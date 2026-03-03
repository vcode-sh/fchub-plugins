<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\FluentCRM;

use FChubWishlist\FluentCRM\Filters\WishlistFilters;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistFiltersTest extends TestCase
{
    #[Test]
    public function testRegisterAddsFilterAndAction(): void
    {
        WishlistFilters::register();

        $filters = $GLOBALS['wp_filters_registered'];
        $actions = $GLOBALS['wp_actions_registered'];

        $filterTags = array_column($filters, 'tag');
        $actionTags = array_column($actions, 'tag');

        $this->assertContains('fluentcrm_advanced_filter_options', $filterTags);
        $this->assertContains('fluentcrm_contacts_filter_fchub_wishlist', $actionTags);
    }

    #[Test]
    public function testAddFilterOptionsRegistersGroup(): void
    {
        $groups = WishlistFilters::addFilterOptions([]);

        $this->assertArrayHasKey('fchub_wishlist', $groups);
        $this->assertSame('Wishlist', $groups['fchub_wishlist']['label']);
        $this->assertSame('fchub_wishlist', $groups['fchub_wishlist']['value']);
    }

    #[Test]
    public function testAddFilterOptionsHasChildFilters(): void
    {
        $groups = WishlistFilters::addFilterOptions([]);

        $children = $groups['fchub_wishlist']['children'];
        $this->assertCount(2, $children);

        $childValues = array_column($children, 'value');
        $this->assertContains('fchub_has_wishlist_items', $childValues);
        $this->assertContains('fchub_wishlist_item_count', $childValues);
    }

    #[Test]
    public function testHasWishlistItemsFilterHasCorrectOperators(): void
    {
        $groups = WishlistFilters::addFilterOptions([]);
        $children = $groups['fchub_wishlist']['children'];

        $hasItemsFilter = null;
        foreach ($children as $child) {
            if ($child['value'] === 'fchub_has_wishlist_items') {
                $hasItemsFilter = $child;
                break;
            }
        }

        $this->assertNotNull($hasItemsFilter);
        $this->assertSame('selections', $hasItemsFilter['type']);
        $this->assertArrayHasKey('custom_operators', $hasItemsFilter);
        $this->assertArrayHasKey('exist', $hasItemsFilter['custom_operators']);
        $this->assertArrayHasKey('not_exist', $hasItemsFilter['custom_operators']);
    }

    #[Test]
    public function testItemCountFilterIsNumeric(): void
    {
        $groups = WishlistFilters::addFilterOptions([]);
        $children = $groups['fchub_wishlist']['children'];

        $countFilter = null;
        foreach ($children as $child) {
            if ($child['value'] === 'fchub_wishlist_item_count') {
                $countFilter = $child;
                break;
            }
        }

        $this->assertNotNull($countFilter);
        $this->assertSame('numeric', $countFilter['type']);
    }

    #[Test]
    public function testApplyFiltersReturnsQueryForUnknownProperty(): void
    {
        $query = new \stdClass();

        $result = WishlistFilters::applyFilters($query, [
            ['property' => 'unknown_property', 'operator' => '=', 'value' => '5'],
        ]);

        // Unknown property should return query unchanged
        $this->assertSame($query, $result);
    }

    #[Test]
    public function testApplyFiltersReturnsQueryForEmptyFilters(): void
    {
        $query = new \stdClass();

        $result = WishlistFilters::applyFilters($query, []);

        $this->assertSame($query, $result);
    }

    #[Test]
    public function testApplyFiltersSkipsFilterWithEmptyPropertyOrOperator(): void
    {
        $query = new \stdClass();

        $result = WishlistFilters::applyFilters($query, [
            ['property' => '', 'operator' => '=', 'value' => '5'],
            ['property' => 'fchub_wishlist_item_count', 'operator' => '', 'value' => '5'],
        ]);

        $this->assertSame($query, $result);
    }

    #[Test]
    public function testFilterOptionsPreservesExistingGroups(): void
    {
        $existingGroups = [
            'some_other' => ['label' => 'Other', 'value' => 'some_other'],
        ];

        $groups = WishlistFilters::addFilterOptions($existingGroups);

        $this->assertArrayHasKey('some_other', $groups);
        $this->assertArrayHasKey('fchub_wishlist', $groups);
    }
}
