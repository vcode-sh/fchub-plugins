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
                return new RollbackPlan($runId, $generation, [], ['rollback_dependency_order_unproven'], false, []);
            }
            $sequences[$receipt->sequence] = true;
            $previousSequence = $receipt->sequence;
        }
        $deletions = [];
        $mappingRetirements = [];
        $conflicts = [];
        foreach (array_reverse($receipts) as $receipt) {
            if (!$receipt instanceof TransferReceipt || $receipt->runId !== $runId || $receipt->generation !== $generation) {
                $conflicts[] = 'rollback_receipt_scope_invalid';
                continue;
            }
            if ($receipt->action === 'reused') {
                $mappingRetirements[] = ['source_identity' => $receipt->sourceIdentity, 'receipt' => $receipt];
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
        return new RollbackPlan($runId, $generation, $deletions, $conflicts, $conflicts === [], $mappingRetirements);
    }

    /** @param callable(TransferReceipt): void $markRolledBack */
    public function execute(RollbackPlan $plan, RollbackTargetGateway $gateway, callable $markRolledBack): void
    {
        if (!$plan->safe) {
            throw new \RuntimeException('rollback_plan_conflicted');
        }
        $operations = array_merge($plan->deletions, $plan->mappingRetirements);
        usort($operations, static fn (array $left, array $right): int => $right['receipt']->sequence <=> $left['receipt']->sequence);
        foreach ($operations as $item) {
            $receipt = $item['receipt'];
            if ($receipt->action === 'created') {
                $actual = $gateway->fingerprint($receipt);
                if ($actual === null || !hash_equals($receipt->afterFingerprint, $actual)) {
                    throw new \RuntimeException('rollback_target_drift_during_execution:' . $receipt->sourceIdentity);
                }
                $gateway->delete($receipt);
            }
            $markRolledBack($receipt);
        }
    }
}
