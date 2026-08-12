<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

final readonly class RollbackPlanner
{
    /** @param list<TransferReceipt> $receipts */
    public function plan(string $runId, int $generation, array $receipts, RollbackTargetGateway $gateway): RollbackPlan
    {
        $sequences = [];
        $previousSequence = 0;
        foreach ($receipts as $receipt) {
            if (!$receipt instanceof TransferReceipt
                || $receipt->sequence <= $previousSequence
                || isset($sequences[$receipt->sequence])) {
                return new RollbackPlan($runId, $generation, [], ['rollback_dependency_order_unproven'], false);
            }
            $sequences[$receipt->sequence] = true;
            $previousSequence = $receipt->sequence;
        }
        $deletions = [];
        $conflicts = [];
        foreach (array_reverse($receipts) as $receipt) {
            if (!$receipt instanceof TransferReceipt || $receipt->runId !== $runId || $receipt->generation !== $generation) {
                $conflicts[] = 'rollback_receipt_scope_invalid';
                continue;
            }
            if ($receipt->action !== 'created') {
                continue;
            }
            $deletions[] = ['source_identity' => $receipt->sourceIdentity, 'receipt' => $receipt];
            $actual = $gateway->fingerprint($receipt);
            if ($actual === null || !hash_equals($receipt->afterFingerprint, $actual)) {
                $conflicts[] = 'rollback_target_drift:' . $receipt->sourceIdentity;
            }
        }
        $conflicts = array_values(array_unique($conflicts));
        return new RollbackPlan($runId, $generation, $deletions, $conflicts, $conflicts === []);
    }

    /** @param callable(TransferReceipt): void $markRolledBack */
    public function execute(RollbackPlan $plan, RollbackTargetGateway $gateway, callable $markRolledBack): void
    {
        if (!$plan->safe) {
            throw new \RuntimeException('rollback_plan_conflicted');
        }
        foreach ($plan->deletions as $item) {
            $receipt = $item['receipt'];
            $actual = $gateway->fingerprint($receipt);
            if ($actual === null || !hash_equals($receipt->afterFingerprint, $actual)) {
                throw new \RuntimeException('rollback_target_drift_during_execution:' . $receipt->sourceIdentity);
            }
            $gateway->delete($receipt);
            $markRolledBack($receipt);
        }
    }
}
