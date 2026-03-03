<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\FluentCRM;

use FChubWishlist\FluentCRM\Triggers\ItemRemovedTrigger;
use FChubWishlist\Tests\Support\MockBuilder;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ItemRemovedTriggerTest extends TestCase
{
    private ItemRemovedTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new ItemRemovedTrigger();
    }

    #[Test]
    public function testTriggerRegistration(): void
    {
        $triggerData = $this->trigger->getTrigger();

        $this->assertSame('FCHub Wishlist', $triggerData['category']);
        $this->assertSame('Item Removed from Wishlist', $triggerData['label']);
    }

    #[Test]
    public function testFunnelConditionDefaultsIncludeUpdateType(): void
    {
        $funnel = MockBuilder::funnel();
        $defaults = $this->trigger->getFunnelConditionDefaults($funnel);

        $this->assertSame('update', $defaults['update_type']);
        $this->assertSame([], $defaults['product_ids']);
        $this->assertSame('no', $defaults['run_multiple']);
    }

    #[Test]
    public function testHandleSkipsGuestUser(): void
    {
        $funnel = MockBuilder::funnel();

        $this->trigger->handle($funnel, [0, 100, 200, 1]);

        $this->assertEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }

    #[Test]
    public function testHandleSkipsWhenUserNotFound(): void
    {
        $funnel = MockBuilder::funnel();

        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        $this->assertEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }

    #[Test]
    public function testHandleProcessesValidUser(): void
    {
        $this->setMockUser(42, 'user@example.com');

        $funnel = MockBuilder::funnel([
            'settings' => (object) ['subscription_status' => 'subscribed'],
            'conditions' => (object) [
                'product_ids'  => [],
                'run_multiple' => 'no',
            ],
        ]);

        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        $this->assertNotEmpty($GLOBALS['fluentcrm_funnel_sequences']);
        $sequence = $GLOBALS['fluentcrm_funnel_sequences'][0];
        $this->assertSame('fchub_wishlist/item_removed', $sequence['context']['source_trigger_name']);
    }

    #[Test]
    public function testHandleRespectsProductFilter(): void
    {
        $this->setMockUser(42, 'user@example.com');

        $funnel = MockBuilder::funnel([
            'settings' => (object) ['subscription_status' => 'subscribed'],
            'conditions' => (object) [
                'product_ids'  => [200], // Only product 200
                'run_multiple' => 'no',
            ],
        ]);

        // Product 100 not in filter
        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        $this->assertEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }

    #[Test]
    public function testHandleRunMultipleBehaviour(): void
    {
        $this->setMockUser(42, 'user@example.com');

        $subscriber = MockBuilder::subscriber(['id' => 5, 'email' => 'user@example.com']);
        $GLOBALS['fluentcrm_mock_subscriber'] = $subscriber;
        $GLOBALS['fluentcrm_mock_already_in_funnel'] = true;

        $funnel = MockBuilder::funnel([
            'id' => 10,
            'settings' => (object) ['subscription_status' => 'subscribed'],
            'conditions' => (object) [
                'product_ids'  => [],
                'run_multiple' => 'yes',
            ],
        ]);

        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        $this->assertNotEmpty($GLOBALS['fluentcrm_removed_from_funnel']);
        $this->assertNotEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }

    #[Test]
    public function testHandleBlocksAlreadyInFunnelWithoutRunMultiple(): void
    {
        $this->setMockUser(42, 'user@example.com');

        $subscriber = MockBuilder::subscriber(['id' => 5, 'email' => 'user@example.com']);
        $GLOBALS['fluentcrm_mock_subscriber'] = $subscriber;
        $GLOBALS['fluentcrm_mock_already_in_funnel'] = true;

        $funnel = MockBuilder::funnel([
            'settings' => (object) ['subscription_status' => 'subscribed'],
            'conditions' => (object) [
                'product_ids'  => [],
                'run_multiple' => 'no',
            ],
        ]);

        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        $this->assertEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }
}
