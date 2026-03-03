<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\FluentCRM;

use FChubWishlist\FluentCRM\Actions\AddToWishlistAction;
use FChubWishlist\Tests\Support\MockBuilder;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AddToWishlistActionTest extends TestCase
{
    private AddToWishlistAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new AddToWishlistAction();
    }

    #[Test]
    public function testBlockRegistrationData(): void
    {
        $block = $this->action->getBlock();

        $this->assertSame('FCHub Wishlist', $block['category']);
        $this->assertSame('Add to Wishlist', $block['title']);
        $this->assertArrayHasKey('settings', $block);
        $this->assertSame('', $block['settings']['product_id']);
        $this->assertSame('', $block['settings']['variant_id']);
    }

    #[Test]
    public function testBlockFieldsStructure(): void
    {
        $fields = $this->action->getBlockFields();

        $this->assertArrayHasKey('title', $fields);
        $this->assertArrayHasKey('fields', $fields);
        $this->assertArrayHasKey('product_id', $fields['fields']);
        $this->assertArrayHasKey('variant_id', $fields['fields']);
        $this->assertTrue($fields['fields']['product_id']['is_required']);
    }

    #[Test]
    public function testHandleSkipsWhenNoProductId(): void
    {
        $subscriber = MockBuilder::subscriber(['user_id' => 42]);
        $sequence = MockBuilder::sequence([
            'id'       => 1,
            'settings' => (object) ['product_id' => 0, 'variant_id' => 0],
        ]);

        $this->action->handle($subscriber, $sequence, 1, null);

        // Should have marked as skipped
        $this->assertNotEmpty($GLOBALS['fluentcrm_sequence_status_changes']);
        $change = $GLOBALS['fluentcrm_sequence_status_changes'][0];
        $this->assertSame('skipped', $change['status']);
    }

    #[Test]
    public function testHandleSkipsWhenUserIdNotResolved(): void
    {
        $subscriber = (object) [
            'id'       => 1,
            'user_id'  => 0,
            'email'    => 'nouser@example.com',
        ];

        $sequence = MockBuilder::sequence([
            'settings' => (object) ['product_id' => 100, 'variant_id' => 200],
        ]);

        // No WP user for this email
        $this->action->handle($subscriber, $sequence, 1, null);

        $this->assertNotEmpty($GLOBALS['fluentcrm_sequence_status_changes']);
        $this->assertSame('skipped', $GLOBALS['fluentcrm_sequence_status_changes'][0]['status']);
    }

    #[Test]
    public function testHandleResolvesUserAndAddsItem(): void
    {
        $this->setMockUser(42, 'user@example.com');

        $subscriber = (object) [
            'id'       => 1,
            'user_id'  => 42,
            'email'    => 'user@example.com',
        ];

        $sequence = MockBuilder::sequence([
            'settings' => (object) ['product_id' => 100, 'variant_id' => 200],
        ]);

        // Existing wishlist found by findByUserId (get_row returns this)
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

        // Item does not exist yet (get_var returns null)
        $this->setWpdbMockVar(null);

        $this->action->handle($subscriber, $sequence, 1, null);

        // item_added hook should fire
        $this->assertHookFired('fchub_wishlist/item_added');
    }

    #[Test]
    public function testHandleSkipsDuplicateItem(): void
    {
        $this->setMockUser(42, 'user@example.com');

        $subscriber = (object) [
            'id'       => 1,
            'user_id'  => 42,
            'email'    => 'user@example.com',
        ];

        $sequence = MockBuilder::sequence([
            'settings' => (object) ['product_id' => 100, 'variant_id' => 200],
        ]);

        // Wishlist exists
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

        // Item already exists
        $this->setWpdbMockVar('1');

        $this->action->handle($subscriber, $sequence, 1, null);

        // Should NOT fire item_added since it was a duplicate
        $this->assertHookNotFired('fchub_wishlist/item_added');
    }
}
