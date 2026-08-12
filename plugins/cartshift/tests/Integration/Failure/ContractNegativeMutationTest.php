<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Domain\Transfer\Audit\WooSourceApi;
use CartShift\Domain\Transfer\Audit\WooSourceInventoryReader;
use CartShift\Domain\Transfer\Audit\WooStorageIntegrityReader;
use CartShift\Domain\Transfer\Product\ProductAssessmentContext;
use CartShift\Domain\Transfer\Product\ProductFieldDecisionSet;
use CartShift\Domain\Transfer\Product\ProductFieldDisposition;
use CartShift\Domain\Transfer\Product\ProductRecordFactory;
use CartShift\Domain\Transfer\Product\SimpleVariationPlanner;
use CartShift\Domain\Transfer\Product\StockOwnership;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\Domain\Transfer\Product\ProductAssessmentFixture;

final class ContractNegativeMutationTest extends FailureTestCase
{
    public function testCensusRemainsAuthoritativeWhenLookupMembershipLies(): void
    {
        $report = $this->inventory(new MutantSourceApi(
            productIds: [10],
            semanticIds: [10],
            lookupIds: [],
            products: [10 => $this->facts('publish')],
        ));

        self::assertSame(1, $report->counts['product_census_ids']);
        self::assertSame(1, $report->counts['products_considered']);
        self::assertSame(1, $report->counts['products_exported']);
        self::assertContains('product_lookup_missing', array_column($report->blockers, 'code'));
    }

    public function testSelectedHydrationFailureCannotAdvanceTheCursorAsSuccess(): void
    {
        $report = $this->inventory(new MutantSourceApi(
            productIds: [10],
            semanticIds: [10],
            lookupIds: [10],
            products: [],
        ));

        self::assertSame(1, $report->counts['products_considered']);
        self::assertSame(1, $report->counts['products_blocked_hydration']);
        self::assertSame(0, $report->counts['products_exported']);
        self::assertContains('product_hydration_failed', array_column($report->blockers, 'code'));
    }

    public function testNonDefaultStatusCannotDisappearFromCapabilityEvidence(): void
    {
        $report = $this->inventory(new MutantSourceApi(
            productIds: [10],
            semanticIds: [10],
            lookupIds: [10],
            products: [10 => $this->facts('training-review')],
        ));

        self::assertSame(1, $report->capabilities['product_statuses']['training-review']);
    }

    public function testExplicitZeroPriceCannotFallBackToTheTruthyRegularPrice(): void
    {
        $GLOBALS['_cartshift_test_wc_currency'] = 'USD';
        $record = (new ProductRecordFactory())->fromWooProduct(new MutantWooProduct([
            'get_id' => 42,
            'get_type' => 'simple',
            'get_status' => 'publish',
            'get_name' => 'Zero price contract',
            'get_slug' => 'zero-price-contract',
            'get_date_created' => new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            'get_price' => '0',
            'get_regular_price' => '29.00',
            'get_sale_price' => '',
            'get_data' => [],
        ]), 'contract-source');

        self::assertSame(0, $record->variations[0]->price->activePrice);
        self::assertSame(2900, $record->variations[0]->price->regularPrice);
    }

