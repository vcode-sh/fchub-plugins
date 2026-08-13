<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\Identity\TargetClaimStore;
use CartShift\Domain\Transfer\Order\AddressRecord;
use CartShift\Domain\Transfer\Order\CouponLineRecord;
use CartShift\Domain\Transfer\Order\FeeLineRecord;
use CartShift\Domain\Transfer\Order\FluentCartOrderWriter;
use CartShift\Domain\Transfer\Order\OrderLineRecord;
use CartShift\Domain\Transfer\Order\OrderNoteRecord;
use CartShift\Domain\Transfer\Order\OrderProjectionContext;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Order\OrderStagePlan;
use CartShift\Domain\Transfer\Order\OrderTargetGateway;
use CartShift\Domain\Transfer\Order\OrderReconciler;
use CartShift\Domain\Transfer\Order\PaymentEvidenceKind;
use CartShift\Domain\Transfer\Order\PaymentEventRecord;
use CartShift\Domain\Transfer\Order\ShippingLineRecord;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Support\CanonicalJson;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class FluentCartOrderWriterTest extends PluginTestCase
{
    private string $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = sys_get_temp_dir() . '/cartshift-order-writer-' . bin2hex(random_bytes(8));
        mkdir($this->package, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->package)) {
            rmdir($this->package);
        }
        parent::tearDown();
    }

    public function testTaxRateFailureRollsBackWholeOrderGraphAndItsMaps(): void
    {
        [$writer, $target, $maps] = $this->writer();
        $target->failOn = 'tax';

        try {
            $writer->stage($this->plan(), $this->stageContext());
            self::fail('A late tax-row failure left an order graph behind.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced target failure: tax', $exception->getMessage());
        }

        self::assertSame([], $target->orders);
        self::assertSame([], $target->items);
        self::assertSame([], $target->addresses);
        self::assertSame([], $target->coupons);
        self::assertSame([], $target->taxRates);
        self::assertSame([], $target->transactions);
        self::assertSame([], $target->meta);
        self::assertSame([], $maps->ownedRecords());
        self::assertSame(0, DatabaseTransaction::depth());
    }

    /** @return array<string, array{string}> */
    public static function graphBoundaryFailures(): array
    {
        return [
            'between parent order and first child item' => ['item:product'],
            'between product and fee child items' => ['item:fee'],
            'after child items before dependent rows' => ['address'],
            'between charge and refund transactions' => ['transaction:refund'],
            'after payment graph before provenance' => ['meta:cartshift_order_provenance'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('graphBoundaryFailures')]
    public function testEveryLateOrderGraphFailureRollsBackParentChildrenPaymentsAndMaps(string $failurePoint): void
    {
        [$writer, $target, $maps] = $this->writer();
        $target->failOn = $failurePoint;

        try {
            $writer->stage($this->plan(), $this->stageContext());
            self::fail('The injected ' . $failurePoint . ' failure left a partial order graph.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced target failure: ' . $failurePoint, $exception->getMessage());
        }

        self::assertSame([], $target->orders);
        self::assertSame([], $target->items);
        self::assertSame([], $target->addresses);
        self::assertSame([], $target->coupons);
        self::assertSame([], $target->taxRates);
        self::assertSame([], $target->transactions);
        self::assertSame([], $target->meta);
        self::assertSame([], $maps->ownedRecords());
        self::assertSame(0, DatabaseTransaction::depth());
    }

    public function testRetryWritesNothingAfterExactPersistedReconciliation(): void
    {
        [$writer, $target] = $this->writer();

        $first = $writer->stage($this->plan(), $this->stageContext());
        $before = $target->fingerprintAll();
        $writes = $target->writeCount;
        $second = $writer->stage($this->plan(), $this->stageContext());

        self::assertSame($first->targetId, $second->targetId);
        self::assertSame($first->targetFingerprint, $second->targetFingerprint);
        self::assertTrue($second->reused);
        self::assertSame($before, $target->fingerprintAll());
        self::assertSame($writes, $target->writeCount);
    }

    public function testExclusiveOrderClaimIsPersistedAndRetriedWithExactEvidence(): void
    {
        [$writer, , , $claims] = $this->writer();

        $first = $writer->stage($this->plan(), $this->stageContext());
        $writer->stage($this->plan(), $this->stageContext());

        self::assertCount(2, $claims->attempts);
        self::assertSame($first->targetId, $claims->attempts[0]['target_id']);
        self::assertSame($claims->attempts[0], $claims->attempts[1]);
        self::assertSame(MapState::Reconciled, $claims->attempts[0]['state']);
    }

    public function testWriterUsesTheOnlySafeParentFirstGraphOrder(): void
    {
        [$writer, $target] = $this->writer();

        $writer->stage($this->plan(), $this->stageContext());

        self::assertSame([
            'order',
            'item:product',
            'item:fee',
            'address',
            'coupon',
            'tax',
            'transaction:charge',
            'transaction:refund',
            'meta:cartshift_order_provenance',
        ], $target->writeOrder);
    }

    public function testRetryRejectsPersistedTaxAndReferenceDriftWithoutRepairWrites(): void
    {
        [$writer, $target] = $this->writer();
        $result = $writer->stage($this->plan(), $this->stageContext());
        $target->taxRates[array_key_first($target->taxRates)]['order_tax'] = 9;
        $target->items[array_key_first($target->items)]['object_id'] = 9999;
        $writes = $target->writeCount;

        try {
            $writer->stage($this->plan(), $this->stageContext());
            self::fail('A drifted order graph was silently reused or repaired.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('target_reconciliation_failed', $exception->getMessage());
            self::assertStringContainsString('tax_row_mismatch', $exception->getMessage());
            self::assertStringContainsString('product_reference_mismatch', $exception->getMessage());
        }

        self::assertSame($writes, $target->writeCount);
        self::assertSame(9999, $target->items[array_key_first($target->items)]['object_id']);
        self::assertSame($result->targetId, array_key_first($target->orders));
    }

    public function testRenewalWithoutItsCheckedParentMappingBlocksBeforeAnyWrite(): void
    {
        [$writer, $target, $maps] = $this->writer();
        $record = OrderWriterFixture::record(parent: OrderWriterFixture::identity('4999'), relationship: 'renewal');
        $plan = $this->plan($record, parentTargetId: 880);

        try {
            $writer->stage($plan, $this->stageContext());
            self::fail('A renewal was staged against an unproved parent ID.');
        } catch (\RuntimeException $exception) {
            self::assertSame('order_dependency_mapping_missing: parent_order', $exception->getMessage());
        }

        self::assertSame(0, $target->writeCount);
        self::assertSame([], $maps->ownedRecords());
    }

    public function testHistoricalLineWithDeletedVariationRequiresOnlyItsProductMapping(): void
    {
        [$writer, $target, $maps] = $this->writer();
        $record = OrderWriterFixture::record();
        unset($maps->records[$record->productLines[0]->variation->canonical()]);
        $plan = OrderStagePlan::build(
            $record,
            new OrderProjectionContext(
                [$record->productLines[0]->identity->canonical() => [
                    'post_id' => 501,
                    'object_id' => 0,
                    'fulfillment_type' => 'digital',
                    'historical_variation_unlinked' => true,
                ]],
                [$record->couponLines[0]->identity->canonical() => null],
                [],
                'test',
                'Historical WooCommerce provenance',
                true,
            ),
            customerTargetId: 701,
        );

        $writer->stage($plan, $this->stageContext());

        self::assertCount(2, $target->items);
        self::assertSame(0, $target->items[array_key_first($target->items)]['object_id']);
        self::assertTrue($target->items[array_key_first($target->items)]['other_info']['historical_variation_unlinked']);
    }

    public function testNoteDecisionFingerprintCannotBeReusedAfterPrivateContentDrifts(): void
    {
        $selected = new OrderNoteRecord(
            OrderWriterFixture::identity('5001:note:71'),
            71,
            'Original customer note',
            '2026-01-01T11:00:00Z',
            true,
            'customer',
            str_repeat('a', 64),
        );
        $original = OrderWriterFixture::record(notes: [$selected]);
        $decision = OrderStagePlan::noteDecisionFingerprint($original, $selected->identity);
        $changed = OrderWriterFixture::record(notes: [new OrderNoteRecord(
            $selected->identity,
            71,
            'Changed customer note',
            '2026-01-01T11:00:00Z',
            true,
            'customer',
            str_repeat('a', 64),
        )]);

        try {
            $this->plan($changed, canonicalNote: $selected->identity, noteDecisionFingerprint: $decision);
            self::fail('A note-visibility decision survived a change to the selected private content.');
        } catch (\CartShift\Domain\Transfer\SourceRecordException $exception) {
            self::assertSame('target_schema_unrepresentable', $exception->reasonCode);
        }
    }

    /** @return array{FluentCartOrderWriter, MemoryOrderTargetGateway, MemoryOrderMaps, MemoryOrderClaims} */
    private function writer(): array
    {
        $target = new MemoryOrderTargetGateway();
        $maps = new MemoryOrderMaps();
        $record = OrderWriterFixture::record();
        $maps->seed($record->customer, 701);
        $maps->seed($record->productLines[0]->product, 501);
        $maps->seed($record->productLines[0]->variation, 601);
        $reconciler = new OrderReconciler($target, $maps);
        $claims = new MemoryOrderClaims();
        return [new FluentCartOrderWriter($target, $maps, $reconciler, $claims), $target, $maps, $claims];
    }

    private function plan(
        ?OrderRecord $record = null,
        ?int $parentTargetId = null,
        ?SourceIdentity $canonicalNote = null,
        ?string $noteDecisionFingerprint = null,
    ): OrderStagePlan {
        $record ??= OrderWriterFixture::record();
        return OrderStagePlan::build(
            $record,
            new OrderProjectionContext(
                [$record->productLines[0]->identity->canonical() => [
                    'post_id' => 501,
                    'object_id' => 601,
                    'fulfillment_type' => 'digital',
                ]],
                [$record->couponLines[0]->identity->canonical() => null],
                [],
                'test',
                'Historical WooCommerce provenance',
                true,
            ),
            customerTargetId: 701,
            parentTargetId: $parentTargetId,
            canonicalCustomerNote: $canonicalNote,
            noteDecisionFingerprint: $noteDecisionFingerprint,
        );
    }

    private function stageContext(): StageContext
    {
        return new StageContext($this->package, 'order-writer-run', 'source-runtime');
    }
}

final class MemoryOrderClaims implements TargetClaimStore
{
    /** @var list<array{identity:string,target_id:int,run_id:string,source_fingerprint:string,target_fingerprint:string,state:MapState}> */
    public array $attempts = [];

    public function claimOrThrow(SourceIdentity $identity, int $targetId, string $runId, string $sourceFingerprint, string $targetFingerprint, MapState $state): MappingRecord
    {
        $this->attempts[] = [
            'identity' => $identity->canonical(),
            'target_id' => $targetId,
            'run_id' => $runId,
            'source_fingerprint' => $sourceFingerprint,
            'target_fingerprint' => $targetFingerprint,
            'state' => $state,
        ];
        return new MappingRecord($identity, $targetId, $sourceFingerprint, $targetFingerprint, $state);
    }
}

final class OrderWriterFixture
{
    public static function identity(string $sourceId, string $kind = 'order'): SourceIdentity
    {
        return new SourceIdentity('lapka-web', $kind, $sourceId);
    }

    /** @param list<OrderNoteRecord> $notes */
    public static function record(
        ?SourceIdentity $parent = null,
        string $relationship = 'checkout',
        array $notes = [],
    ): OrderRecord
    {
        $order = self::identity('5001');
        $customer = self::identity('81', 'customer');
        $product = self::identity('101', 'product');
        $variation = self::identity('101:variation:102', 'product');
        $chargeIdentity = self::identity('5001:charge:5001');
        $line = new OrderLineRecord(
            self::identity('5001:item:11'),
            11,
            $product,
            $variation,
            'Course',
            'COURSE',
            [],
            1,
            0,
            10000,
            10000,
            0,
            1000,
            0,
            0,
            9000,
            1500,
            'not_available_from_woo_core',
            1,
            '1',
            '2026-01-01T10:00:00Z',
            [],
            ['source_fulfilment_type' => 'digital'],
            [],
        );
        $fee = new FeeLineRecord(self::identity('5001:fee:21'), 21, 'Handling', 500, 0, [], []);
        $shipping = new ShippingLineRecord(
            self::identity('5001:shipping:31'),
            31,
            'flat_rate',
            2,
            'Courier',
            2000,
            0,
            [],
            [],
        );
        $coupon = new CouponLineRecord(self::identity('5001:coupon:41'), 41, 'DOG10', 1000, 0);
        $address = new AddressRecord(
            self::identity('5001:address:1'),
            'billing',
            'Ada',
            'Lovelace',
            '',
            'Main 1',
            '',
            'Warsaw',
            '',
            '00-001',
            'PL',
            'ada@example.invalid',
            '+48 500 000 000',
            '',
        );
        $charge = new PaymentEventRecord(
            $chargeIdentity,
            'charge',
            11500,
            'PLN',
            'succeeded',
            PaymentEvidenceKind::ProviderReference,
            'stripe',
            'Card',
            'ch_private',
            null,
            '2026-01-01T10:00:00Z',
            [],
        );
        $refund = new PaymentEventRecord(
            self::identity('5001:refund:6001'),
            'refund',
            1500,
            'PLN',
            'succeeded',
            PaymentEvidenceKind::ProviderReference,
            'stripe',
            'Card',
            're_private',
            $chargeIdentity,
            '2026-01-02T10:00:00Z',
            [],
        );

        return new OrderRecord(
            $order,
            $customer,
            $parent,
            $relationship,
            'completed',
            'PLN',
            'PLN',
            'PLN',
            '1',
            'same_currency:PLN',
            false,
            10000,
            1000,
            0,
            0,
            2000,
            0,
            500,
            0,
            0,
            11500,
            1500,
            '2026-01-01T10:00:00Z',
            '2026-01-02T11:00:00Z',
            '2026-01-01T10:00:00Z',
            '2026-01-01T10:00:00Z',
            '2026-01-02T10:00:00Z',
            [$line],
            [$fee],
            [$shipping],
            [$coupon],
            [],
            [$address],
            [$charge, $refund],
            $notes,
            ['campaign' => 'winter'],
        );
    }
}

final class MemoryOrderMaps implements CheckedMappingStore
{
    /** @var array<string, MappingRecord> */
    public array $records = [];
    /** @var array<string, bool> */
    private array $dependencies = [];
    private bool $rollbackRegistered = false;

    public function seed(?SourceIdentity $identity, int $targetId): void
    {
        if ($identity === null) {
            return;
        }
        $this->dependencies[$identity->canonical()] = true;
        $this->records[$identity->canonical()] = new MappingRecord(
            $identity,
            $targetId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            MapState::Reconciled,
        );
    }

    public function get(SourceIdentity $identity): ?MappingRecord
    {
        return $this->records[$identity->canonical()] ?? null;
    }

    public function storeOrThrow(
        SourceIdentity $identity,
        int $targetId,
        string $migrationId,
        string $sourceFingerprint,
        string $targetFingerprint,
        MapState $state,
        bool $createdByMigration,
        int $generation = 1,
    ): MappingRecord {
        $this->registerRollback();
        if (isset($this->records[$identity->canonical()])) {
            throw new \RuntimeException('duplicate order mapping');
        }
        return $this->records[$identity->canonical()] = new MappingRecord(
            $identity,
            $targetId,
            $sourceFingerprint,
            $targetFingerprint,
            $state,
        );
    }

    public function transitionOrThrow(
        SourceIdentity $identity,
        MapState $expected,
        MapState $next,
        string $expectedTargetFingerprint,
        string $nextTargetFingerprint,
    ): MappingRecord {
        $this->registerRollback();
        $current = $this->records[$identity->canonical()] ?? null;
        if ($current === null || $current->state !== $expected
            || !hash_equals($expectedTargetFingerprint, (string) $current->targetFingerprint)) {
            throw new \RuntimeException('order mapping transition conflict');
        }
        return $this->records[$identity->canonical()] = new MappingRecord(
            $identity,
            $current->targetId,
            $current->sourceFingerprint,
            $nextTargetFingerprint,
            $next,
        );
    }

    /** @return array<string, MappingRecord> */
    public function ownedRecords(): array
    {
        return array_diff_key($this->records, $this->dependencies);
    }

    private function registerRollback(): void
    {
        if ($this->rollbackRegistered) {
            return;
        }
        $before = $this->records;
        DatabaseTransaction::afterRollback(function () use ($before): void {
            $this->records = $before;
            $this->rollbackRegistered = false;
        });
        DatabaseTransaction::afterCommit(function (): void {
            $this->rollbackRegistered = false;
        });
        $this->rollbackRegistered = true;
    }
}

final class MemoryOrderTargetGateway implements OrderTargetGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $orders = [];
    /** @var array<int, array<string, mixed>> */
    public array $items = [];
    /** @var array<int, array<string, mixed>> */
    public array $addresses = [];
    /** @var array<int, array<string, mixed>> */
    public array $coupons = [];
    /** @var array<int, array<string, mixed>> */
    public array $taxRates = [];
    /** @var array<int, array<string, mixed>> */
    public array $transactions = [];
    /** @var array<int, array<string, mixed>> */
    public array $meta = [];
    /** @var list<string> */
    public array $writeOrder = [];
    public int $writeCount = 0;
    public ?string $failOn = null;
    private int $nextId = 1000;
    private bool $rollbackRegistered = false;

    public function createOrder(array $fields): int
    {
        $this->beforeWrite('order');
        $id = $this->nextId++;
        $this->orders[$id] = ['id' => $id] + $fields;
        return $id;
    }

    public function createItem(int $orderId, array $fields): int
    {
        $paymentType = (string) ($fields['payment_type'] ?? '');
        $this->beforeWrite('item:' . ($paymentType === 'fee' ? 'fee' : 'product'));
        $id = $this->nextId++;
        $this->items[$id] = ['id' => $id, 'order_id' => $orderId] + $fields;
        return $id;
    }

    public function createAddress(int $orderId, array $fields): int
    {
        $this->beforeWrite('address');
        $id = $this->nextId++;
        $this->addresses[$id] = ['id' => $id, 'order_id' => $orderId] + $fields;
        return $id;
    }

    public function createCoupon(int $orderId, array $fields): int
    {
        $this->beforeWrite('coupon');
        $id = $this->nextId++;
        $this->coupons[$id] = ['id' => $id, 'order_id' => $orderId] + $fields;
        return $id;
    }

    public function createTaxRate(int $orderId, array $fields): int
    {
        $this->beforeWrite('tax');
        $id = $this->nextId++;
        $this->taxRates[$id] = ['id' => $id, 'order_id' => $orderId] + $fields;
        return $id;
    }

    public function createTransaction(int $orderId, array $fields): int
    {
        $this->beforeWrite('transaction:' . (string) ($fields['transaction_type'] ?? ''));
        $id = $this->nextId++;
        $this->transactions[$id] = ['id' => $id, 'order_id' => $orderId] + $fields;
        return $id;
    }

    public function createMeta(int $orderId, array $fields): int
    {
        $this->beforeWrite('meta:' . (string) ($fields['meta_key'] ?? ''));
        $id = $this->nextId++;
        $this->meta[$id] = ['id' => $id, 'order_id' => $orderId] + $fields;
        return $id;
    }

    public function exists(int $orderId): bool
    {
        return isset($this->orders[$orderId]);
    }

    public function snapshot(int $orderId): array
    {
        $owned = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) $row['order_id'] === $orderId,
        ));
        return [
            'order' => $this->orders[$orderId] ?? null,
            'items' => $owned($this->items),
            'addresses' => $owned($this->addresses),
            'coupons' => $owned($this->coupons),
            'tax_rates' => $owned($this->taxRates),
            'transactions' => $owned($this->transactions),
            'meta' => $owned($this->meta),
        ];
    }

    public function fingerprintAll(): string
    {
        return CanonicalJson::fingerprint([
            $this->orders,
            $this->items,
            $this->addresses,
            $this->coupons,
            $this->taxRates,
            $this->transactions,
            $this->meta,
        ]);
    }

    private function beforeWrite(string $kind): void
    {
        if ($this->failOn === $kind) {
            throw new \RuntimeException('forced target failure: ' . $kind);
        }
        if (!$this->rollbackRegistered) {
            $before = [
                $this->orders,
                $this->items,
                $this->addresses,
                $this->coupons,
                $this->taxRates,
                $this->transactions,
                $this->meta,
                $this->writeOrder,
                $this->writeCount,
                $this->nextId,
            ];
            DatabaseTransaction::afterRollback(function () use ($before): void {
                [
                    $this->orders,
                    $this->items,
                    $this->addresses,
                    $this->coupons,
                    $this->taxRates,
                    $this->transactions,
                    $this->meta,
                    $this->writeOrder,
                    $this->writeCount,
                    $this->nextId,
                ] = $before;
                $this->rollbackRegistered = false;
            });
            DatabaseTransaction::afterCommit(function (): void {
                $this->rollbackRegistered = false;
            });
            $this->rollbackRegistered = true;
        }
        ++$this->writeCount;
        $this->writeOrder[] = $kind;
    }
}
