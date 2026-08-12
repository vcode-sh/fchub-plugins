<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Order\CustomerAggregateGateway;
use CartShift\Domain\Transfer\Order\CustomerAggregateProjector;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class CustomerAggregateProjectorTest extends PluginTestCase
{
    public function testCompleteTargetSetIncludesPreExistingOrdersRefundsAndCurrencyValues(): void
    {
        $gateway = new MemoryCustomerAggregateGateway([
            $this->order('paid', 'PLN', 10000, 0, 1, '2026-01-01 10:00:00'),
            $this->order('partially_refunded', 'PLN', 20000, 5000, 1, '2026-02-01 10:00:00'),
            $this->order('partially_paid', 'EUR', 5000, 1000, 4, '2026-03-01 10:00:00'),
            $this->order('refunded', 'PLN', 12000, 12000, 1, '2026-04-01 10:00:00'),
            $this->order('pending', 'PLN', 99999, 0, 1, '2026-05-01 10:00:00'),
        ]);

        $result = (new CustomerAggregateProjector($gateway))->projectCompleteSet(
            new SourceIdentity('lapka-web', 'customer', '81'),
            701,
            'aggregate-run',
        );

        self::assertSame([
            'purchase_value' => ['EUR' => 4000, 'PLN' => 25000],
            'purchase_count' => 3,
            'ltv' => 41000,
            'aov' => 13666.67,
            'first_purchase_date' => '2026-01-01 10:00:00',
            'last_purchase_date' => '2026-03-01 10:00:00',
        ], $gateway->customer);
        self::assertFalse($result->reused);
        self::assertCount(1, $gateway->receipts);
        self::assertSame('customer_aggregate', $gateway->receipts[0]['record_kind']);
        self::assertSame($result->targetFingerprint, $gateway->receipts[0]['target_fingerprint']);
    }

    public function testIndependentSqlDisagreementRollsBackAggregateAndReceipt(): void
    {
        $gateway = new MemoryCustomerAggregateGateway([
            $this->order('paid', 'PLN', 10000, 0, 1, '2026-01-01 10:00:00'),
        ]);
        $gateway->independentOverride = [
            'purchase_value' => ['PLN' => 9999],
            'purchase_count' => 1,
            'ltv' => 9999,
            'aov' => 9999.0,
            'first_purchase_date' => '2026-01-01 10:00:00',
            'last_purchase_date' => '2026-01-01 10:00:00',
        ];

        try {
            (new CustomerAggregateProjector($gateway))->projectCompleteSet(
                new SourceIdentity('lapka-web', 'customer', '81'),
                701,
                'aggregate-run',
            );
            self::fail('A self-consistent writer overruled an independent SQL mismatch.');
        } catch (\RuntimeException $exception) {
            self::assertSame('customer_aggregate_reconciliation_failed', $exception->getMessage());
        }

        self::assertSame([], $gateway->customer);
        self::assertSame([], $gateway->receipts);
        self::assertSame(0, DatabaseTransaction::depth());
    }

    public function testExactReceiptMakesRetryReadOnlyButSourceOrderDriftBlocks(): void
    {
        $gateway = new MemoryCustomerAggregateGateway([
            $this->order('paid', 'PLN', 10000, 0, 1, '2026-01-01 10:00:00'),
        ]);
        $projector = new CustomerAggregateProjector($gateway);
        $identity = new SourceIdentity('lapka-web', 'customer', '81');
        $first = $projector->projectCompleteSet($identity, 701, 'aggregate-run');
        $writes = $gateway->writes;
        $second = $projector->projectCompleteSet($identity, 701, 'aggregate-run');

        self::assertTrue($second->reused);
        self::assertSame($first->targetFingerprint, $second->targetFingerprint);
        self::assertSame($writes, $gateway->writes);

        $gateway->orders[] = $this->order('paid', 'PLN', 2000, 0, 1, '2026-02-01 10:00:00');
        $this->expectExceptionMessage('customer_aggregate_receipt_stale');
        $projector->projectCompleteSet($identity, 701, 'aggregate-run');
    }

    /** @return array<string,mixed> */
    private function order(
        string $status,
        string $currency,
        int $paid,
        int $refund,
        int $rate,
        string $createdAt,
    ): array {
        return compact('status', 'currency', 'paid', 'refund', 'rate') + ['created_at' => $createdAt];
    }
}

final class MemoryCustomerAggregateGateway implements CustomerAggregateGateway
{
    /** @param list<array<string,mixed>> $orders */
    public function __construct(public array $orders) {}
    /** @var array<string,mixed> */
    public array $customer = [];
    /** @var list<array<string,mixed>> */
    public array $receipts = [];
    /** @var array<string,mixed>|null */
    public ?array $independentOverride = null;
    public int $writes = 0;
    private bool $rollbackRegistered = false;

    public function customerExists(int $customerId): bool { return $customerId === 701; }
    public function orders(int $customerId): array { return $this->orders; }
    public function write(int $customerId, array $aggregate): void
    {
        $this->registerRollback();
        ++$this->writes;
        $this->customer = $aggregate;
    }
    public function snapshot(int $customerId): array { return $this->customer; }
    public function independentProjection(int $customerId): array
    {
        return $this->independentOverride ?? CustomerAggregateProjector::calculate($this->orders);
    }
    public function receipt(SourceIdentity $source, string $runId, int $generation): ?array
    {
        foreach ($this->receipts as $receipt) {
            if ($receipt['source_identity'] === $source->canonical()
                && $receipt['run_id'] === $runId && $receipt['generation'] === $generation) return $receipt;
        }
        return null;
    }
    public function storeReceipt(array $receipt): void
    {
        $this->registerRollback();
        ++$this->writes;
        $this->receipts[] = $receipt;
    }

    private function registerRollback(): void
    {
        if ($this->rollbackRegistered) return;
        $customer = $this->customer;
        $receipts = $this->receipts;
        $writes = $this->writes;
        DatabaseTransaction::afterRollback(function () use ($customer, $receipts, $writes): void {
            $this->customer = $customer;
            $this->receipts = $receipts;
            $this->writes = $writes;
            $this->rollbackRegistered = false;
        });
        DatabaseTransaction::afterCommit(function (): void { $this->rollbackRegistered = false; });
        $this->rollbackRegistered = true;
    }
}
