<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Product\HistoricalProductDecisionResolver;
use CartShift\Domain\Transfer\Product\HistoricalProductPlaceholder;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 4) . '/stubs/EntityMigratorStubs.php';

final class HistoricalProductDecisionResolverTest extends PluginTestCase
{
    public function testExactOrderItemDecisionCreatesTheOnlyApprovedInertProductRecord(): void
    {
        $order = new HistoricalOrderFixture(41);
        $item = new HistoricalItemFixture(73, 'Deleted course', '25.00', 1, 'OLD-1');
        $GLOBALS['_cartshift_test_order_item_meta'][73]['_product_id'] = '99';
        $identity = new SourceIdentity('shop-alpha', 'product', '99');
        $shape = $this->shape();
        $resolver = new HistoricalProductDecisionResolver(
            'shop-alpha',
            $this->decisions($identity, $shape),
        );

        self::assertSame($identity->canonical(), $resolver->resolve($order, $item)->canonical());
        $records = $resolver->records();
        self::assertCount(1, $records);
        self::assertSame($identity->canonical(), $records[0]->identity->canonical());
        self::assertSame(
            HistoricalProductPlaceholder::approvalFingerprint($identity, $shape),
            $records[0]->payload['approved_meta']['historical_line_shape_fingerprint'],
        );
    }

    public function testChangedImmutableLineShapeInvalidatesApproval(): void
    {
        $order = new HistoricalOrderFixture(41);
        $GLOBALS['_cartshift_test_order_item_meta'][73]['_product_id'] = '99';
        $identity = new SourceIdentity('shop-alpha', 'product', '99');
        $resolver = new HistoricalProductDecisionResolver(
            'shop-alpha',
            $this->decisions($identity, $this->shape()),
        );

        try {
            $resolver->resolve($order, new HistoricalItemFixture(73, 'Deleted course', '26.00', 1, 'OLD-1'));
            self::fail('A changed historical line was accepted under stale approval.');
        } catch (SourceRecordException $exception) {
            self::assertSame('historical_product_missing', $exception->reasonCode);
        }
    }

    /** @param array<string, mixed> $shape */
    private function decisions(SourceIdentity $placeholder, array $shape): TransferDecisionSet
    {
        return TransferDecisionSet::fromArray([[
            'scope' => 'audit_finding',
            'identity' => 'shop-alpha:order:41:item:73',
            'finding_code' => 'historical_product_missing',
            'action' => 'approve_mapping',
            'source_fingerprint' => str_repeat('a', 64),
            'placeholder_identity' => $placeholder->canonical(),
            'placeholder_fingerprint' => HistoricalProductPlaceholder::approvalFingerprint($placeholder, $shape),
            'operator' => 'owner',
            'reason' => 'Immutable deleted product provenance reviewed.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
    }

    /** @return array{name:string,sku:string,unit_total:int,currency:string,source_created_utc:string} */
    private function shape(): array
    {
        return [
            'name' => 'Deleted course',
            'sku' => 'OLD-1',
            'unit_total' => 2500,
            'currency' => 'PLN',
            'source_created_utc' => '2026-01-02T03:04:05Z',
        ];
    }
}

final class HistoricalOrderFixture
{
    public function __construct(private readonly int $id) {}
    public function get_id(): int { return $this->id; }
    public function get_currency(): string { return 'PLN'; }
    public function get_date_created(): \DateTimeImmutable { return new \DateTimeImmutable('2026-01-02T03:04:05Z'); }
}

final class HistoricalItemFixture
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $subtotal,
        private readonly int $quantity,
        private readonly string $sku,
    ) {}
    public function get_id(): int { return $this->id; }
    public function get_name(): string { return $this->name; }
    public function get_subtotal(): string { return $this->subtotal; }
    public function get_quantity(): int { return $this->quantity; }
    public function get_meta(string $key, bool $single = true): string { return $key === '_sku' ? $this->sku : ''; }
}
