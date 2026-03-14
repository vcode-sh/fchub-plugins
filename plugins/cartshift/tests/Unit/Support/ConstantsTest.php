<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

final class ConstantsTest extends PluginTestCase
{
    private const array ALL_ENTITY_TYPES = [
        Constants::ENTITY_PRODUCT,
        Constants::ENTITY_VARIATION,
        Constants::ENTITY_PRODUCT_DETAIL,
        Constants::ENTITY_CUSTOMER,
        Constants::ENTITY_GUEST_CUSTOMER,
        Constants::ENTITY_CUSTOMER_ADDRESS,
        Constants::ENTITY_ORDER,
        Constants::ENTITY_ORDER_ITEM,
        Constants::ENTITY_ORDER_ADDRESS,
        Constants::ENTITY_ORDER_TRANSACTION,
        Constants::ENTITY_COUPON,
        Constants::ENTITY_SUBSCRIPTION,
        Constants::ENTITY_CATEGORY,
        Constants::ENTITY_BRAND,
        Constants::ENTITY_ATTRIBUTE_GROUP,
        Constants::ENTITY_ATTRIBUTE_TERM,
    ];

    public function testRollbackOrderContainsAllEntityTypes(): void
    {
        foreach (self::ALL_ENTITY_TYPES as $entity) {
            $this->assertContains(
                $entity,
                Constants::ROLLBACK_ORDER,
                "ROLLBACK_ORDER is missing entity type: {$entity}"
            );
        }

        $this->assertCount(
            count(self::ALL_ENTITY_TYPES),
            Constants::ROLLBACK_ORDER,
            'ROLLBACK_ORDER should have exactly ' . count(self::ALL_ENTITY_TYPES) . ' entries'
        );
    }

    public function testRollbackOrderHasNoDuplicates(): void
    {
        $unique = array_unique(Constants::ROLLBACK_ORDER);

        $this->assertCount(
            count(Constants::ROLLBACK_ORDER),
            $unique,
            'ROLLBACK_ORDER contains duplicate entries'
        );
    }

    public function testEntityTypesAreStrings(): void
    {
        foreach (self::ALL_ENTITY_TYPES as $entity) {
            $this->assertIsString($entity);
            $this->assertNotEmpty($entity);
        }
    }

    public function testRollbackOrderIncludesBrandAndAttributeTypes(): void
    {
        $this->assertContains(
            Constants::ENTITY_BRAND,
            Constants::ROLLBACK_ORDER,
            'ROLLBACK_ORDER must include brand entity type',
        );
        $this->assertContains(
            Constants::ENTITY_ATTRIBUTE_GROUP,
            Constants::ROLLBACK_ORDER,
            'ROLLBACK_ORDER must include attribute_group entity type',
        );
        $this->assertContains(
            Constants::ENTITY_ATTRIBUTE_TERM,
            Constants::ROLLBACK_ORDER,
            'ROLLBACK_ORDER must include attribute_term entity type',
        );
    }

    public function testAllEntityConstantsAreUnique(): void
    {
        $values = self::ALL_ENTITY_TYPES;
        $unique = array_unique($values);

        $this->assertCount(
            count($values),
            $unique,
            'Entity constants must all have unique values. Duplicates: '
            . implode(', ', array_diff_assoc($values, $unique)),
        );
    }
}
