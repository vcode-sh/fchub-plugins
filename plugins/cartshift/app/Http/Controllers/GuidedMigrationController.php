<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\Runtime\TransferTopology;
use CartShift\Domain\Transfer\SameSite\GuidedCustomerDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedCollisionDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedDecisionReview;
use CartShift\Domain\Transfer\SameSite\GuidedProductDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedRunCoordinator;
use CartShift\Domain\Transfer\SameSite\GuidedRunPlan;
use CartShift\Domain\Transfer\SameSite\GuidedRollback;
use CartShift\Domain\Transfer\SameSite\GuidedRunState;
use CartShift\Domain\Transfer\SameSite\GuidedRunStateRepository;
use CartShift\Domain\Transfer\SameSite\GuidedRunner;
use CartShift\Domain\Transfer\SameSite\GuidedSetup;
use CartShift\Domain\Transfer\SameSite\PrivateWorkspace;
use CartShift\Domain\Transfer\SameSite\SiteSourceIdentity;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Http\GuidedPreflightPresentation;
use CartShift\Http\GuidedRunProjection;
use CartShift\Validator\PreflightCheck;
use WP_REST_Request;
use WP_REST_Response;

/** REST boundary for zero-write guided status and explicit durable actions. */
final class GuidedMigrationController
{
    private const string NAMESPACE = 'cartshift/v1';
    private const string STATUS_SOURCE_KEY = 'site-readiness-preview';

