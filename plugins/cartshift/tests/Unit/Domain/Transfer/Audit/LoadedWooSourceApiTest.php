<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Audit;

use CartShift\Domain\Transfer\Audit\LoadedWooSourceApi;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 4) . '/stubs/HttpCliStubs.php';
require_once dirname(__DIR__, 4) . '/stubs/EntityMigratorStubs.php';

final class LoadedWooSourceApiTest extends PluginTestCase
{
    public function testSemanticProductEnumerationExplicitlyIncludesVariations(): void
    {
        $GLOBALS['_cartshift_test_wc_product_batches'] = [[7, 3]];

        self::assertSame([3, 7], (new LoadedWooSourceApi())->semanticProductIds());

        $call = $GLOBALS['_cartshift_test_wc_get_products_calls'][0];
        self::assertSame('ids', $call['return']);
        self::assertContains('variation', $call['type']);
        self::assertSame('ASC', $call['order']);
    }

    public function testProductNormalisationDistinguishesReadableSameSiteRemoteAndMissingDownloads(): void
    {
        $directory = sys_get_temp_dir() . '/cartshift-source-api-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $local = $directory . '/guide.pdf';
        self::assertSame(4, file_put_contents($local, 'test'));

        try {
            $GLOBALS['_cartshift_test_wc_products'][17] = new InventoryProductDouble([
                new InventoryDownloadDouble('https://source.test/uploads/guide.pdf'),
                new InventoryDownloadDouble('https://cdn.example.test/video.mp4'),
                new InventoryDownloadDouble('https://source.test/uploads/missing.pdf'),
            ]);
            $api = new LoadedWooSourceApi([
                'baseurl' => 'https://source.test/uploads',
                'basedir' => $directory,
            ]);
            $facts = $api->product(17);

            self::assertIsArray($facts);
            self::assertSame(['local', 'remote', 'missing'], $facts['downloads']);
            self::assertSame(['custom', 'wildcard'], $facts['attribute_contracts']);
            self::assertSame('parent', $facts['stock_owner']);
            self::assertSame('0', $facts['sale_price']);
            self::assertTrue($facts['sale_scheduled']);
            self::assertSame(0, $facts['grouped_child_count']);
            self::assertSame(37, $facts['sku_length']);
            self::assertSame(hash('sha256', str_repeat('S', 37)), $facts['sku_fingerprint']);
        } finally {
            unlink($local);
            rmdir($directory);
        }
    }

    public function testSubscriptionRelationshipsAreReadSeparatelyWithPinnedArgumentOrder(): void
    {
        $subscription = new InventorySubscriptionDouble();
        $GLOBALS['_cartshift_test_wcs_pages'] = [$subscription];
        $GLOBALS['_cartshift_test_options']['woocommerce_custom_orders_table_enabled'] = 'yes';
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array =>
            str_contains($query, "type = 'shop_subscription'") ? [301] : [];
        $api = new LoadedWooSourceApi();

        self::assertSame([301], $api->subscriptionCensusPage(1, 10));
        self::assertSame(0, $GLOBALS['_cartshift_test_wcs_query_count'] ?? 0);
        $facts = $api->subscription(301);

        self::assertIsArray($facts);
        self::assertSame(
            [
                'parent' => [201],
                'renewal' => [202, 203],
                'switch' => [204],
                'resubscribe' => [205],
            ],
            $facts['related_orders'],
        );
        self::assertSame(
            [
                ['ids', 'parent'],
                ['ids', 'renewal'],
                ['ids', 'switch'],
                ['ids', 'resubscribe'],
            ],
            $subscription->calls,
        );
        self::assertSame('active', $facts['status']);
        self::assertSame('stripe', $facts['source_gateway']);
        self::assertFalse($facts['requires_manual_renewal']);
        self::assertFalse($facts['has_next_payment']);
        self::assertFalse($facts['has_end']);
    }

    public function testSubscriptionCensusUsesTheAuthoritativeCptStoreWithoutWcsScopeFiltering(): void
    {
        $GLOBALS['_cartshift_test_options']['woocommerce_custom_orders_table_enabled'] = 'no';
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array =>
            str_contains($query, "post_type = 'shop_subscription'") ? [91, 73] : [];

        self::assertSame([73, 91], (new LoadedWooSourceApi())->subscriptionCensusPage(2, 25));
        self::assertSame(0, $GLOBALS['_cartshift_test_wcs_query_count'] ?? 0);
        self::assertNotEmpty(array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $entry): bool => str_contains((string) ($entry[1] ?? ''), 'FROM `wp_posts`')
                && str_contains((string) ($entry[1] ?? ''), 'LIMIT 25 OFFSET 25'),
        ));
    }

    public function testDeletedOrderLineProductUsesRawImmutableItemReference(): void
    {
        $item = new InventoryOrderItemDouble(73, 0, 'Deleted course', '25.00', 1);
        $GLOBALS['_cartshift_test_wc_orders'][9468] = new InventoryOrderDouble(9468, [$item]);
        $GLOBALS['_cartshift_test_order_item_meta'][73]['_product_id'] = '9467';

        $facts = (new LoadedWooSourceApi())->order(9468);

        self::assertIsArray($facts);
        self::assertSame([], $facts['product_ids']);
        self::assertSame(73, $facts['missing_product_refs'][0]['line_id']);
        self::assertSame(9467, $facts['missing_product_refs'][0]['product_id']);
        self::assertSame([
            'name' => 'Deleted course',
            'sku' => '',
            'unit_total' => 2500,
            'currency' => 'PLN',
            'source_created_utc' => '2026-08-01T10:00:00Z',
        ], $facts['missing_product_refs'][0]['line_shape']);
    }

    public function testDeletedOrderLineWithRetainedProductIdStillUsesHistoricalPlaceholder(): void
    {
        $item = new InventoryOrderItemDouble(74, 9467, 'Deleted course', '25.00', 1, false);
        $GLOBALS['_cartshift_test_wc_orders'][9469] = new InventoryOrderDouble(9469, [$item]);
        $GLOBALS['_cartshift_test_order_item_meta'][74]['_product_id'] = '9467';

        $facts = (new LoadedWooSourceApi())->order(9469);

        self::assertIsArray($facts);
        self::assertSame([], $facts['product_ids']);
        self::assertSame(74, $facts['missing_product_refs'][0]['line_id']);
        self::assertSame(9467, $facts['missing_product_refs'][0]['product_id']);
    }
}

