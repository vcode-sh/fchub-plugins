<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Domain\Transfer\Execution\RollbackPlanner;
use CartShift\Domain\Transfer\Execution\RollbackTargetGateway;
use CartShift\Domain\Transfer\Execution\TransferReceipt;

final class RollbackConflictTest extends FailureTestCase
{
    public function testOneDriftedTargetBlocksTheEntireRollbackBeforeAnyDeletion(): void
    {
        $receipts = [$this->receipt('product', '41', 1), $this->receipt('order', '42', 2)];
        $gateway = new FailureRollbackGateway([
            $receipts[0]->sourceIdentity => $receipts[0]->afterFingerprint,
            $receipts[1]->sourceIdentity => str_repeat('f', 64),
        ]);
        $planner = new RollbackPlanner();
        $plan = $planner->plan('run-failure-matrix', 1, $receipts, $gateway);

        self::assertFalse($plan->safe);
        self::assertSame(['rollback_target_drift:' . $receipts[1]->sourceIdentity], $plan->conflicts);
        try {
            $planner->execute($plan, $gateway, static function (): void {});
            self::fail('A conflicted rollback deleted an unchanged target row.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback_plan_conflicted', $exception->getMessage());
        }
        self::assertSame([], $gateway->deleted);
    }

    public function testDriftAfterApprovalStopsAtTheFirstExecutionRead(): void
    {
        $receipt = $this->receipt('product', '51', 1);
        $gateway = new FailureRollbackGateway([$receipt->sourceIdentity => $receipt->afterFingerprint]);
        $planner = new RollbackPlanner();
        $plan = $planner->plan('run-failure-matrix', 1, [$receipt], $gateway);
        self::assertTrue($plan->safe);

        $gateway->fingerprints[$receipt->sourceIdentity] = str_repeat('f', 64);
        try {
            $planner->execute($plan, $gateway, static function (): void {});
            self::fail('Post-approval target drift was deleted.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'rollback_target_drift_during_execution:' . $receipt->sourceIdentity,
                $exception->getMessage(),
            );
        }
        self::assertSame([], $gateway->deleted);
    }

    private function receipt(string $kind, string $id, int $sequence): TransferReceipt
    {
        return new TransferReceipt(
            'run-failure-matrix',
            $kind,
            'contract-source:' . $kind . ':' . $id,
            1,
            str_repeat('a', 64),
            'created',
            ['primary' => 900 + $sequence],
            null,
            str_repeat((string) $sequence, 64),
            $sequence,
            '2026-08-10T12:00:00Z',
            '2026-08-10T12:00:01Z',
        );
    }
}

final class FailureRollbackGateway implements RollbackTargetGateway
{
    /** @var list<string> */
    public array $deleted = [];

    /** @param array<string, string|null> $fingerprints */
    public function __construct(public array $fingerprints)
    {
    }

    public function fingerprint(TransferReceipt $receipt): ?string
    {
        return $this->fingerprints[$receipt->sourceIdentity] ?? null;
    }

    public function delete(TransferReceipt $receipt): void
    {
        $this->deleted[] = $receipt->sourceIdentity;
        $this->fingerprints[$receipt->sourceIdentity] = null;
    }
}

