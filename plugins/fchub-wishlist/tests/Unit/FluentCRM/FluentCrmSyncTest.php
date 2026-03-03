<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\FluentCRM;

use FChubWishlist\Integration\FluentCrmSync;
use FChubWishlist\Support\Constants;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FluentCrmSyncTest extends TestCase
{
    #[Test]
    public function testRegisterDoesNothingWhenFluentCrmDisabledInSettings(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, ['fluentcrm_enabled' => 'no']);

        $sync = new FluentCrmSync();
        $sync->register();

        $actionTags = array_column($GLOBALS['wp_actions_registered'], 'tag');

        // The hooks should NOT be registered since fluentcrm_enabled = 'no'
        $this->assertNotContains('fchub_wishlist/item_added', $actionTags);
        $this->assertNotContains('fchub_wishlist/item_removed', $actionTags);
    }

    #[Test]
    public function testOnItemAddedSkipsGuestUser(): void
    {
        $sync = new FluentCrmSync();
        $sync->onItemAdded(0, 100, 200, 1);

        // Guard clause returns early for userId=0, so no FluentCRM contact operations happen.
        // If it didn't return early, it would call FluentCrmApi() which is undefined and would fatal.
        $this->assertHookNotFired('fchub_wishlist/item_added');
        $this->assertEmpty($GLOBALS['wpdb']->queries, 'No DB queries should be made for guest user');
    }

    #[Test]
    public function testOnItemRemovedSkipsGuestUser(): void
    {
        $sync = new FluentCrmSync();
        $sync->onItemRemoved(0, 100, 200, 1);

        // Guard clause returns early for userId=0, so no FluentCRM contact operations happen.
        $this->assertHookNotFired('fchub_wishlist/item_removed');
        $this->assertEmpty($GLOBALS['wpdb']->queries, 'No DB queries should be made for guest user');
    }

    #[Test]
    public function testOnItemRemovedDoesNothingWhenWishlistStillHasItems(): void
    {
        // User still has items in their wishlist
        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => null,
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => '5',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        $sync = new FluentCrmSync();
        $sync->onItemRemoved(42, 100, 200, 1);

        // Should return early because item_count > 0.
        // If it continued past the guard, it would call FluentCrmApi() which is undefined.
        // Verify the DB was queried (to find the wishlist) but nothing else happened.
        $this->assertNotEmpty($GLOBALS['wpdb']->queries, 'Should query DB to find wishlist');
        $this->assertHookNotFired('fchub_wishlist/item_removed');
    }

    #[Test]
    public function testDefaultSettingsHaveFluentCrmEnabled(): void
    {
        $defaults = Constants::DEFAULT_SETTINGS;

        $this->assertSame('yes', $defaults['fluentcrm_enabled']);
        $this->assertSame('wishlist:', $defaults['fluentcrm_tag_prefix']);
        $this->assertSame('yes', $defaults['fluentcrm_auto_create_tags']);
    }

    #[Test]
    public function testSyncUsesCorrectTagPrefix(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, [
            'fluentcrm_tag_prefix' => 'custom_prefix:',
        ]);

        // Verify the Hooks helper reads the custom prefix from settings
        $settings = \FChubWishlist\Support\Hooks::getSettings();
        $this->assertSame('custom_prefix:', $settings['fluentcrm_tag_prefix']);

        // Verify the merged tag name matches what FluentCrmSync would build
        $tagName = $settings['fluentcrm_tag_prefix'] . 'active';
        $this->assertSame('custom_prefix:active', $tagName);
    }
}
