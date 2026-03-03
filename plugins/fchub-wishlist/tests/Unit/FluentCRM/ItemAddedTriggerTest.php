<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\FluentCRM;

use FChubWishlist\FluentCRM\Triggers\ItemAddedTrigger;
use FChubWishlist\Tests\Support\MockBuilder;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ItemAddedTriggerTest extends TestCase
{
    private ItemAddedTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = new ItemAddedTrigger();
    }

    #[Test]
    public function testTriggerRegistrationDetails(): void
    {
        $triggerData = $this->trigger->getTrigger();

        $this->assertSame('FCHub Wishlist', $triggerData['category']);
        $this->assertSame('Item Added to Wishlist', $triggerData['label']);
        $this->assertArrayHasKey('description', $triggerData);
    }

    #[Test]
    public function testFunnelSettingsDefaults(): void
    {
        $defaults = $this->trigger->getFunnelSettingsDefaults();

        $this->assertSame('subscribed', $defaults['subscription_status']);
    }

    #[Test]
    public function testFunnelConditionDefaults(): void
    {
        $funnel = MockBuilder::funnel();
        $defaults = $this->trigger->getFunnelConditionDefaults($funnel);

        $this->assertSame([], $defaults['product_ids']);
        $this->assertSame('update', $defaults['update_type']);
        $this->assertSame('no', $defaults['run_multiple']);
    }

    #[Test]
    public function testSettingsFieldsStructure(): void
    {
        $funnel = MockBuilder::funnel();
        $fields = $this->trigger->getSettingsFields($funnel);

        $this->assertArrayHasKey('title', $fields);
        $this->assertArrayHasKey('fields', $fields);
        $this->assertArrayHasKey('subscription_status', $fields['fields']);
    }

    #[Test]
    public function testConditionFieldsStructure(): void
    {
        $funnel = MockBuilder::funnel();
        $fields = $this->trigger->getConditionFields($funnel);

        $this->assertArrayHasKey('update_type', $fields);
        $this->assertArrayHasKey('product_ids', $fields);
        $this->assertArrayHasKey('run_multiple', $fields);
    }

    #[Test]
    public function testHandleSkipsGuestUser(): void
    {
        $funnel = MockBuilder::funnel();

        // userId = 0 means guest
        $this->trigger->handle($funnel, [0, 100, 200, 1]);

        // No funnel sequence should have been started
        $this->assertEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }

    #[Test]
    public function testHandleSkipsWhenUserNotFound(): void
    {
        $funnel = MockBuilder::funnel();

        // userId = 42 but no mock user set
        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        $this->assertEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }

    #[Test]
    public function testHandleProcessesValidUser(): void
    {
        $user = $this->setMockUser(42, 'user@example.com');
        $funnel = MockBuilder::funnel([
            'settings' => (object) ['subscription_status' => 'subscribed'],
            'conditions' => (object) [
                'product_ids' => [],
                'update_type' => 'update',
                'run_multiple' => 'no',
            ],
        ]);

        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        $this->assertNotEmpty($GLOBALS['fluentcrm_funnel_sequences']);
        $sequence = $GLOBALS['fluentcrm_funnel_sequences'][0];
        $this->assertSame('fchub_wishlist/item_added', $sequence['context']['source_trigger_name']);
        $this->assertSame(100, $sequence['context']['source_ref_id']);
    }

    #[Test]
    public function testHandleRespectsProductFilter(): void
    {
        $user = $this->setMockUser(42, 'user@example.com');
        $funnel = MockBuilder::funnel([
            'settings' => (object) ['subscription_status' => 'subscribed'],
            'conditions' => (object) [
                'product_ids'  => [200, 300], // Only triggers for product 200 and 300
                'update_type'  => 'update',
                'run_multiple' => 'no',
            ],
        ]);

        // Product 100 is not in the filter list
        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        $this->assertEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }

    #[Test]
    public function testHandleSkipAllIfExistCondition(): void
    {
        $user = $this->setMockUser(42, 'user@example.com');

        // Set a subscriber to exist
        $subscriber = MockBuilder::subscriber(['id' => 1, 'email' => 'user@example.com']);
        $GLOBALS['fluentcrm_mock_subscriber'] = $subscriber;

        $funnel = MockBuilder::funnel([
            'settings' => (object) ['subscription_status' => 'subscribed'],
            'conditions' => (object) [
                'product_ids'  => [],
                'update_type'  => 'skip_all_if_exist',
                'run_multiple' => 'no',
            ],
        ]);

        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        $this->assertEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }

    #[Test]
    public function testHandleRunMultipleRemovesAndRestarts(): void
    {
        $user = $this->setMockUser(42, 'user@example.com');

        $subscriber = MockBuilder::subscriber(['id' => 5, 'email' => 'user@example.com']);
        $GLOBALS['fluentcrm_mock_subscriber'] = $subscriber;
        $GLOBALS['fluentcrm_mock_already_in_funnel'] = true;

        $funnel = MockBuilder::funnel([
            'id' => 10,
            'settings' => (object) ['subscription_status' => 'subscribed'],
            'conditions' => (object) [
                'product_ids'  => [],
                'update_type'  => 'update',
                'run_multiple' => 'yes',
            ],
        ]);

        $this->trigger->handle($funnel, [42, 100, 200, 1]);

        // Should have removed subscriber from funnel first
        $this->assertNotEmpty($GLOBALS['fluentcrm_removed_from_funnel']);
        $removal = $GLOBALS['fluentcrm_removed_from_funnel'][0];
        $this->assertSame(10, $removal['funnel_id']);
        $this->assertSame([5], $removal['subscriber_ids']);

        // Should have started a new funnel sequence
        $this->assertNotEmpty($GLOBALS['fluentcrm_funnel_sequences']);
    }
}
