<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Subscription\Source\WooSubscriptionRecordSource;
use CartShift\Domain\Transfer\Customer\WooCustomerRecordSource;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Order\OrderRecordFactory;
use CartShift\Domain\Transfer\Package\LoadedWooTransferRecordLoader;
use CartShift\Domain\Transfer\Product\HistoricalProductDecisionResolver;
use CartShift\Domain\Transfer\Product\HistoricalProductPlaceholder;
use CartShift\Domain\Transfer\Product\ProductRecordFactory;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 4) . '/stubs/EntityMigratorStubs.php';

final class LoadedWooTransferRecordLoaderTest extends PluginTestCase
{
    public function testHistoricalRecordIsResolvedFromReviewedLineWitnessWithoutHydratingACurrentProduct(): void
    {
        $order = new LoaderHistoricalOrderFixture();
        $item = new LoaderHistoricalItemFixture();
        $GLOBALS['_cartshift_test_order_item_meta'][73]['_product_id'] = '99';
        $shape = [
            'name' => 'Deleted course', 'sku' => 'OLD-1', 'unit_total' => 2500,
            'currency' => 'PLN', 'source_created_utc' => '2026-01-02T03:04:05Z',
        ];
        $placeholder = new SourceIdentity('shop-alpha', 'product', '99');
        $resolver = new HistoricalProductDecisionResolver(
            'shop-alpha',
            TransferDecisionSet::fromArray([[
                'scope' => 'audit_finding', 'identity' => 'shop-alpha:order:41:item:73',
                'finding_code' => 'historical_product_missing', 'action' => 'approve_mapping',
                'source_fingerprint' => str_repeat('a', 64),
                'placeholder_identity' => $placeholder->canonical(),
                'placeholder_fingerprint' => HistoricalProductPlaceholder::approvalFingerprint($placeholder, $shape),
                'operator' => 'owner', 'reason' => 'Reviewed witness.', 'decided_at' => '2026-08-10T12:00:00Z',
            ]]),
        );
        $resolver->resolve($order, $item);
        $productReads = 0;
        $loader = $this->loader($resolver, static function () use (&$productReads): null {
            ++$productReads;
            return null;
        });

        $record = $loader->load($placeholder);

        self::assertNotNull($record);
        self::assertSame('[Historical] Deleted course', $record->payload['name']);
        self::assertSame(0, $productReads);
    }

    public function testVariationCannotMasqueradeAsAProductRoot(): void
    {
        $resolver = new HistoricalProductDecisionResolver('shop-alpha', TransferDecisionSet::empty());
        $loader = $this->loader($resolver, static fn (): object => new class {
            public function get_id(): int { return 13; }
            public function get_parent_id(): int { return 12; }
        });

        try {
            $loader->load(new SourceIdentity('shop-alpha', 'product', '13'));
            self::fail('A variation was accepted as a root product record.');
        } catch (SourceRecordException $exception) {
            self::assertSame('product_root_expected', $exception->reasonCode);
        }
    }

    /** @param callable(int): mixed $productReader */
    private function loader(HistoricalProductDecisionResolver $resolver, callable $productReader): LoadedWooTransferRecordLoader
    {
        return new LoadedWooTransferRecordLoader(
            'shop-alpha',
            new ProductRecordFactory(),
            new OrderRecordFactory('PLN', 'PLN', 'test-run'),
            new WooCustomerRecordSource(static fn (): array => []),
            new WooSubscriptionRecordSource(static fn (): array => []),
            $resolver,
            $productReader,
            static fn (): null => null,
        );
    }
}

final class LoaderHistoricalOrderFixture
{
    public function get_id(): int { return 41; }
    public function get_currency(): string { return 'PLN'; }
    public function get_date_created(): \DateTimeImmutable { return new \DateTimeImmutable('2026-01-02T03:04:05Z'); }
}

final class LoaderHistoricalItemFixture
{
    public function get_id(): int { return 73; }
    public function get_name(): string { return 'Deleted course'; }
    public function get_subtotal(): string { return '25.00'; }
    public function get_quantity(): int { return 1; }
    public function get_meta(string $key, bool $single = true): string { return $key === '_sku' ? 'OLD-1' : ''; }
}
