<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\Runtime\TransferTopology;
use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\TransferJournalRepository;
use CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway;
use CartShift\Domain\Transfer\Product\ParentStockExceptionReport;
use CartShift\Domain\Transfer\SameSite\GuidedEvidence;
use CartShift\Domain\Transfer\SameSite\GuidedCustomerDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedDecisionReview;
use CartShift\Domain\Transfer\SameSite\GuidedRunCoordinator;
use CartShift\Domain\Transfer\SameSite\GuidedRunPlan;
use CartShift\Domain\Transfer\SameSite\GuidedRollback;
use CartShift\Domain\Transfer\SameSite\GuidedRunState;
use CartShift\Domain\Transfer\SameSite\GuidedRunStateRepository;
use CartShift\Domain\Transfer\SameSite\GuidedRunner;
use CartShift\Domain\Transfer\SameSite\GuidedSetup;
use CartShift\Domain\Transfer\SameSite\PrivateWorkspace;
use CartShift\Domain\Transfer\SameSite\SiteSourceIdentity;
use CartShift\Validator\PreflightCheck;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Everything the guided screen renders, from one read.
 *
 * Status is a zero-write projection; POST actions advance durable adapter state.
 *
 * Full audit remains a durable run step; a status poll never performs it.
 */
final class GuidedMigrationController
{
    private const string NAMESPACE = 'cartshift/v1';

