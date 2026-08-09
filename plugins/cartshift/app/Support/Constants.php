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

    /**
     * FluentCart's product custom post type.
     *
     * Verified against the installed plugin's app/CPT/FluentProducts.php::
     * CPT_NAME. 'fc_product' is not a value FluentCart has ever registered or
     * written — a query filtered on it returns zero rows every time. Kept
     * here as the single source of truth after that exact mistake shipped
     * once already (MappingController's candidate query, PreflightController's
     * fc_product_count, and MappingPromoter's existence check all used to
     * carry their own copy of this literal).
     */
    public const string FC_PRODUCT_POST_TYPE = 'fluent-products';

    /**
     * `fct_product_details.variation_type` for an attribute-driven product.
     *
     * FluentCart's Helper::PRODUCT_TYPE_ADVANCE_VARIATION. Named here because
     * two places have to agree about it and they are not near each other: the
     * mapping screen keeps such products out of the candidate list, and the
     * orphan-variant creator refuses to write into one if a decision reaches it
     * anyway. Both exist because FluentCart regenerates an advanced product's
     * variants from scratch on every combination save and deletes anything not
     * in the new cartesian.
     */
    public const string FC_ADVANCED_VARIATIONS = 'advanced_variations';

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

    /**
     * Child rows the migration writes that never reach the id-map.
     *
     * The migrators create these as a side effect of building a parent record and
     * never map them, so rollback cannot find them by id and used to leave them
     * behind as orphans. Each entry deletes rows whose foreign-key column points at
     * a parent that IS in the id-map, so the parent's own rollback drags them with it.
     *
     * Table and column names verified against FluentCart's schema in
     * fluent-cart/database/Migrations/: AppliedCouponsMigrator, OrderMetaMigrator,
     * ProductDownloadsMigrator, ProductMetaMigrator, AttributeObjectRelationsMigrator.
     *
     * Keys:
     * - table:       FluentCart table name, without the wpdb prefix.
     * - column:      foreign-key column holding the parent's FluentCart id.
     * - parent:      entity type whose rolled-back ids fill that column.
     * - object_type: optional extra equality filter, for polymorphic tables where
     *                the same id can belong to different object types.
     *
     * @var array<int, array{table: string, column: string, parent: string, object_type?: string}>
     */
    public const array ROLLBACK_CHILD_TABLES = [
        // OrderMigrator::migrateAppliedCoupons()
        [
            'table'  => 'fct_applied_coupons',
            'column' => 'order_id',
            'parent' => self::ENTITY_ORDER,
        ],
        // OrderMigrator::migrateKeyOrderMeta() and migrateOrderNotes()
        [
            'table'  => 'fct_order_meta',
            'column' => 'order_id',
            'parent' => self::ENTITY_ORDER,
        ],
        // ProductMigrator::createDownloadRecord() — post_id is the FC product post id.
        [
            'table'  => 'fct_product_downloads',
            'column' => 'post_id',
            'parent' => self::ENTITY_PRODUCT,
        ],
        // ProductMigrator::assignAttributes() — object_id is the FC variation id.
        [
            'table'  => 'fct_atts_relations',
            'column' => 'object_id',
            'parent' => self::ENTITY_VARIATION,
        ],
        // ProductMigrator::migrateVariationThumbnail(). fct_product_meta.object_id is
        // only unique within an object_type, so that filter is load-bearing here.
        [
            'table'       => 'fct_product_meta',
            'column'      => 'object_id',
            'parent'      => self::ENTITY_VARIATION,
            'object_type' => 'product_variant_info',
        ],
    ];

    /** Maximum ids per generated `WHERE column IN (...)` chunk. */
    public const int ROLLBACK_DELETE_CHUNK = 500;
}
