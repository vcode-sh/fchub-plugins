<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\FluentCRM;

use FChubWishlist\FluentCRM\ProfileSection\WishlistProfileSection;
use FluentCrm\App\Models\Subscriber;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistProfileSectionTest extends TestCase
{
    private WishlistProfileSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->section = new WishlistProfileSection();
    }

    #[Test]
    public function testRegisterAddsTwoFilters(): void
    {
        $this->section->register();

        $filters = $GLOBALS['wp_filters_registered'];
        $filterTags = array_column($filters, 'tag');

        $this->assertContains('fluentcrm_profile_sections', $filterTags);
        // Note: FluentCRM has a typo in this hook name
        $this->assertContains('fluencrm_profile_section_fchub_wishlist', $filterTags);
    }

    #[Test]
    public function testAddSectionRegistersSectionData(): void
    {
        $sections = $this->section->addSection([]);

        $this->assertArrayHasKey('fchub_wishlist', $sections);
        $this->assertSame('Wishlist', $sections['fchub_wishlist']['title']);
        $this->assertSame('route', $sections['fchub_wishlist']['handler']);
    }

    #[Test]
    public function testAddSectionPreservesExistingSections(): void
    {
        $existing = ['other_section' => ['title' => 'Other']];
        $sections = $this->section->addSection($existing);

        $this->assertArrayHasKey('other_section', $sections);
        $this->assertArrayHasKey('fchub_wishlist', $sections);
    }

    #[Test]
    public function testGetSectionShowsEmptyStateForNoUser(): void
    {
        $subscriber = new Subscriber();
        $subscriber->user_id = 0;

        $section = [];
        $result = $this->section->getSection($section, $subscriber);

        $this->assertSame('Wishlist', $result['heading']);
        $this->assertStringContainsString('not linked to a WordPress user', $result['content_html']);
    }

    #[Test]
    public function testGetSectionShowsEmptyStateForNoWishlist(): void
    {
        $subscriber = new Subscriber();
        $subscriber->user_id = 42;

        // No wishlist found
        $this->setWpdbMockRow(null);

        $section = [];
        $result = $this->section->getSection($section, $subscriber);

        $this->assertStringContainsString('No wishlist items found', $result['content_html']);
    }

    #[Test]
    public function testGetSectionShowsEmptyStateForZeroItems(): void
    {
        $subscriber = new Subscriber();
        $subscriber->user_id = 42;

        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => null,
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => '0',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        $section = [];
        $result = $this->section->getSection($section, $subscriber);

        $this->assertStringContainsString('No wishlist items found', $result['content_html']);
    }

    #[Test]
    public function testGetSectionRendersTableForLinkedUser(): void
    {
        $subscriber = new Subscriber();
        $subscriber->user_id = 42;

        // Wishlist found with items
        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => null,
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => '2',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-02-15 10:00:00',
        ]);

        // Items with product data
        $this->setWpdbMockResults([
            [
                'id'               => '10',
                'wishlist_id'      => '1',
                'product_id'       => '100',
                'variant_id'       => '200',
                'price_at_addition' => '29.99',
                'note'             => null,
                'created_at'       => '2025-01-15 10:30:00',
                'product_title'    => 'Widget Pro',
                'product_status'   => 'publish',
                'product_slug'     => 'widget-pro',
                'variant_title'    => 'Large',
                'current_price'    => '34.99',
                'variant_status'   => 'active',
                'variant_sku'      => 'WP-LG',
            ],
        ]);

        $section = [];
        $result = $this->section->getSection($section, $subscriber);

        $this->assertSame('Wishlist', $result['heading']);
        $this->assertStringContainsString('Items:', $result['content_html']);
        $this->assertStringContainsString('<table>', $result['content_html']);
        $this->assertStringContainsString('Widget Pro', $result['content_html']);
    }
}