    public function testParentStockSentinelCannotBeBooleanCoercedIntoChildOwnership(): void
    {
        $GLOBALS['_cartshift_test_wc_currency'] = 'USD';
        $parent = new MutantWooProduct([
            'get_id' => 42,
            'get_type' => 'variable',
            'get_status' => 'publish',
            'get_name' => 'Parent stock contract',
            'get_slug' => 'parent-stock-contract',
            'get_date_created' => new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            'get_children' => [101],
            'get_manage_stock' => true,
            'get_stock_quantity' => 98,
            'get_data' => [],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][101] = new MutantWooProduct([
            'get_id' => 101,
            'get_parent_id' => 42,
            'get_type' => 'variation',
            'get_manage_stock' => 'parent',
            'get_stock_quantity' => 3,
            'get_date_created' => new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            'get_data' => [],
        ]);

        $variation = (new ProductRecordFactory())->fromWooProduct($parent, 'contract-source')->variations[0];

        self::assertSame(StockOwnership::Parent, $variation->stock->ownership);
        self::assertSame(98, $variation->stock->quantity);
        self::assertNotSame(3, $variation->stock->quantity);
    }

    public function testProvenSimpleVariationTypeCannotBeSwitchedToAdvancedVariations(): void
    {
        $product = ProductAssessmentFixture::product([
            'productType' => 'variable',
            'variations' => [ProductAssessmentFixture::variation(
                ProductAssessmentFixture::identity('42'),
                ['identity' => ProductAssessmentFixture::identity('42:variation:101')],
            )],
        ]);
        $plans = (new SimpleVariationPlanner())->plan($product, new ProductAssessmentContext(
            ['standard', 'none'],
            [],
            ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            targetShippingClasses: ['none' => 0],
        ));

        self::assertSame('simple_variations', $plans[0]->targetFields['variation_type']);
        self::assertNotSame('advanced_variations', $plans[0]->targetFields['variation_type']);
    }

    private function inventory(MutantSourceApi $api): \CartShift\Domain\Transfer\Audit\SourceInventoryReport
    {
        return (new WooSourceInventoryReader(
            $api,
            new class implements WooStorageIntegrityReader {
                public function inspect(string $sourceKey): array
                {
                    return [];
                }
            },
            'contract-runtime',
            10,
        ))->inspect(new TransferSelection(
            'contract-source',
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
        ));
    }

    /** @return array<string, mixed> */
    private function facts(string $status): array
    {
        return [
            'type' => 'simple',
            'status' => $status,
            'modified_gmt' => '2026-08-10T12:00:00Z',
            'attributes' => [],
            'prices' => ['active' => '10.00', 'regular' => '10.00', 'sale' => ''],
            'tax_status' => 'taxable',
            'tax_class' => '',
            'stock' => ['ownership' => 'none'],
            'downloads' => [],
            'media' => [],
            'catalogue' => [],
            'relationships' => [],
        ];
    }
}

final readonly class MutantSourceApi implements WooSourceApi
{
    /**
     * @param list<int> $productIds
     * @param list<int> $semanticIds
     * @param list<int> $lookupIds
     * @param array<int, array<string, mixed>> $products
     */
    public function __construct(
        private array $productIds,
        private array $semanticIds,
        private array $lookupIds,
        private array $products,
    ) {
    }

    public function productCensusPage(int $page, int $limit): array
    {
        return $page === 1 ? $this->productIds : [];
    }

    public function semanticProductIds(): array
    {
        return $this->semanticIds;
    }

    public function lookupProductIds(): array
    {
        return $this->lookupIds;
    }

    public function product(int $id): ?array
    {
        return $this->products[$id] ?? null;
    }

    public function orderCensusPage(int $page, int $limit): array
    {
        return [];
    }

    public function order(int $id): ?array
    {
        return null;
    }

    public function subscriptionCensusPage(int $page, int $limit): array
    {
        return [];
    }

    public function subscription(int $id): ?array
    {
        return null;
    }
}

final readonly class MutantWooProduct
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function get_id(): int
    {
        return (int) ($this->values['get_id'] ?? 0);
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'get_meta') {
            return '';
        }
        $defaults = [
            'get_attributes' => [], 'get_default_attributes' => [], 'get_children' => [], 'get_downloads' => [],
            'get_gallery_image_ids' => [], 'get_category_ids' => [], 'get_tag_ids' => [], 'get_upsell_ids' => [],
            'get_cross_sell_ids' => [], 'get_price' => '', 'get_regular_price' => '', 'get_sale_price' => '',
            'get_tax_status' => 'taxable', 'get_tax_class' => '', 'get_manage_stock' => false,
            'get_stock_quantity' => null, 'get_stock_status' => 'instock', 'get_backorders' => 'no',
            'is_sold_individually' => false, 'get_low_stock_amount' => null, 'is_virtual' => false,
            'is_downloadable' => false, 'get_image_id' => 0, 'get_download_limit' => -1,
            'get_download_expiry' => -1, 'get_date_modified' => null, 'get_date_on_sale_from' => null,
            'get_date_on_sale_to' => null, 'get_menu_order' => 0, 'is_featured' => false,
            'get_catalog_visibility' => 'visible', 'get_purchase_note' => '', 'get_reviews_allowed' => false,
            'get_review_count' => 0, 'get_average_rating' => '0', 'get_total_sales' => 0,
            'get_rating_counts' => [], 'get_global_unique_id' => '', 'get_description' => '',
            'get_short_description' => '', 'get_sku' => '', 'get_weight' => '', 'get_length' => '',
            'get_width' => '', 'get_height' => '', 'get_cogs_value' => null, 'get_parent_id' => 0,
            'get_data' => [], 'get_status' => 'publish', 'get_type' => 'simple', 'get_name' => 'Contract',
            'get_slug' => 'contract', 'get_date_created' => new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        ];
        return $this->values[$name] ?? $defaults[$name] ?? null;
    }
}
