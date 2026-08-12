<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\TargetClaimStore;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\StageResult;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final readonly class FluentCartSubscriptionWriter
{
    public function __construct(
        private SubscriptionTargetGateway $gateway,
        private CheckedMappingStore $maps,
        private TargetClaimStore $claims,
        private SubscriptionReconciler $reconciler,
        private SubscriptionTargetFingerprint $fingerprint = new SubscriptionTargetFingerprint(),
    ) {}

    public function stage(SubscriptionStagePlan $plan, StageContext $context): StageResult
    {
        $this->assertDependencies($plan);
        $existing = $this->maps->get($plan->record->identity);
        DatabaseTransaction::begin();
        try {
            if ($existing !== null) {
                if (!$existing->isActive() || !$this->gateway->exists($existing->targetId)) {
                    throw new \RuntimeException('target_subscription_mapping_inactive_or_missing');
                }
                $result = $this->reconciler->reconcile($plan, $existing->targetId, $existing->targetFingerprint);
                if (!$result->matches) {
                    throw new \RuntimeException('target_subscription_reconciliation_failed:' . implode(',', $result->failures));
                }
                $this->claims->claimOrThrow($plan->record->identity, $existing->targetId, $context->migrationId, $plan->sourceFingerprint, $result->actualFingerprint, MapState::Reconciled);
                DatabaseTransaction::commit();
                return new StageResult(
                    $existing->targetId,
                    [],
                    [],
                    [],
                    $result->actualFingerprint,
                    true,
                    [],
                    [$plan->record->identity->canonical() => $existing->targetId],
                );
            }

            $targetId = $this->gateway->create($plan->row);
            if ($targetId <= 0) {
                throw new \RuntimeException('target_subscription_write_failed');
            }
            foreach ($plan->transactionLinks as $transactionId => $orderType) {
                $this->gateway->linkTransaction($transactionId, $targetId, $orderType);
            }
            foreach ($plan->corrections as $key => $value) {
                $this->gateway->writeCorrection($targetId, $key, $value);
            }
            $snapshot = $this->gateway->snapshot($targetId);
            $targetFingerprint = $this->fingerprint->fingerprint($snapshot, [$plan->record->identity->canonical() => $targetId]);
            $this->maps->storeOrThrow($plan->record->identity, $targetId, $context->migrationId, $plan->sourceFingerprint, $targetFingerprint, MapState::Staged, true, $context->generation);
            $result = $this->reconciler->reconcile($plan, $targetId, $targetFingerprint);
            if (!$result->matches) {
                throw new \RuntimeException('target_subscription_reconciliation_failed:' . implode(',', $result->failures));
            }
            $this->claims->claimOrThrow($plan->record->identity, $targetId, $context->migrationId, $plan->sourceFingerprint, $result->actualFingerprint, MapState::Reconciled);
            $this->maps->transitionOrThrow($plan->record->identity, MapState::Staged, MapState::Reconciled, $targetFingerprint, $result->actualFingerprint);
            DatabaseTransaction::commit();
            return new StageResult(
                $targetId,
                [],
                [],
                [],
                $result->actualFingerprint,
                false,
                [],
                [$plan->record->identity->canonical() => $targetId],
            );
        } catch (\Throwable $exception) {
            DatabaseTransaction::rollback($exception);
            throw $exception;
        }
    }

    private function assertDependencies(SubscriptionStagePlan $plan): void
    {
        foreach ($plan->dependencyTargets as $canonical => $targetId) {
            $mapping = $this->maps->get(\CartShift\Domain\Transfer\SourceIdentity::fromCanonical($canonical));
            if ($mapping === null || !$mapping->isActive() || $mapping->targetId !== $targetId) {
                throw new \RuntimeException('target_subscription_dependency_mapping_changed');
            }
        }
    }
}
