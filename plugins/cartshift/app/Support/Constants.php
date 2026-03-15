<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

final class Constants
{
    public const string ENTITY_PRODUCT = 'product';
    public const string ENTITY_VARIATION = 'variation';
    public const string ENTITY_PRODUCT_DETAIL = 'product_detail';
    public const string ENTITY_CUSTOMER = 'customer';
    public const string ENTITY_GUEST_CUSTOMER = 'guest_customer';
    public const string ENTITY_CUSTOMER_ADDRESS = 'customer_address';
    public const string ENTITY_ORDER = 'order';
    public const string ENTITY_ORDER_ITEM = 'order_item';
    public const string ENTITY_ORDER_ADDRESS = 'order_address';
    public const string ENTITY_ORDER_TRANSACTION = 'order_transaction';
    public const string ENTITY_COUPON = 'coupon';
    public const string ENTITY_SUBSCRIPTION = 'subscription';
    public const string ENTITY_CATEGORY = 'category';
    public const string ENTITY_BRAND = 'brand';
    public const string ENTITY_ATTRIBUTE_GROUP = 'attribute_group';
    public const string ENTITY_ATTRIBUTE_TERM = 'attribute_term';
    public const string ENTITY_SHIPPING_CLASS = 'shipping_class';

    public const int DEFAULT_BATCH_SIZE = 50;

    /** Dependency-safe deletion sequence for rollback */
    public const array ROLLBACK_ORDER = [
        self::ENTITY_SUBSCRIPTION,
        self::ENTITY_ORDER_TRANSACTION,
        self::ENTITY_ORDER_ADDRESS,
        self::ENTITY_ORDER_ITEM,
        self::ENTITY_ORDER,
        self::ENTITY_COUPON,
        self::ENTITY_CUSTOMER_ADDRESS,
        self::ENTITY_CUSTOMER,
        self::ENTITY_GUEST_CUSTOMER,
        self::ENTITY_ATTRIBUTE_TERM,
        self::ENTITY_ATTRIBUTE_GROUP,
        self::ENTITY_SHIPPING_CLASS,
        self::ENTITY_VARIATION,
        self::ENTITY_PRODUCT_DETAIL,
        self::ENTITY_PRODUCT,
        self::ENTITY_CATEGORY,
        self::ENTITY_BRAND,
    ];
}
