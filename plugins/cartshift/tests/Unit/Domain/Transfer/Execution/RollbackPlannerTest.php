<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\RollbackPlanner;
use CartShift\Domain\Transfer\Execution\RollbackTargetGateway;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Tests\Unit\PluginTestCase;

final class RollbackPlannerTest extends PluginTestCase
{
    public function testOneDriftedCreatedRowBlocksEveryDeletionAndReusedRowsAreOnlyMappingRetirements(): void
    {
        $receipts = [
            $this->receipt('product', 'shop-alpha:product:1', 'created', 10, str_repeat('a', 64), 1),
            $this->receipt('customer', 'shop-alpha:customer:2', 'reused', 20, str_repeat('b', 64), 2, str_repeat('9', 64)),
            $this->receipt('order', 'shop-alpha:order:3', 'created', 30, str_repeat('c', 64), 3),
        ];
        $gateway = new RecordingRollbackGateway([
            'shop-alpha:product:1' => str_repeat('a', 64),
            'shop-alpha:customer:2' => str_repeat('b', 64),
            'shop-alpha:order:3' => str_repeat('f', 64),
        ]);
        $planner = new RollbackPlanner();

        $plan = $planner->plan('run-task-22', 1, $receipts, $gateway);

        self::assertFalse($plan->safe);
        self::assertSame(['rollback_target_drift:shop-alpha:order:3'], $plan->conflicts);
        self::assertSame(['shop-alpha:order:3', 'shop-alpha:product:1'], array_column($plan->deletions, 'source_identity'));
        self::assertSame(['shop-alpha:customer:2'], array_column($plan->mappingRetirements, 'source_identity'));

        try {
            $planner->execute($plan, $gateway, static function (): void {});
            self::fail('A conflicted rollback deleted target rows.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback_plan_conflicted', $exception->getMessage());
        }

        self::assertSame([], $gateway->deleted);
    }

    public function testSafePlanDeletesInReverseDependencyOrderAndPreservesEvidenceCallback(): void
    {
        $receipts = [
            $this->receipt('product', 'shop-alpha:product:1', 'created', 10, str_repeat('a', 64), 1),
            $this->receipt('order', 'shop-alpha:order:3', 'created', 30, str_repeat('c', 64), 2),
        ];
        $gateway = new RecordingRollbackGateway([
            'shop-alpha:product:1' => str_repeat('a', 64),
            'shop-alpha:order:3' => str_repeat('c', 64),
        ]);
        $marked = [];
        $planner = new RollbackPlanner();
        $plan = $planner->plan('run-task-22', 1, $receipts, $gateway);

        $planner->execute($plan, $gateway, static function (TransferReceipt $receipt) use (&$marked): void {
            $marked[] = $receipt->sourceIdentity;
        });

        self::assertTrue($plan->safe);
        self::assertSame(['shop-alpha:order:3', 'shop-alpha:product:1'], $gateway->deleted);
        self::assertSame($gateway->deleted, $marked);
    }

    public function testSafePlanRetiresReusedMappingEvidenceWithoutTouchingItsTarget(): void
    {
        $receipts = [
            $this->receipt('product', 'shop-alpha:product:1', 'created', 10, str_repeat('a', 64), 1),
            $this->receipt('customer', 'shop-alpha:customer:2', 'reused', 20, str_repeat('b', 64), 2, str_repeat('b', 64)),
        ];
        $gateway = new RecordingRollbackGateway([
            'shop-alpha:product:1' => str_repeat('a', 64),
            'shop-alpha:customer:2' => str_repeat('b', 64),
        ]);
        $marked = [];
        $planner = new RollbackPlanner();
        $plan = $planner->plan('run-task-22', 1, $receipts, $gateway);

        $planner->execute($plan, $gateway, static function (TransferReceipt $receipt) use (&$marked): void {
            $marked[] = $receipt->sourceIdentity;
        });

        self::assertTrue($plan->safe);
        self::assertSame(['shop-alpha:product:1'], array_column($plan->deletions, 'source_identity'));
        self::assertSame(['shop-alpha:customer:2'], array_column($plan->mappingRetirements, 'source_identity'));
        self::assertSame(['shop-alpha:product:1'], $gateway->deleted);
        self::assertSame(['shop-alpha:customer:2', 'shop-alpha:product:1'], $marked);
        self::assertSame(2, $gateway->fingerprintCalls);
    }

    public function testOutOfOrderOrDuplicateReceiptSequenceBlocksBeforeFingerprintingTargets(): void
    {
        $receipts = [
            $this->receipt('order', 'shop-alpha:order:3', 'created', 30, str_repeat('c', 64), 2),
            $this->receipt('product', 'shop-alpha:product:1', 'created', 10, str_repeat('a', 64), 1),
            $this->receipt('customer', 'shop-alpha:customer:2', 'created', 20, str_repeat('b', 64), 1),
        ];
        $gateway = new RecordingRollbackGateway([]);

        $plan = (new RollbackPlanner())->plan('run-task-22', 1, $receipts, $gateway);

        self::assertFalse($plan->safe);
        self::assertSame(['rollback_dependency_order_unproven'], $plan->conflicts);
        self::assertSame([], $plan->deletions);
        self::assertSame([], $plan->mappingRetirements);
        self::assertSame(0, $gateway->fingerprintCalls);
    }

    private function receipt(
        string $kind,
        string $identity,
        string $action,
        int $targetId,
        string $after,
        int $sequence,
        ?string $before = null,
    ): TransferReceipt {
        return new TransferReceipt(
            'run-task-22',
            $kind,
            $identity,
            1,
            str_repeat('1', 64),
            $action,
            ['primary' => $targetId],
            $before,
            $after,
            $sequence,
            '2026-08-10T12:00:00Z',
            '2026-08-10T12:00:01Z',
        );
    }
}

final class RecordingRollbackGateway implements RollbackTargetGateway
{
    /** @var list<string> */
    public array $deleted = [];
    public int $fingerprintCalls = 0;

    /** @param array<string, string|null> $fingerprints */
    public function __construct(private array $fingerprints) {}

    public function fingerprint(TransferReceipt $receipt): ?string
    {
        ++$this->fingerprintCalls;
        return $this->fingerprints[$receipt->sourceIdentity] ?? null;
    }

    public function delete(TransferReceipt $receipt): void
    {
        $this->deleted[] = $receipt->sourceIdentity;
        $this->fingerprints[$receipt->sourceIdentity] = null;
    }
}
