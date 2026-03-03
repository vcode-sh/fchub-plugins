<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\GDPR;

use FChubWishlist\GDPR\PersonalDataHandler;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PersonalDataHandlerTest extends TestCase
{
    #[Test]
    public function testRegisterAddsExporterAndEraserFilters(): void
    {
        PersonalDataHandler::register();

        $filters = $GLOBALS['wp_filters_registered'];
        $filterTags = array_column($filters, 'tag');

        $this->assertContains('wp_privacy_personal_data_exporters', $filterTags);
        $this->assertContains('wp_privacy_personal_data_erasers', $filterTags);
    }

    #[Test]
    public function testRegisterExporterAddsEntry(): void
    {
        $exporters = PersonalDataHandler::registerExporter([]);

        $this->assertArrayHasKey('fchub-wishlist', $exporters);
        $this->assertSame('FCHub Wishlist Data', $exporters['fchub-wishlist']['exporter_friendly_name']);
        $this->assertIsCallable($exporters['fchub-wishlist']['callback']);
    }

    #[Test]
    public function testRegisterEraserAddsEntry(): void
    {
        $erasers = PersonalDataHandler::registerEraser([]);

        $this->assertArrayHasKey('fchub-wishlist', $erasers);
        $this->assertSame('FCHub Wishlist Data', $erasers['fchub-wishlist']['eraser_friendly_name']);
        $this->assertIsCallable($erasers['fchub-wishlist']['callback']);
    }

    #[Test]
    public function testExportReturnsEmptyForUnknownUser(): void
    {
        // get_user_by will return null for unknown email
        $result = PersonalDataHandler::exportPersonalData('unknown@example.com');

        $this->assertSame([], $result['data']);
        $this->assertTrue($result['done']);
    }

    #[Test]
    public function testExportReturnsEmptyForUserWithNoWishlist(): void
    {
        $this->setMockUser(42, 'user@example.com');
        $this->setWpdbMockRow(null); // No wishlist found

        $result = PersonalDataHandler::exportPersonalData('user@example.com');

        $this->assertSame([], $result['data']);
        $this->assertTrue($result['done']);
    }

    #[Test]
    public function testExportReturnsFormattedItemData(): void
    {
        $this->setMockUser(42, 'user@example.com');

        // First query: find wishlist by user ID
        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => null,
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => '1',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        // Second query: get items with product data
        $this->setWpdbMockResults([
            [
                'id'               => '10',
                'wishlist_id'      => '1',
                'product_id'       => '100',
                'variant_id'       => '200',
                'price_at_addition' => '29.99',
                'note'             => null,
                'created_at'       => '2025-01-15 10:30:00',
                'product_title'    => 'Premium Widget',
                'product_status'   => 'publish',
                'product_slug'     => 'premium-widget',
                'variant_title'    => 'Large',
                'current_price'    => '34.99',
                'variant_status'   => 'active',
                'variant_sku'      => 'PW-LG',
            ],
        ]);

        $result = PersonalDataHandler::exportPersonalData('user@example.com');

        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['data']);

        $item = $result['data'][0];
        $this->assertSame('fchub-wishlist', $item['group_id']);
        $this->assertSame('Wishlist', $item['group_label']);
        $this->assertSame('wishlist-item-10', $item['item_id']);

        // Check exported fields
        $dataMap = [];
        foreach ($item['data'] as $field) {
            $dataMap[$field['name']] = $field['value'];
        }

        $this->assertSame('Premium Widget', $dataMap['Product']);
        $this->assertSame('Large', $dataMap['Variant']);
        $this->assertSame('29.99', $dataMap['Price at Addition']);
        $this->assertSame('2025-01-15 10:30:00', $dataMap['Added On']);
    }

    #[Test]
    public function testEraseReturnsZeroForUnknownUser(): void
    {
        $result = PersonalDataHandler::erasePersonalData('unknown@example.com');

        $this->assertSame(0, $result['items_removed']);
        $this->assertSame(0, $result['items_retained']);
        $this->assertTrue($result['done']);
    }

    #[Test]
    public function testEraseReturnsZeroForUserWithNoWishlist(): void
    {
        $this->setMockUser(42, 'user@example.com');
        $this->setWpdbMockRow(null);

        $result = PersonalDataHandler::erasePersonalData('user@example.com');

        $this->assertSame(0, $result['items_removed']);
        $this->assertTrue($result['done']);
    }

    #[Test]
    public function testEraseDeletesItemsAndWishlist(): void
    {
        $this->setMockUser(42, 'user@example.com');

        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => null,
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => '3',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        // deleteByWishlistId query result (3 items deleted)
        $GLOBALS['wpdb_mock_query_result'] = 3;

        $result = PersonalDataHandler::erasePersonalData('user@example.com');

        // 3 items + 1 wishlist record = 4
        $this->assertSame(4, $result['items_removed']);
        $this->assertSame(0, $result['items_retained']);
        $this->assertTrue($result['done']);
    }

    #[Test]
    public function testExportHandlesDeletedProductGracefully(): void
    {
        $this->setMockUser(42, 'user@example.com');

        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => null,
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => '1',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        $this->setWpdbMockResults([
            [
                'id'               => '10',
                'wishlist_id'      => '1',
                'product_id'       => '100',
                'variant_id'       => '0',
                'price_at_addition' => '0',
                'note'             => null,
                'created_at'       => '2025-01-01 00:00:00',
                'product_title'    => null, // Deleted product
                'product_status'   => null,
                'product_slug'     => null,
                'variant_title'    => null,
                'current_price'    => null,
                'variant_status'   => null,
                'variant_sku'      => null,
            ],
        ]);

        $result = PersonalDataHandler::exportPersonalData('user@example.com');

        $this->assertCount(1, $result['data']);
        $dataMap = [];
        foreach ($result['data'][0]['data'] as $field) {
            $dataMap[$field['name']] = $field['value'];
        }
        $this->assertSame('(Deleted product)', $dataMap['Product']);
        $this->assertSame('Default', $dataMap['Variant']);
    }
}