    private readonly GuidedPreflightPresentation $preflightPresentation;
    private readonly GuidedRunProjection $runProjection;

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
        private readonly ?GuidedProductDecisionBuilder $productDecisions = null,
        private readonly ?GuidedCollisionDecisionBuilder $collisionDecisions = null,
    ) {
        $this->preflightPresentation = new GuidedPreflightPresentation();
        $this->runProjection = new GuidedRunProjection(
            $customerDecisions,
            $rollbackFactory,
            $productDecisions,
            $collisionDecisions,
        );
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

        $preflight = (new PreflightCheck(rememberAdvisoryCounts: false))
            ->run(PreflightCheck::OPERATION_MIGRATION);
        $guidedPreflight = $this->preflightPresentation->evaluate($preflight);
        $subscriptionsActive = ($preflight['checks']['wc_subscriptions']['active'] ?? false) === true;
        $sourceKey = (new SiteSourceIdentity())->current();

        if ($sourceKey === null) {
            $setup = new GuidedSetup(self::STATUS_SOURCE_KEY, $this->operatorId());
            $cutover = $setup->cutover();
            $data = [
                'guided_available' => true,
                'initialised' => false,
                'message' => 'CartShift is ready to check this store.',
                'preflight' => $guidedPreflight,
                'subscriptions' => ['available' => $subscriptionsActive],
                'setup' => [
                    'complete' => false,
                    'cutover' => ['available' => $cutover['available'], 'message' => $cutover['message']],
                ],
            ];

            return new WP_REST_Response(['data' => $data + $this->runProjection->plan(
                self::STATUS_SOURCE_KEY,
                $subscriptionsActive,
                loadRun: false,
            )]);
        }

        $setup = new GuidedSetup($sourceKey, $this->operatorId());
        $cutover = $setup->cutover();

        $data = [
            'guided_available' => true,
            'initialised' => true,
            'preflight' => $guidedPreflight,
            'subscriptions' => [
                'available' => $subscriptionsActive,
            ],
            'setup' => [
                'complete' => $setup->isComplete(),
                'cutover' => ['available' => $cutover['available'], 'message' => $cutover['message']],
            ],
        ];

        return new WP_REST_Response(['data' => $data + $this->runProjection->plan($sourceKey, $subscriptionsActive)]);
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
        if (($this->preflightPresentation->evaluate($preflight)['ready'] ?? false) !== true) {
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
            if ($current instanceof GuidedRunState && $this->runProjection->modeChanged($current, $subscriptions)) {
                $stopped = $repository->transaction(static fn (?GuidedRunState $state): GuidedRunState =>
                    $state instanceof GuidedRunState
                        ? $state->afterFailure('preflight', new \RuntimeException('guided_subscription_mode_changed'))
                        : throw new \RuntimeException('guided_run_missing'));

                return new WP_REST_Response(['data' => $this->runProjection->run($stopped)]);
            }
            $state = $this->coordinator($sourceKey, $subscriptions)->start(
                $request->get_param('renewals_paused') === true,
            );

            return new WP_REST_Response(['data' => $this->runProjection->run($state)]);
        } catch (\Throwable) {
            return $this->actionFailure('The readiness check could not continue. Refresh this page to see its saved state.');
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
            if (($this->preflightPresentation->evaluate($preflight)['ready'] ?? false) !== true) {
                return $this->conflict('The shop changed while you were reviewing. Resolve the new blocker, then review again.');
            }
            $subscriptions = ($preflight['checks']['wc_subscriptions']['active'] ?? false) === true;
            $repository = new GuidedRunStateRepository($workspace, $sourceKey);
            $accepted = [];
            $reviewChanged = false;
            $approvedReviews = is_array($request->get_param('approved_reviews'))
                ? $request->get_param('approved_reviews')
                : [];
            $reviewAnswers = is_array($request->get_param('review_answers'))
                ? $request->get_param('review_answers')
                : [];
            $updated = $repository->transaction(function (?GuidedRunState $state) use (
                $approvedReviews,
                $reviewAnswers,
                $sourceKey,
                $workspace,
                &$accepted,
                &$reviewChanged,
                $subscriptions,
            ): GuidedRunState {
                if (!$state instanceof GuidedRunState || $state->phase !== GuidedRunState::AWAITING_DECISIONS) {
                    throw new \RuntimeException('guided_run_not_awaiting_decisions');
                }
                if ($this->runProjection->modeChanged($state, $subscriptions)) {
                    return $state->afterFailure('preflight', new \RuntimeException('guided_subscription_mode_changed'));
                }
                if (!array_is_list($approvedReviews)
                    || array_filter($approvedReviews, 'is_string') !== $approvedReviews
                    || count(array_unique($approvedReviews)) !== count($approvedReviews)) {
                    throw new \RuntimeException('guided_decision_review_invalid');
                }
                $runner = $this->runner($sourceKey, $state->operator);
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
                    $reviewAnswers,
                );
                $accepted = $runner->acceptProposal($proposal, $workspace . '/decisions.json');
                $accepted['migration_exceptions'] = is_array($proposal['migration_exceptions'] ?? null)
                    ? array_values($proposal['migration_exceptions'])
                    : [];

                return $state->afterDecisionAcceptance($accepted, count($steps));
            });

            return new WP_REST_Response(['data' => $accepted + [
                'review_changed' => $reviewChanged,
                'run' => $this->runProjection->run($updated),
            ]]);
        } catch (\Throwable) {
            return $this->actionFailure('Those decisions could not be recorded. Refresh this page and review the current check.');
        }
    }

    /** Prepare the stable source identity and private workspace after one explicit click. */
    public function initialise(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $sourceKey = GuidedSetup::initialise($this->operatorId());
            $setup = new GuidedSetup($sourceKey, $this->operatorId());

            return new WP_REST_Response(['data' => [
                'initialised' => true,
                'setup_complete' => $setup->isComplete(),
            ]]);
        } catch (\Throwable) {
            return $this->actionFailure('CartShift could not prepare its private workspace. Check the server permissions and try again.');
        }
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

            return new WP_REST_Response(['data' => $this->runProjection->run($state)]);
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

            return new WP_REST_Response(['data' => $this->runProjection->run($after)]);
        } catch (\Throwable) {
            return $this->actionFailure('Rollback could not finish. Refresh this page to resume from its saved state.');
        }
    }

    private function coordinator(string $sourceKey, bool $subscriptions): GuidedRunCoordinator
    {
        $workspace = (new PrivateWorkspace($sourceKey))->path();
        $runner = $this->runner($sourceKey, $this->operatorId());

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
            static function (GuidedRunState $state) use ($workspace): bool {
                if (!$state->includesSubscriptions || $state->evidence->descriptor === null) {
                    return false;
                }
                try {
                    $evidence = (new SubscriptionCutoverEvidenceRepository($workspace))
                        ->get($state->evidence->descriptor);

                    return $evidence->releaseStarted();
                } catch (\Throwable) {
                    return false;
                }
            },
        );
    }

    private function runner(string $sourceKey, string $operator): GuidedRunner
    {
        return new GuidedRunner(
            new GuidedSetup($sourceKey, $operator),
            productDecisions: $this->productDecisions,
            customerDecisions: $this->customerDecisions,
            collisionDecisions: $this->collisionDecisions,
        );
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
        return new GuidedDecisionReview(
            $this->customerDecisionBuilder(),
            $this->productDecisions ?? new GuidedProductDecisionBuilder(),
            $this->collisionDecisions ?? new GuidedCollisionDecisionBuilder(),
        );
    }

    private function conflict(string $message): WP_REST_Response
    {
        return new WP_REST_Response(['data' => ['message' => $message]], 409);
    }

    private function actionFailure(string $message): WP_REST_Response
    {
        return new WP_REST_Response(['data' => ['message' => $message]], 422);
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
