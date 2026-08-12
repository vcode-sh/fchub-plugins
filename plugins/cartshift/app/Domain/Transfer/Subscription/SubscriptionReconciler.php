<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\ReconciliationResult;

defined('ABSPATH') || exit;

final readonly class SubscriptionReconciler
{
    public function __construct(
        private SubscriptionTargetGateway $gateway,
        private CheckedMappingStore $maps,
        private SubscriptionTargetFingerprint $fingerprint = new SubscriptionTargetFingerprint(),
    ) {}

    public function reconcile(SubscriptionStagePlan $plan, int $targetId, string $expectedFingerprint): ReconciliationResult
    {
        $failures = [];
        $mapping = $this->maps->get($plan->record->identity);
        if ($mapping === null || !$mapping->isActive() || $mapping->targetId !== $targetId) {
            $failures[] = 'subscription_checked_map_missing';
        } else {
            if (!hash_equals($plan->sourceFingerprint, $mapping->sourceFingerprint)) {
                $failures[] = 'subscription_checked_map_source_changed';
            }
            if (!hash_equals($expectedFingerprint, $mapping->targetFingerprint)) {
                $failures[] = 'subscription_checked_map_target_changed';
            }
        }
        $snapshot = $this->gateway->snapshot($targetId);
        $expected = self::expectedSnapshot($plan, $targetId);
        if (SubscriptionTargetFingerprint::normalise($snapshot) !== SubscriptionTargetFingerprint::normalise($expected)) {
            $failures[] = 'subscription_graph_mismatch';
        }
        $actual = $this->fingerprint->fingerprint($snapshot, [$plan->record->identity->canonical() => $targetId]);
        if (!hash_equals($expectedFingerprint, $actual)) {
            $failures[] = 'subscription_target_fingerprint_mismatch';
        }
        $failures = array_values(array_unique($failures));
        sort($failures, SORT_STRING);
        return new ReconciliationResult($failures === [], $actual, $failures);
    }

    /** @return array<string,mixed> */
    public static function expectedSnapshot(SubscriptionStagePlan $plan, int $targetId): array
    {
        $links = [];
        foreach ($plan->transactionLinks as $id => $orderType) {
            $links[] = ['id' => $id, 'subscription_id' => $targetId, 'order_type' => $orderType];
        }
        $meta = array_filter($plan->corrections, static fn (int $value): bool => $value > 0);
        return ['subscription' => ['id' => $targetId] + $plan->row, 'transaction_links' => $links, 'meta' => $meta];
    }
}
