<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Bootstrap;

use FChubWishlist\Bootstrap\Modules\FluentCrmModule;
use FChubWishlist\Bootstrap\Plugin;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DependencyPathTest extends TestCase
{
    #[Test]
    public function testFluentCrmModuleRegistersHooksWhenAvailable(): void
    {
        $module = new FluentCrmModule();
        $module->register();

        $filters = $GLOBALS['wp_filters_registered'] ?? [];
        $found = false;
        foreach ($filters as $filter) {
            if (str_contains($filter['tag'] ?? '', 'fluentcrm_ajax_options_fchub_wishlist')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'FluentCRM ajax options filter should be registered when FLUENTCRM is defined.');
    }

    #[Test]
    public function testFluentCrmModuleRegistersAutomationConditions(): void
    {
        $module = new FluentCrmModule();
        $module->register();

        $filters = $GLOBALS['wp_filters_registered'] ?? [];
        $found = false;
        foreach ($filters as $filter) {
            if (str_contains($filter['tag'] ?? '', 'fluentcrm_automation_condition_groups')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Automation condition groups filter should be registered.');
    }

    #[Test]
    public function testAddAutomationConditionsAddsWishlistGroup(): void
    {
        $module = new FluentCrmModule();
        $result = $module->addAutomationConditions([], null);

        $this->assertArrayHasKey('fchub_wishlist', $result);
        $this->assertSame('fchub_wishlist', $result['fchub_wishlist']['value']);
        $this->assertArrayHasKey('children', $result['fchub_wishlist']);
        $this->assertCount(3, $result['fchub_wishlist']['children']);
    }

    #[Test]
    public function testConditionChildrenHaveRequiredValues(): void
    {
        $module = new FluentCrmModule();
        $result = $module->addAutomationConditions([], null);
        $children = $result['fchub_wishlist']['children'];
        $values = array_column($children, 'value');

        $this->assertContains('wishlist_has_items', $values);
        $this->assertContains('wishlist_item_count', $values);
        $this->assertContains('wishlist_contains_products', $values);
    }

    #[Test]
    public function testAssessHasItemsReturnsFalseForNoUser(): void
    {
        $module = new FluentCrmModule();
        $subscriber = new \FluentCrm\App\Models\Subscriber();
        $subscriber->user_id = 0;

        $result = $module->assessAutomationConditions(
            true,
            ['property' => 'wishlist_has_items', 'operator' => 'exist', 'value' => ''],
            $subscriber
        );

        $this->assertFalse($result);
    }

    #[Test]
    public function testAssessNotExistReturnsTrueForNoUser(): void
    {
        $module = new FluentCrmModule();
        $subscriber = new \FluentCrm\App\Models\Subscriber();
        $subscriber->user_id = 0;

        $result = $module->assessAutomationConditions(
            false,
            ['property' => 'wishlist_has_items', 'operator' => 'not_exist', 'value' => ''],
            $subscriber
        );

        $this->assertTrue($result);
    }

    #[Test]
    public function testAssessUnknownPropertyReturnsOriginal(): void
    {
        $module = new FluentCrmModule();
        $subscriber = new \FluentCrm\App\Models\Subscriber();
        $subscriber->user_id = 42;

        $result = $module->assessAutomationConditions(
            true,
            ['property' => 'unknown_property', 'operator' => 'exist', 'value' => ''],
            $subscriber
        );

        $this->assertTrue($result);
    }

    #[Test]
    public function testPluginBootCompletesWithDependencies(): void
    {
        Plugin::boot();
        $this->assertTrue(true, 'Plugin::boot() completed without error.');
    }

    #[Test]
    public function testGetWishlistProductsDelegatesCorrectly(): void
    {
        $module = new FluentCrmModule();
        $result = $module->getWishlistProducts([], '', []);
        $this->assertIsArray($result);
    }

    #[Test]
    public function testGetWishlistVariantsDelegatesCorrectly(): void
    {
        $module = new FluentCrmModule();
        $result = $module->getWishlistVariants([], '', []);
        $this->assertIsArray($result);
    }

    #[Test]
    public function testAssessItemCountWithEmptyValueReturnsTrue(): void
    {
        $module = new FluentCrmModule();
        $subscriber = new \FluentCrm\App\Models\Subscriber();
        $subscriber->user_id = 42;

        $result = $module->assessAutomationConditions(
            false,
            ['property' => 'wishlist_item_count', 'operator' => '=', 'value' => ''],
            $subscriber
        );

        $this->assertTrue($result);
    }
}
