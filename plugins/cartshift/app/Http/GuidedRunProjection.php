<?php

declare(strict_types=1);

namespace CartShift\Http;

use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\TransferJournalRepository;
use CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway;
use CartShift\Domain\Transfer\Product\ParentStockExceptionReport;
use CartShift\Domain\Transfer\SameSite\GuidedCustomerDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedCollisionDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedDecisionReview;
use CartShift\Domain\Transfer\SameSite\GuidedEvidence;
use CartShift\Domain\Transfer\SameSite\GuidedProductDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedRollback;
use CartShift\Domain\Transfer\SameSite\GuidedRunPlan;
use CartShift\Domain\Transfer\SameSite\GuidedRunState;
use CartShift\Domain\Transfer\SameSite\GuidedRunStateRepository;
use CartShift\Domain\Transfer\SameSite\GuidedSetup;
use CartShift\Domain\Transfer\SameSite\PrivateWorkspace;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;

defined('ABSPATH') || exit;

/** Projects durable guided plans and runs into the exact owner-facing REST shape. */
final class GuidedRunProjection
{
    /** @var null|\Closure(GuidedRunState): GuidedRollback */
    private readonly ?\Closure $rollbackFactory;

    /** @param null|callable(GuidedRunState): GuidedRollback $rollbackFactory */
    public function __construct(
        private readonly ?GuidedCustomerDecisionBuilder $customerDecisions = null,
        ?callable $rollbackFactory = null,
        private readonly ?GuidedProductDecisionBuilder $productDecisions = null,
        private readonly ?GuidedCollisionDecisionBuilder $collisionDecisions = null,
    ) {
        $this->rollbackFactory = $rollbackFactory === null ? null : $rollbackFactory(...);
    }