final readonly class InventoryDownloadDouble
{
    public function __construct(private string $file) {}

    public function get_file(): string
    {
        return $this->file;
    }
}

final readonly class InventoryAttributeDouble
{
    public function is_taxonomy(): bool
    {
        return false;
    }

    /** @return list<string> */
    public function get_options(): array
    {
        return ['Small', ''];
    }
}

final readonly class InventoryProductDouble
{
    /** @param list<InventoryDownloadDouble> $downloads */
    public function __construct(private array $downloads) {}

    public function get_id(): int { return 17; }
    public function get_type(): string { return 'variable'; }
    public function get_status(): string { return 'future'; }
    public function get_parent_id(): int { return 0; }
    public function get_date_modified(): \DateTimeImmutable { return new \DateTimeImmutable('2026-08-10 10:00:00 UTC'); }
    public function get_attributes(): array { return [new InventoryAttributeDouble()]; }
    public function get_variation_attributes(): never { throw new \RuntimeException('cache-mutating variation getter called'); }
    public function get_downloads(): array { return $this->downloads; }
    public function get_image_id(): int { return 9; }
    public function get_gallery_image_ids(): array { return [10]; }
    public function get_regular_price(string $context = 'view'): string { return '10'; }
    public function get_sale_price(string $context = 'view'): string { return '0'; }
    public function get_date_on_sale_from(): \DateTimeImmutable { return new \DateTimeImmutable('2026-08-01 UTC'); }
    public function get_date_on_sale_to(): ?\DateTimeImmutable { return null; }
    public function get_tax_class(): string { return 'reduced-rate'; }
    public function get_manage_stock(): string { return 'parent'; }
    public function get_backorders(): string { return 'notify'; }
    public function get_catalog_visibility(): string { return 'hidden'; }
    public function get_featured(): bool { return true; }
    public function get_menu_order(): int { return 4; }
    public function get_purchase_note(): string { return 'Private note'; }
    public function get_reviews_allowed(): bool { return true; }
    public function get_review_count(): int { return 2; }
    public function get_average_rating(): string { return '4.5'; }
    public function get_total_sales(): int { return 8; }
    public function get_global_unique_id(): string { return '123456789'; }
    public function get_upsell_ids(): array { return [18]; }
    public function get_cross_sell_ids(): array { return [19]; }
    public function get_children(): never { throw new \RuntimeException('cache-mutating children getter called'); }
    public function get_product_url(): string { return ''; }
    public function get_button_text(): string { return ''; }
    public function get_sku(): string { return str_repeat('S', 37); }
}

final class InventorySubscriptionDouble
{
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    public function get_id(): int
    {
        return 301;
    }

    public function get_date_modified(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-10 10:00:00 UTC');
    }

    public function get_status(): string { return 'active'; }
    public function get_payment_method(): string { return 'stripe'; }
    public function get_requires_manual_renewal(): bool { return false; }
    public function get_date(string $type): string { return ''; }

    /** @return list<int> */
    public function get_related_orders(string $returnFields = 'ids', string $relationship = 'any'): array
    {
        $this->calls[] = [$returnFields, $relationship];

        return match ($relationship) {
            'parent' => [201],
            'renewal' => [203, 202],
            'switch' => [204],
            'resubscribe' => [205],
            default => [],
        };
    }
}

final readonly class InventoryOrderItemDouble
{
    public function __construct(
        private int $id,
        private int $productId,
        private string $name,
        private string $subtotal,
        private int $quantity,
        private bool $productAvailable = true,
    ) {}

    public function get_id(): int { return $this->id; }
    public function get_product_id(): int { return $this->productId; }
    public function get_name(): string { return $this->name; }
    public function get_subtotal(): string { return $this->subtotal; }
    public function get_quantity(): int { return $this->quantity; }
    public function get_meta(string $key, bool $single = true): string { return ''; }
    public function get_product(): ?object { return $this->productAvailable ? new \stdClass() : null; }
}

final readonly class InventoryOrderDouble
{
    /** @param list<InventoryOrderItemDouble> $items */
    public function __construct(private int $id, private array $items) {}

    public function get_id(): int { return $this->id; }
    public function get_items(string $type): array { return $type === 'line_item' ? $this->items : []; }
    public function get_total(): string { return '10'; }
    public function get_total_refunded(): string { return '0'; }
    public function get_date_modified(): \DateTimeImmutable { return new \DateTimeImmutable('2026-08-10 10:00:00 UTC'); }
    public function get_status(): string { return 'completed'; }
    public function get_currency(): string { return 'PLN'; }
    public function get_date_created(): \DateTimeImmutable { return new \DateTimeImmutable('2026-08-01 10:00:00 UTC'); }
}