    /**
     * The probe arrives through a seam for the same reason every other symbol
     * reader in this plugin does: `class_exists()` cannot be told a class is
     * absent, so a shared-process suite cannot otherwise reach both topologies.
     * Controllers are constructed as `new $class($container)`, so the default is
     * what production gets.
     */
    public function __construct(
        private readonly Container $container,
        private readonly ?TransferRuntimeProbe $probe = null,
        private readonly ?\Closure $runStep = null,
        private readonly ?GuidedCustomerDecisionBuilder $customerDecisions = null,
        private readonly ?\Closure $rollbackFactory = null,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/migration/status', [
            'methods' => 'GET',
            'callback' => [$this, 'status'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migration/start', [
            'methods' => 'POST',
            'callback' => [$this, 'start'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migration/setup-lines', [
            'methods' => 'POST',
            'callback' => [$this, 'setupLines'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migration/decisions', [
            'methods' => 'POST',
            'callback' => [$this, 'acceptDecisions'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migration/initialise', [
            'methods' => 'POST',
            'callback' => [$this, 'initialise'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migration/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'cancel'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migration/rollback', [
            'methods' => 'POST',
            'callback' => [$this, 'rollback'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        $topology = ($this->probe ?? new TransferRuntimeProbe())->topology();

        if ($topology !== TransferTopology::SameSite) {
            return new WP_REST_Response(['data' => [
                'guided_available' => false,
                'message' => 'This guided migration needs WooCommerce and FluentCart on the same WordPress site.',
            ]]);
        }

        $sourceKey = (new SiteSourceIdentity())->current();

        if ($sourceKey === null) {
            // NAMING THE SITE IS A WRITE, AND THIS IS A READ.
            // `ensure()` mints a key and stores it, which is a legitimate
            // one-time configuration write and emphatically not something a
            // status poll should do — the zero-write guard caught exactly that
            // and was right to. So the screen is told the site has no name yet
            // and offers the one action that gives it one.
            return new WP_REST_Response(['data' => [
                'guided_available' => true,
                'initialised' => false,
                'message' => 'This site has not been named for transfer yet. Naming writes only its transfer identity; '
                    . 'simply opening this screen writes nothing.',
            ]]);
        }

        $setup = new GuidedSetup($sourceKey, $this->operatorId());
        $preflight = (new PreflightCheck(rememberAdvisoryCounts: false))
            ->run(PreflightCheck::OPERATION_MIGRATION);
        $guidedPreflight = $this->guidedPreflightPayload($preflight);
        $subscriptionsActive = ($preflight['checks']['wc_subscriptions']['active'] ?? false) === true;

        $data = [
            'guided_available' => true,
            'initialised' => true,
            'preflight' => $this->guidedPreflightPresentation($guidedPreflight),
            'subscriptions' => [
                'available' => $subscriptionsActive,
            ],
            'setup' => [
                'complete' => $setup->isComplete(),
                'missing' => $setup->requirements(),
                'can_copy_lines' => !$setup->isComplete(),
                'cutover' => ['available' => false, 'message' => $setup->cutover()['message']],
            ],
        ];

        return new WP_REST_Response(['data' => $data + $this->plan($sourceKey, $subscriptionsActive)]);
    }

    /** Run until the adapter reaches owner review, completion, or a persisted failure. */
    public function start(WP_REST_Request $request): WP_REST_Response
    {
        $topology = ($this->probe ?? new TransferRuntimeProbe())->topology();
        if ($topology !== TransferTopology::SameSite) {
            return $this->conflict('Guided migration is available only when WooCommerce and FluentCart share this WordPress.');
        }
        $sourceKey = (new SiteSourceIdentity())->current();
        if ($sourceKey === null) {
            return $this->conflict('Name this site before starting the readiness check.');
        }
        $preflight = (new PreflightCheck(rememberAdvisoryCounts: false))
            ->run(PreflightCheck::OPERATION_MIGRATION);
        if (($this->guidedPreflightPayload($preflight)['ready'] ?? false) !== true) {
            $this->stopActiveRunForChangedPreflight($sourceKey);
            return $this->conflict('Resolve the blocking shop checks before starting.');
        }
        $subscriptions = ($preflight['checks']['wc_subscriptions']['active'] ?? false) === true;
        $setup = new GuidedSetup($sourceKey, $this->operatorId());
        if (!$setup->isComplete()) {
            return $this->conflict('Complete the one-time transfer setup first.');
        }

        try {
            $workspace = (new PrivateWorkspace($sourceKey))->path();
            $repository = new GuidedRunStateRepository($workspace, $sourceKey);
            $current = $repository->get();
            if ($current instanceof GuidedRunState && $this->runModeChanged($current, $subscriptions)) {
                $stopped = $repository->transaction(static fn (?GuidedRunState $state): GuidedRunState =>
                    $state instanceof GuidedRunState
                        ? $state->afterFailure('preflight', new \RuntimeException('guided_subscription_mode_changed'))
                        : throw new \RuntimeException('guided_run_missing'));

                return new WP_REST_Response(['data' => $this->runPayload($stopped)]);
            }
            $state = $this->coordinator($sourceKey, $subscriptions)->start();

            return new WP_REST_Response(['data' => $this->runPayload($state)]);
        } catch (\Throwable) {
            return $this->actionFailure('The readiness check could not continue. Refresh this page to see its saved state.');
        }
    }

    /** Create the private directory only after the owner asks for pasteable setup lines. */
    public function setupLines(WP_REST_Request $request): WP_REST_Response
    {
        $sourceKey = (new SiteSourceIdentity())->current();
        if ($sourceKey === null) {
            return $this->conflict('Name this site before copying setup lines.');
        }

        try {
            return new WP_REST_Response(['data' => [
                'lines' => (new GuidedSetup($sourceKey, $this->operatorId()))->wpConfigSnippet(),
            ]]);
        } catch (\Throwable) {
            return $this->actionFailure('The setup lines could not be prepared.');
        }
    }

    /**
     * The review step: the owner accepts what CartShift proposed.
     *
     * This is the only decision in the guided route a machine must not take
     * alone. The audit produced two on this shop — product mapping and
     * order-note visibility — and neither is answerable from the data: whether
     * a note was visible to a customer is a fact about intent, and publishing
     * an internal note to somebody's order history is a different kind of wrong
     * from losing it.
     *
     * So the proposal is refreshed under the run lock and written only when
     * the owner approves the exact current review. Declared `private_files` in
     * `LegacyCommandPolicy`, because that is what it writes.
     */
    public function acceptDecisions(WP_REST_Request $request): WP_REST_Response
    {
        $sourceKey = (new SiteSourceIdentity())->current();

        if ($sourceKey === null) {
            return new WP_REST_Response(
                ['data' => ['message' => 'This site has not been named for transfer yet.']],
                409,
            );
        }

        try {
            $workspace = (new PrivateWorkspace($sourceKey))->path();
            $preflight = (new PreflightCheck(rememberAdvisoryCounts: false))
                ->run(PreflightCheck::OPERATION_MIGRATION);
            if (($this->guidedPreflightPayload($preflight)['ready'] ?? false) !== true) {
                return $this->conflict('The shop changed while you were reviewing. Resolve the new blocker, then review again.');
            }
            $subscriptions = ($preflight['checks']['wc_subscriptions']['active'] ?? false) === true;
            $repository = new GuidedRunStateRepository($workspace, $sourceKey);
            $accepted = [];
            $reviewChanged = false;
            $approvedReviews = is_array($request->get_param('approved_reviews'))
                ? $request->get_param('approved_reviews')
                : [];
            $updated = $repository->transaction(function (?GuidedRunState $state) use (
                $approvedReviews,
                $sourceKey,
                $workspace,
                &$accepted,
                &$reviewChanged,
                $subscriptions,
            ): GuidedRunState {
                if (!$state instanceof GuidedRunState || $state->phase !== GuidedRunState::AWAITING_DECISIONS) {
                    throw new \RuntimeException('guided_run_not_awaiting_decisions');
                }
                if ($this->runModeChanged($state, $subscriptions)) {
                    return $state->afterFailure('preflight', new \RuntimeException('guided_subscription_mode_changed'));
                }
                if (!array_is_list($approvedReviews)
                    || array_filter($approvedReviews, 'is_string') !== $approvedReviews
                    || count(array_unique($approvedReviews)) !== count($approvedReviews)) {
                    throw new \RuntimeException('guided_decision_review_invalid');
                }
                $runner = new GuidedRunner(new GuidedSetup($sourceKey, $state->operator));
                $plan = GuidedRunPlan::rehearsal(
                    $state->sourceKey,
                    $workspace,
                    $state->operator,
                    $state->decidedAtUtc,
                    $state->evidence,
                    $state->includesSubscriptions,
                );
                $steps = $plan->steps();
                $step = $steps[$state->nextStep] ?? null;
                if (!$step instanceof \CartShift\Domain\Transfer\SameSite\GuidedStep
                    || $step->verb !== 'propose-decisions') {
                    throw new \RuntimeException('guided_decision_step_missing');
                }
                $proposal = ($this->runStep ?? $runner->run(...))($step);
                $currentReview = $this->decisionReview()->presentation($proposal);
                $currentIds = array_column($currentReview['items'], 'review_id');
                sort($currentIds, SORT_STRING);
                $approvedIds = $approvedReviews;
                sort($approvedIds, SORT_STRING);
                if ($currentReview['blockers'] !== [] || $currentIds !== $approvedIds) {
                    $reviewChanged = true;
                    return $state->afterDecisionRefresh($proposal, count($steps));
                }
                $proposal = $this->decisionReview()->approve(
                    $proposal,
                    $approvedReviews,
                    $this->operatorId(),
                    gmdate('Y-m-d\TH:i:s\Z'),
                );
                $accepted = $runner->acceptProposal($proposal, $workspace . '/decisions.json');

                return $state->afterDecisionAcceptance($accepted, count($steps));
            });

            return new WP_REST_Response(['data' => $accepted + [
                'review_changed' => $reviewChanged,
                'run' => $this->runPayload($updated),
            ]]);
        } catch (\Throwable) {
            return $this->actionFailure('Those decisions could not be recorded. Refresh this page and review the current check.');
        }
    }

    /**
     * Name this site, once.
     *
     * The only write in the guided surface before the owner has reviewed
     * anything, and it is declared as `configuration_option` in
     * `LegacyCommandPolicy` rather than smuggled in behind a read.
     */
    public function initialise(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(['data' => [
            'initialised' => (new SiteSourceIdentity())->ensure() !== '',
        ]]);
    }

    /** Stop adapter progress. Target rows already written remain rollback evidence, never silently disappear. */
    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        $sourceKey = (new SiteSourceIdentity())->current();
        if ($sourceKey === null) {
            return $this->conflict('There is no readiness check to cancel.');
        }
        try {
            $state = $this->coordinator($sourceKey, false)->cancel();

            return new WP_REST_Response(['data' => $this->runPayload($state)]);
        } catch (\Throwable) {
            return $this->actionFailure('The readiness check could not be cancelled. Refresh this page to see its saved state.');
        }
    }

    /** Recompute and execute the exact receipt-owned rollback the owner previewed. */
    public function rollback(WP_REST_Request $request): WP_REST_Response
    {
        $sourceKey = (new SiteSourceIdentity())->current();
        if ($sourceKey === null) {
            return $this->conflict('There is no failed run to roll back.');
        }
        try {
            $workspace = (new PrivateWorkspace($sourceKey))->path();
            $repository = new GuidedRunStateRepository($workspace, $sourceKey);
            $before = $repository->get();
            if (!$before instanceof GuidedRunState) {
                throw new \RuntimeException('guided_run_missing');
            }
            if ($before->phase === GuidedRunState::FAILED) {
                $rollback = $this->rollbackFor($workspace, $before);
                $preview = $rollback->preview();
                $reviewId = 'rollback-' . substr((string) $preview['confirm'], 0, 12);
                if (!hash_equals($reviewId, (string) $request->get_param('review_id'))) {
                    throw new \RuntimeException('guided_rollback_review_changed');
                }
                $sealed = $rollback->seal((string) $preview['confirm']);
                $rolling = $repository->transaction(static function (?GuidedRunState $current) use (
                    $before,
                    $sealed,
                ): GuidedRunState {
                    if (!$current instanceof GuidedRunState || $current->toArray() != $before->toArray()) {
                        throw new \RuntimeException('guided_run_state_changed');
                    }

                    return $current->beginRollback($sealed);
                });
            } elseif ($before->phase === GuidedRunState::ROLLING_BACK) {
                $sealed = $before->lastResult;
                $reviewId = 'rollback-' . substr((string) ($sealed['rollback_plan_fingerprint'] ?? ''), 0, 12);
                $rolling = $before;
            } else {
                throw new \RuntimeException('guided_rollback_unavailable');
            }
            if (!hash_equals($reviewId, (string) $request->get_param('review_id'))) {
                throw new \RuntimeException('guided_rollback_review_changed');
            }
            $result = $this->rollbackFor($workspace, $rolling)->executeSealed($sealed);
            $after = $repository->transaction(static function (?GuidedRunState $current) use ($rolling, $result): GuidedRunState {
                if (!$current instanceof GuidedRunState || $current->toArray() != $rolling->toArray()) {
                    throw new \RuntimeException('guided_run_state_changed');
                }

                return $current->afterRollback($result);
            });

            return new WP_REST_Response(['data' => $this->runPayload($after)]);
        } catch (\Throwable) {
            return $this->actionFailure('Rollback could not finish. Refresh this page to resume from its saved state.');
        }
    }

    /**
     * The friendly sequence and any durable run already in progress.
     *
     * A shop with WooCommerce Subscriptions is refused by `GuidedRunPlan` rather
     * than given an invented ordering, so the refusal is reported as the plan's
     * state instead of thrown at the screen as a fatal.
     *
     * @return array<string, mixed>
     */
    private function plan(string $sourceKey, bool $subscriptionsActive): array
    {
        $run = null;
        try {
            $setup = new GuidedSetup($sourceKey, $this->operatorId());
            if ($setup->isComplete()) {
                $workspace = (new PrivateWorkspace($sourceKey))->path();
                $run = (new GuidedRunStateRepository($workspace, $sourceKey))->get();
            } else {
                $workspace = '/cartshift-private';
                $run = null;
            }
            $activeRun = $run instanceof GuidedRunState && in_array(
                $run->phase,
                [GuidedRunState::READY, GuidedRunState::RUNNING, GuidedRunState::AWAITING_DECISIONS],
                true,
            );
            $plan = GuidedRunPlan::rehearsal(
                sourceKey: $sourceKey,
                workspace: $workspace,
                operator: $run?->operator ?? $this->operatorId(),
                decidedAtUtc: $run?->decidedAtUtc ?? gmdate('Y-m-d\TH:i:s\Z'),
                evidence: $run?->evidence ?? GuidedEvidence::none(),
                includesSubscriptions: $activeRun ? $run->includesSubscriptions : $subscriptionsActive,
            );

            $steps = array_map(
                fn (object $step, int $index): array => [
                    'label' => $this->stepLabel($step->verb),
                    'completed' => $run instanceof GuidedRunState && $index < $run->nextStep,
                ],
                $plan->steps(),
                array_keys($plan->steps()),
            );
        } catch (\Throwable $refusal) {
            $runPayload = $run instanceof GuidedRunState ? $this->runPayload($run) : null;
            if ($runPayload !== null && $this->runModeChanged($run, $subscriptionsActive)) {
                $runPayload['mode_changed'] = 'Subscription availability changed after this check started. '
                    . ($run->phase === GuidedRunState::AWAITING_DECISIONS
                        ? 'Cancel this check before approving anything.'
                        : 'Stop this outdated check before starting again.');
            }

            return [
                'plan' => [],
                'plan_blocked' => true,
                'plan_message' => str_contains($refusal->getMessage(), 'guided_subscription_sequence_unplanned')
                    ? 'Active WooCommerce subscriptions need a transfer sequence that the guided route does not support yet.'
                    : 'The guided migration plan is not available for this shop yet.',
                'run' => $runPayload,
            ];
        }

        $runPayload = $run instanceof GuidedRunState ? $this->runPayload($run) : null;
        if ($runPayload !== null && $this->runModeChanged($run, $subscriptionsActive)) {
            $runPayload['mode_changed'] = 'Subscription availability changed after this check started. '
                . ($run->phase === GuidedRunState::AWAITING_DECISIONS
                    ? 'Cancel this check before approving anything.'
                    : 'Stop this outdated check before starting again.');
        }

        return [
            'plan' => $steps,
            'plan_blocked' => null,
            'plan_message' => null,
            'run' => $runPayload,
        ];
    }

    private function runModeChanged(GuidedRunState $state, bool $subscriptionsActive): bool
    {
        return in_array($state->phase, [GuidedRunState::READY, GuidedRunState::RUNNING, GuidedRunState::AWAITING_DECISIONS], true)
            && $state->includesSubscriptions !== $subscriptionsActive;
    }

    private function coordinator(string $sourceKey, bool $subscriptions): GuidedRunCoordinator
    {
        $workspace = (new PrivateWorkspace($sourceKey))->path();
        $runner = new GuidedRunner(new GuidedSetup($sourceKey, $this->operatorId()));

        return new GuidedRunCoordinator(
            new GuidedRunStateRepository($workspace, $sourceKey),
            static fn (GuidedRunState $state): GuidedRunPlan => GuidedRunPlan::rehearsal(
                $state->sourceKey,
                $workspace,
                $state->operator,
                $state->decidedAtUtc,
                $state->evidence,
                $state->includesSubscriptions,
            ),
            $this->runStep ?? $runner->run(...),
            fn (): GuidedRunState => GuidedRunState::start(
                $sourceKey,
                $this->operatorId(),
                gmdate('Y-m-d\TH:i:s\Z'),
                $subscriptions,
            ),
        );
    }

    /** @return array<string, mixed> */
    private function runPayload(GuidedRunState $state): array
    {
        $review = null;
        if ($state->phase === GuidedRunState::AWAITING_DECISIONS) {
            try {
                $review = $this->decisionReview()->presentation($state->lastResult);
            } catch (\Throwable) {
                $review = [
                    'items' => [],
                    'blockers' => [
                        'This review was paused by an older CartShift version. Cancel this check and start it again.',
                    ],
                ];
            }
        }

        $legacyCompletion = $state->phase === GuidedRunState::COMPLETED;

        return [
            'phase' => $legacyCompletion ? 'unsafe_completion' : $state->phase,
            'completed_steps' => $state->nextStep,
            'total_steps' => 12,
            'last_step' => $state->lastVerb === null ? null : $this->stepLabel($state->lastVerb),
            'failure' => $legacyCompletion ? [
                'message' => 'This older rehearsal completed without rollback proof. Cutover remains unavailable.',
                'can_restart' => false,
            ] : $this->failurePayload($state),
            'review' => $review,
            'migration_exceptions' => $this->migrationExceptionPayload($this->migrationExceptionsFor($state)),
            'rollback' => $this->rollbackPreview($state),
        ];
    }

    /** @return array<string, mixed>|null */
    private function rollbackPreview(GuidedRunState $state): ?array
    {
        if ($state->phase === GuidedRunState::ROLLING_BACK) {
            return [
                'safe' => true,
                'deletion_count' => (int) ($state->lastResult['deletion_count'] ?? 0),
                'review_id' => 'rollback-' . substr(
                    (string) ($state->lastResult['rollback_plan_fingerprint'] ?? ''),
                    0,
                    12,
                ),
            ];
        }
        if ($state->phase !== GuidedRunState::FAILED || $state->evidence->descriptor === null) {
            return null;
        }
        try {
            $preview = $this->rollbackFor(
                (new PrivateWorkspace($state->sourceKey))->path(),
                $state,
            )->preview();

            return [
                'safe' => $preview['safe'],
                'deletion_count' => $preview['deletion_count'],
                'review_id' => $preview['safe']
                    ? 'rollback-' . substr((string) $preview['confirm'], 0, 12)
                    : null,
            ];
        } catch (\Throwable $failure) {
            return [
                'safe' => false,
                'deletion_count' => 0,
                'review_id' => null,
            ];
        }
    }

    /** @return array{message:string,can_restart:bool}|null */
    private function failurePayload(GuidedRunState $state): ?array
    {
        if ($state->failure === null) {
            return null;
        }
        $message = str_contains($state->failure, 'guided_subscription_mode_changed')
            ? 'Subscription availability changed after this check started. '
                . 'Start a new check when the shop setup is stable.'
            : (str_contains($state->failure, 'guided_dependency_bound_target_readiness_unavailable')
                ? 'Orders and subscriptions depend on target links that CartShift cannot prove before preparing records. '
                    . 'The guided check stopped before writing target records.'
            : (str_contains($state->failure, 'guided_completed_rehearsal_rollback_unavailable')
                ? 'This CartShift core cannot yet roll back a completed rehearsal, so the guided run stopped '
                    . 'before preparing any target records.'
            : ($state->evidence->descriptor === null
                ? 'The rehearsal stopped before any target records were prepared. You can safely try again.'
                : 'The rehearsal stopped after target preparation. Roll back this run before starting another one.')));

        return [
            'message' => $message,
            'can_restart' => $state->canRestart(),
        ];
    }

    private function stepLabel(string $verb): string
    {
        return match ($verb) {
            'compatibility' => 'Check compatibility',
            'audit' => 'Inspect source records',
            'propose-decisions' => 'Review migration decisions',
            'export' => 'Create the private rehearsal package',
            'validate-package' => 'Validate the rehearsal package',
            'prepare' => 'Prepare target records',
            'stage' => 'Stage target records',
            'reconcile' => 'Verify staged records',
            'promote' => 'Promote staged records',
            'activate-catalogue' => 'Activate the FluentCart catalogue',
            'complete' => 'Finish the rehearsal',
            'rollback' => 'Roll back the failed rehearsal',
            default => 'Advance the rehearsal',
        };
    }

    private function rollbackFor(string $workspace, GuidedRunState $state): GuidedRollback
    {
        if ($this->rollbackFactory !== null) {
            $rollback = ($this->rollbackFactory)($state);
            if (!$rollback instanceof GuidedRollback) {
                throw new \RuntimeException('guided_rollback_factory_invalid');
            }

            return $rollback;
        }

        return new GuidedRollback($workspace, $state);
    }

    private function customerDecisionBuilder(): GuidedCustomerDecisionBuilder
    {
        return $this->customerDecisions ?? new GuidedCustomerDecisionBuilder();
    }

    private function decisionReview(): GuidedDecisionReview
    {
        return new GuidedDecisionReview($this->customerDecisionBuilder());
    }

    private function conflict(string $message): WP_REST_Response
    {
        return new WP_REST_Response(['data' => ['message' => $message]], 409);
    }

    private function actionFailure(string $message): WP_REST_Response
    {
        return new WP_REST_Response(['data' => ['message' => $message]], 422);
    }

    /** @param array<string,mixed> $preflight @return array<string,mixed> */
    private function preflightPayload(array $preflight): array
    {
        $checks = [];
        foreach (is_array($preflight['checks'] ?? null) ? $preflight['checks'] : [] as $key => $check) {
            if (!is_array($check)) {
                continue;
            }
            $checks[$key] = [
                'label' => $this->guidedCheckLabel((string) $key),
                'severity' => (string) ($check['severity'] ?? PreflightCheck::SEVERITY_FAIL),
                'message' => $this->guidedCheckMessage((string) $key, $check),
            ];
        }

        return ['ready' => ($preflight['ready'] ?? false) === true, 'checks' => $checks];
    }

    /** Standalone coupons are supported by the legacy migrator, but not by the v2 package yet. */
    private function guidedPreflightPayload(array $preflight): array
    {
        $payload = $this->preflightPayload($preflight);
        if (($payload['checks']['fc_data']['severity'] ?? null) === PreflightCheck::SEVERITY_WARN) {
            $payload['checks']['fc_data']['severity'] = PreflightCheck::SEVERITY_FAIL;
            $payload['ready'] = false;
        }
        $coupons = $this->standaloneCouponCount();
        $payload['checks']['standalone_coupons'] = [
            'label' => 'Standalone coupons',
            'severity' => $coupons > 0 ? PreflightCheck::SEVERITY_WARN : PreflightCheck::SEVERITY_PASS,
            'message' => $coupons > 0
                ? sprintf(
                    '%d standalone WooCommerce coupon%s will not be migrated yet. Applied coupon history stays on migrated orders.',
                    $coupons,
                    $coupons === 1 ? '' : 's',
                )
                : 'No standalone WooCommerce coupons need migration.',
        ];

        return $payload;
    }

    /** @param list<array<string,mixed>> $exceptions @return list<array<string,mixed>> */
    private function migrationExceptionPayload(array $exceptions): array
    {
        $groups = [];
        foreach ($exceptions as $exception) {
            if (!is_array($exception) || ($exception['kind'] ?? null) !== 'shared_parent_stock') {
                continue;
            }
            $title = trim((string) ($exception['product_name'] ?? 'Product'));
            $key = hash('sha256', $title . "\0" . (string) ($exception['source_owner'] ?? ''));
            $quantity = is_int($exception['source_quantity'] ?? null) ? $exception['source_quantity'] : null;
            $quantityState = $quantity === null ? 'unknown' : ($quantity < 0 ? 'below_zero' : 'known');
            $groups[$key] ??= [
                'title' => $title,
                'variations' => [],
                'source_quantity' => $quantity,
                'source_quantity_state' => $quantityState,
                'message' => 'WooCommerce shares one stock total across this product, while FluentCart stores stock per variation. '
                    . 'CartShift will migrate the affected variations unavailable with zero stock and backorders disabled to prevent overselling. '
                    . ($quantityState === 'known'
                        ? 'The original quantity below is one product-wide total, not an amount to copy into every variation.'
                        : 'WooCommerce did not provide a usable stock total, so keep every affected variation unavailable until stock is counted.'),
                'suggestions' => $quantityState === 'known' ? [
                    'Allocate stock across the FluentCart variations without exceeding the original shared total.',
                    'Enable only the variations you want to sell.',
                    'Leave the variations unavailable until stock is confirmed.',
                ] : [
                    'Count the available stock before entering quantities in FluentCart.',
                    'Enable only variations whose stock you have confirmed.',
                    'Leave all affected variations unavailable until the count is complete.',
                ],
                '_target_verification' => [],
            ];
            $verified = $exception['target_verified'] ?? null;
            $groups[$key]['_target_verification'][] = is_bool($verified) ? $verified : null;
            $groups[$key]['variations'][] = [
                'title' => trim((string) ($exception['variation_name'] ?? 'Variation')),
                'sku' => trim((string) ($exception['sku'] ?? '')),
            ];
        }

        foreach ($groups as &$group) {
            $verification = $group['_target_verification'];
            unset($group['_target_verification']);
            $group['target_state'] = in_array(false, $verification, true)
                ? 'needs_review'
                : (in_array(null, $verification, true) ? 'planned' : 'confirmed');
        }
        unset($group);

        return array_values($groups);
    }

    /** @return list<array<string,mixed>> */
    private function migrationExceptionsFor(GuidedRunState $state): array
    {
        if ($state->phase === GuidedRunState::ROLLED_BACK || $state->migrationExceptions === []) {
            return [];
        }
        if ($state->evidence->descriptor === null) {
            return $state->migrationExceptions;
        }
        try {
            $workspace = (new PrivateWorkspace($state->sourceKey))->path();
            $descriptors = new PreparedTransferRepository($workspace);
            $receipts = (new TransferJournalRepository($descriptors))->receipts($state->evidence->descriptor);

            return (new ParentStockExceptionReport(new LoadedFluentCartProductGateway()))
                ->confirm($receipts, $state->migrationExceptions);
        } catch (\Throwable) {
            return array_map(
                static fn (array $item): array => [...$item, 'target_verified' => false],
                $state->migrationExceptions,
            );
        }
    }

    private function stopActiveRunForChangedPreflight(string $sourceKey): void
    {
        try {
            $workspace = (new PrivateWorkspace($sourceKey))->path();
            $repository = new GuidedRunStateRepository($workspace, $sourceKey);
            $repository->transaction(static function (?GuidedRunState $state): GuidedRunState {
                if (!$state instanceof GuidedRunState) {
                    throw new \RuntimeException('guided_run_missing');
                }
                if ($state->phase !== GuidedRunState::RUNNING) {
                    return $state;
                }

                return $state->afterFailure('preflight', new \RuntimeException('guided_preflight_changed'));
            });
        } catch (\Throwable) {
            // The caller still returns the current plain preflight blocker.
        }
    }

    /** Remove internal check identifiers from the REST presentation. */
    private function guidedPreflightPresentation(array $payload): array
    {
        return [
            'ready' => ($payload['ready'] ?? false) === true,
            'checks' => array_values(is_array($payload['checks'] ?? null) ? $payload['checks'] : []),
        ];
    }

    private function guidedCheckLabel(string $key): string
    {
        return match ($key) {
            'woocommerce' => 'WooCommerce',
            'fluentcart' => 'FluentCart',
            'order_storage' => 'Order storage',
            'wc_subscriptions' => 'WooCommerce Subscriptions',
            'php_memory' => 'Server memory',
            'max_execution_time' => 'Server processing time',
            'product_types' => 'Product types',
            'fc_data' => 'Existing FluentCart records',
            'migration_tables' => 'CartShift storage',
            'entitlements' => 'Access granted by other plugins',
            default => 'Shop check',
        };
    }

    /** @param array<string,mixed> $check */
    private function guidedCheckMessage(string $key, array $check): string
    {
        $severity = (string) ($check['severity'] ?? PreflightCheck::SEVERITY_FAIL);
        $attention = $severity !== PreflightCheck::SEVERITY_PASS;

        return match ($key) {
            'woocommerce' => $attention ? 'Activate WooCommerce before continuing.' : 'WooCommerce is ready.',
            'fluentcart' => $attention ? 'Activate FluentCart before continuing.' : 'FluentCart is ready.',
            'order_storage' => $attention
                ? 'WooCommerce order storage is not ready for guided migration.'
                : 'WooCommerce order storage is ready.',
            'wc_subscriptions' => ($check['active'] ?? false) === true
                ? 'Subscriptions will be included in the readiness check.'
                : 'Subscriptions are not active and will be skipped.',
            'php_memory' => $attention
                ? 'The server needs more memory before this shop can migrate safely.'
                : 'Server memory is ready.',
            'max_execution_time' => $attention
                ? 'The server needs more processing time before this shop can migrate safely.'
                : 'Server processing time is ready.',
            'product_types' => $this->guidedProductTypeMessage($check),
            'fc_data' => $attention
                ? 'FluentCart already contains records, so continuing could create duplicates. '
                    . 'If they are test records, remove them in FluentCart and reload this screen. '
                    . 'If they must be kept, stop here; CartShift will not overwrite them.'
                : 'FluentCart has no existing records that could conflict with this migration.',
            'migration_tables' => $attention
                ? 'CartShift storage is not ready. Update or reactivate CartShift, then reload this screen.'
                : 'CartShift storage is ready.',
            'entitlements' => $attention
                ? 'Another plugin may grant access from WooCommerce purchases. That access is not migrated by CartShift.'
                : 'No known access-granting plugin needs separate migration.',
            default => $attention ? 'This shop check needs attention before migration.' : 'This shop check is ready.',
        };
    }

    /** @param array<string,mixed> $check */
    private function guidedProductTypeMessage(array $check): string
    {
        $unsupported = $check['unsupported_product_types'] ?? [];
        $types = is_array($unsupported) && is_array($unsupported['types'] ?? null)
            ? $unsupported['types']
            : [];
        $products = array_sum(array_map('intval', $types));
        if ($products === 0) {
            return 'Every detected product type is supported by the guided migration.';
        }
        $orders = max(0, (int) ($unsupported['orders_affected'] ?? 0));

        return sprintf(
            '%d product%s use unsupported types and appear in %d order%s. The orders keep their purchase details, but those items cannot link to migrated products.',
            $products,
            $products === 1 ? '' : 's',
            $orders,
            $orders === 1 ? '' : 's',
        );
    }

    private function standaloneCouponCount(): int
    {
        $counts = wp_count_posts('shop_coupon');
        $total = 0;
        foreach (['publish', 'draft', 'pending', 'private', 'future'] as $status) {
            $total += max(0, (int) ($counts->{$status} ?? 0));
        }

        return $total;
    }

    /**
     * Who this run belongs to.
     *
     * Matches `CARTSHIFT_TRANSFER_OPERATOR_ID`'s alphabet so the suggestion the
     * setup screen shows is one the validator accepts.
     */
    private function operatorId(): string
    {
        return 'wp-user:' . max(1, get_current_user_id());
    }
}