    /** @return array<string, mixed> */
    public function plan(string $sourceKey, bool $subscriptionsActive, bool $loadRun = true): array
    {
        $run = null;
        try {
            $setup = new GuidedSetup($sourceKey, $this->operatorId());
            if ($loadRun && $setup->isComplete()) {
                $workspace = (new PrivateWorkspace($sourceKey))->path();
                $run = (new GuidedRunStateRepository($workspace, $sourceKey))->get();
            } else {
                $workspace = '/cartshift-private';
                $run = null;
            }
            $activeRun = $run instanceof GuidedRunState && in_array(
                $run->phase,
                [
                    GuidedRunState::READY,
                    GuidedRunState::RUNNING,
                    GuidedRunState::AWAITING_DECISIONS,
                    GuidedRunState::AWAITING_RENEWAL_PAUSE,
                ],
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
            $runPayload = $run instanceof GuidedRunState ? $this->run($run) : null;
            if ($runPayload !== null && $this->modeChanged($run, $subscriptionsActive)) {
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

        $runPayload = $run instanceof GuidedRunState ? $this->run($run) : null;
        if ($runPayload !== null && $this->modeChanged($run, $subscriptionsActive)) {
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

    public function modeChanged(GuidedRunState $state, bool $subscriptionsActive): bool
    {
        return in_array($state->phase, [
            GuidedRunState::READY,
            GuidedRunState::RUNNING,
            GuidedRunState::AWAITING_DECISIONS,
            GuidedRunState::AWAITING_RENEWAL_PAUSE,
        ], true)
            && $state->includesSubscriptions !== $subscriptionsActive;
    }

    /** @return array<string, mixed> */
    public function run(GuidedRunState $state): array
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

        return [
            'phase' => $state->phase,
            'completed_steps' => $state->nextStep,
            'total_steps' => GuidedRunPlan::stepCount($state->includesSubscriptions),
            'last_step' => $state->lastVerb === null ? null : $this->stepLabel($state->lastVerb),
            'failure' => $this->failurePayload($state),
            'review' => $review,
            'renewal_pause' => $state->phase === GuidedRunState::AWAITING_RENEWAL_PAUSE
                ? [
                    'title' => 'Pause WooCommerce renewals',
                    'message' => 'Pause checkout, subscription changes and scheduled renewal jobs, then continue. '
                        . 'CartShift will immediately hand renewal ownership to FluentCart.',
                    'action' => 'I have paused renewals — continue',
                ]
                : null,
            'migration_exceptions' => $this->migrationExceptionPayload($this->migrationExceptionsFor($state)),
            'rollback' => $this->rollbackPreview($state),
        ];
    }

    /** @return array<string, mixed>|null */
    private function rollbackPreview(GuidedRunState $state): ?array
    {
        if ($this->canResumeForward($state)) {
            return null;
        }
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
        } catch (\Throwable) {
            return [
                'safe' => false,
                'deletion_count' => 0,
                'review_id' => null,
            ];
        }
    }

    /** @return array{message:string,can_restart:bool,can_resume_forward?:bool}|null */
    private function failurePayload(GuidedRunState $state): ?array
    {
        if ($state->failure === null) {
            return null;
        }
        if ($this->canResumeForward($state)) {
            return [
                'message' => 'WooCommerce renewal ownership was already released safely. Resume the migration to finish activating FluentCart.',
                'can_restart' => false,
                'can_resume_forward' => true,
            ];
        }
        $message = str_contains($state->failure, 'guided_subscription_mode_changed')
            ? 'Subscription availability changed after this check started. '
                . 'Start a new check when the shop setup is stable.'
            : (str_contains($state->failure, 'guided_dependency_bound_target_readiness_unavailable')
                ? 'Orders and subscriptions depend on target links that CartShift cannot prove before preparing records. '
                    . 'The guided check stopped before writing target records.'
            : (str_contains($state->failure, 'guided_completed_rehearsal_rollback_unavailable')
                ? 'CartShift could not prove that this migration could be rolled back safely, so it stopped '
                    . 'before preparing any FluentCart records.'
            : ($state->evidence->descriptor === null
                ? 'The migration stopped before any target records were prepared. You can safely try again.'
                : 'The migration stopped after target preparation. Roll back this run before starting another one.')));

        return [
            'message' => $message,
            'can_restart' => $state->canRestart(),
        ];
    }

    private function canResumeForward(GuidedRunState $state): bool
    {
        if ($state->phase !== GuidedRunState::FAILED
            || !$state->includesSubscriptions
            || $state->evidence->descriptor === null) {
            return false;
        }
        try {
            $evidence = (new SubscriptionCutoverEvidenceRepository(
                (new PrivateWorkspace($state->sourceKey))->path(),
            ))->get($state->evidence->descriptor);

            return $evidence->releaseStarted();
        } catch (\Throwable) {
            return false;
        }
    }

    private function stepLabel(string $verb): string
    {
        return match ($verb) {
            'compatibility' => 'Check compatibility',
            'audit' => 'Inspect source records',
            'propose-decisions' => 'Review migration decisions',
            'export' => 'Create the private migration package',
            'validate-package' => 'Validate the migration package',
            'prepare' => 'Prepare target records',
            'stage' => 'Stage target records',
            'reconcile' => 'Verify staged records',
            'promote' => 'Promote staged records',
            'prepare-subscription-cutover' => 'Prepare subscription transfer',
            'release-subscription-source' => 'Stop WooCommerce subscription renewals',
            'activate-subscriptions' => 'Activate FluentCart subscriptions',
            'activate-catalogue' => 'Activate the FluentCart catalogue',
            'complete' => 'Finish the migration',
            'rollback' => 'Roll back the failed migration',
            default => 'Advance the migration',
        };
    }

    /** @param list<array<string,mixed>> $exceptions @return list<array<string,mixed>> */
    private function migrationExceptionPayload(array $exceptions): array
    {
        $groups = [];
        foreach ($exceptions as $exception) {
            if (!is_array($exception)) {
                continue;
            }
            if (in_array($exception['kind'] ?? null, ['skipped_product', 'skipped_order', 'skipped_subscription'], true)) {
                $kind = (string) $exception['kind'];
                $orders = max(0, (int) ($exception['dependent_orders'] ?? 0));
                $subscriptions = max(0, (int) ($exception['dependent_subscriptions'] ?? 0));
                $groups[] = [
                    'type' => 'skipped_record',
                    'title' => $kind === 'skipped_product'
                        ? trim((string) ($exception['title'] ?? 'Skipped product'))
                        : ($kind === 'skipped_subscription' ? 'Skipped subscription' : 'Skipped order'),
                    'message' => match ($kind) {
                        'skipped_product' => sprintf(
                            'This WooCommerce product stayed in WooCommerce. CartShift also skipped %d related order%s and %d related subscription%s so no incomplete records were created.',
                            $orders,
                            $orders === 1 ? '' : 's',
                            $subscriptions,
                            $subscriptions === 1 ? '' : 's',
                        ),
                        'skipped_subscription' => 'FluentCart already had this subscription. CartShift kept it unchanged and left the WooCommerce subscription managed in WooCommerce.',
                        default => sprintf(
                            'FluentCart already had this order. CartShift kept it unchanged and skipped the WooCommerce copy%s.',
                            $subscriptions > 0 ? sprintf(' together with %d dependent subscription%s', $subscriptions, $subscriptions === 1 ? '' : 's') : '',
                        ),
                    },
                ];
                continue;
            }
            if (($exception['kind'] ?? null) === 'duplicate_variation_sku') {
                $title = trim((string) ($exception['product_name'] ?? 'Product'));
                $key = 'sku-' . hash('sha256', (string) ($exception['source_product'] ?? $title));
                $groups[$key] ??= [
                    'type' => 'sku_change',
                    'title' => $title,
                    'message' => 'WooCommerce allowed the same SKU on more than one variation. '
                        . 'FluentCart requires every variation SKU to be unique, so CartShift kept every variation and assigned a safe unique FluentCart SKU.',
                    'variations' => [],
                    'suggestions' => [
                        'Review any fulfilment or inventory integration that uses the original SKU.',
                        'Keep the generated SKU or replace it with another unique value in FluentCart.',
                    ],
                ];
                $groups[$key]['variations'][] = [
                    'title' => trim((string) ($exception['variation_name'] ?? 'Variation')),
                    'source_sku' => trim((string) ($exception['source_sku'] ?? '')),
                    'target_sku' => trim((string) ($exception['target_sku'] ?? '')),
                ];
                continue;
            }
            if (($exception['kind'] ?? null) === 'physical_order_fulfilment') {
                $key = 'physical-order-fulfilment';
                $groups[$key] ??= [
                    'type' => 'fulfilment_summary',
                    'title' => 'Physical order history',
                    'message' => 'CartShift used each WooCommerce order status to preserve historical fulfilment without creating new shipping work.',
                    'order_count' => 0,
                    'delivered_count' => 0,
                    'unshipped_count' => 0,
                    'mixed_count' => 0,
                ];
                $groups[$key]['order_count']++;
                if (($exception['projection'] ?? null) === 'delivered') {
                    $groups[$key]['delivered_count']++;
                } else {
                    $groups[$key]['unshipped_count']++;
                }
                if (($exception['mixed'] ?? null) === true) {
                    $groups[$key]['mixed_count']++;
                }
                continue;
            }
            if (($exception['kind'] ?? null) === 'historical_order_variation_unlinked') {
                $key = 'historical-order-variation-unlinked';
                $groups[$key] ??= [
                    'type' => 'historical_line_summary',
                    'title' => 'Historical product links',
                    'message' => 'Some WooCommerce orders reference a variation that no longer exists. CartShift kept the product history without guessing a replacement variation.',
                    'line_count' => 0,
                ];
                $groups[$key]['line_count']++;
                continue;
            }
            if (($exception['kind'] ?? null) !== 'shared_parent_stock') {
                continue;
            }
            $title = trim((string) ($exception['product_name'] ?? 'Product'));
            $key = hash('sha256', $title . "\0" . (string) ($exception['source_owner'] ?? ''));
            $quantity = is_int($exception['source_quantity'] ?? null) ? $exception['source_quantity'] : null;
            $quantityState = $quantity === null ? 'unknown' : ($quantity < 0 ? 'below_zero' : 'known');
            $groups[$key] ??= [
                'type' => 'shared_stock',
                'title' => $title,
                'variations' => [],
                'source_quantity' => $quantity,
                'source_quantity_state' => $quantityState,
                'message' => 'WooCommerce shares one stock total across this product, while FluentCart stores stock per variation. '
                    . 'CartShift will migrate the affected variations inactive and unavailable with zero stock and backorders disabled to prevent overselling. '
                    . ($quantityState === 'known'
                        ? 'The original quantity below is one product-wide total, not an amount to copy into every variation.'
                        : 'WooCommerce did not provide a usable stock total, so keep every affected variation unavailable until stock is counted.'),
                'suggestions' => $quantityState === 'known' ? [
                    'Enable FluentCart stock management, then allocate stock without exceeding the original shared total.',
                    'Activate only the variations you want to sell after their stock is set.',
                    'Leave the variations inactive until stock is confirmed.',
                ] : [
                    'Count the available stock before entering quantities in FluentCart.',
                    'Enable FluentCart stock management before activating confirmed variations.',
                    'Leave all affected variations inactive until the count is complete.',
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
            if (($group['type'] ?? null) !== 'shared_stock') {
                continue;
            }
            $verification = $group['_target_verification'];
            unset($group['_target_verification']);
            $group['target_state'] = in_array(false, $verification, true)
                ? 'needs_review'
                : (in_array(null, $verification, true) ? 'planned' : 'confirmed');
            if ($group['target_state'] !== 'planned') {
                $group['message'] = str_replace(
                    'CartShift will migrate the affected variations',
                    'CartShift migrated the affected variations',
                    $group['message'],
                );
            }
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

    private function decisionReview(): GuidedDecisionReview
    {
        return new GuidedDecisionReview(
            $this->customerDecisions ?? new GuidedCustomerDecisionBuilder(),
            $this->productDecisions ?? new GuidedProductDecisionBuilder(),
            $this->collisionDecisions ?? new GuidedCollisionDecisionBuilder(),
        );
    }

    private function operatorId(): string
    {
        return 'wp-user:' . max(1, get_current_user_id());
    }
}
