<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

/**
 * The one answer to "can CartShift migrate this product?".
 *
 * There used to be four, and they disagreed. PreflightCheck kept a constant,
 * PreviewController and ScopeConsequences derived a negative slug list from it,
 * and ProductMigrator kept a second copy of the list that was gated on
 * WooCommerce Subscriptions being loaded — so on a store that had once sold
 * subscriptions and then disabled the add-on, preflight and the picker called
 * those products migratable while the migrator quietly dropped them. Three
 * docblocks already said a second copy must never exist. Nothing enforced it.
 * This class is the enforcement: one list, one SQL predicate, four callers.
 *
 * Two rules are load-bearing and neither is obvious.
 *
 * 1. A product with NO `product_type` term is a simple product. That is
 *    WooCommerce's own reading, not a guess — the data store resolves a missing
 *    term to ProductType::SIMPLE rather than to nothing, so the catalogue, the
 *    admin product list and wc_get_product() all treat such a row as an
 *    ordinary simple product. CartShift has to agree, which is why the
 *    predicate is "has a supported-type term OR has no product_type term at
 *    all" rather than a plain `IN (...)`. A plain `IN (...)` requires the term
 *    row to exist and so drops the product without ever mentioning it: not in
 *    the total, not in the log, not on any screen.
 *
 * @see woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php::get_product_type() (v11.0.0, line 2149)
 *
 * 2. The subscription types are conditional on WooCommerce Subscriptions being
 *    loaded, and that condition belongs here rather than in one caller. See
 *    supported() for why the gate is protective rather than merely defensive.
 */
final class ProductTypes
{
    /**
     * Types CartShift can migrate whatever else is installed.
     *
     * `grouped` and `external` are absent deliberately: ProductMapper::map()
     * returns null for both, so they are unmigratable at the mapping layer too
     * and the two layers must not disagree about it.
     *
     * @var list<string>
     */
    private const array BASE_TYPES = ['simple', 'variable'];

    /**
     * Types CartShift can migrate only while WooCommerce Subscriptions is loaded.
     *
     * @var list<string>
     */
    private const array SUBSCRIPTION_TYPES = ['subscription', 'variable-subscription'];

    /**
     * The `product_type` slugs that carry children rather than being their own
     * single implicit variation.
     *
     * `variable-subscription` belongs here for exactly the reason `subscription`
     * does not: WooCommerce Subscriptions gives a variable product's *children*
     * the recurring configuration (`_subscription_period` and friends live on
     * each `WC_Product_Variation`, read by VariationMapper), while the parent
     * behaves structurally like any other variable product — `get_children()`
     * returns the same list, `get_type()` differs only by this one word.
     *
     * @var list<string>
     */
    private const array VARIABLE_TYPES = ['variable', 'variable-subscription'];

    /**
     * The `product_type` term slugs a run on THIS site can migrate.
     *
     * The WooCommerce Subscriptions gate is real, not superstition. Nothing
     * fatals without the add-on — wc_get_products(['type' => 'subscription'])
     * is a slug-matched taxonomy query and answers perfectly well whether or
     * not any subscription class is loaded — but what comes back is wrong in a
     * way no log row would record:
     *
     *  - WC_Product_Factory falls back to WC_Product_Simple when the class for
     *    a type does not exist, so a `subscription` product hydrates as a
     *    simple one and get_type() answers 'simple'.
     *  - VariationMapper only reads `_subscription_period` and friends behind
     *    class_exists('WC_Subscriptions_Product'), so the recurring
     *    configuration is dropped and the product arrives as a one-off sale.
     *  - Worse for `variable-subscription`: WC_Product::get_children() on the
     *    abstract returns an empty array, so every variation vanishes and the
     *    product arrives with a single "Default" row priced off the parent.
     *
     * Migrating those silently is worse than not migrating them. So the types
     * come off the supported list when the add-on is absent, which — because
     * everything reads this one method — is also what preflight, the picker and
     * the consequences panel then say out loud.
     *
     * @see woocommerce/includes/class-wc-product-factory.php::get_product_classname() (v11.0.0, line 78)
     * @see woocommerce/includes/abstracts/abstract-wc-product.php::get_children() (v11.0.0, line 2012)
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        if (!class_exists('WC_Subscriptions')) {
            return self::BASE_TYPES;
        }

        return [...self::BASE_TYPES, ...self::SUBSCRIPTION_TYPES];
    }

    /**
     * Whether a `product_type` slug is structurally variable — carries child
     * variations rather than being its own single implicit one.
     *
     * The one answer to a question five call sites used to ask themselves with
     * `$type === 'variable'`: ProductMapper (twice), ProductMigrator (twice) and
     * MappingController (twice) all read the parent's children, or fell through
     * to treating the whole product as one simple variation, based on that
     * literal comparison. `variable-subscription` failed every one of them —
     * ProductTypes::supported() has advertised it as migratable since D2, but a
     * variable subscription product was silently collapsed to its parent alone,
     * losing every variation (and, with it, every distinct cadence a shop sold
     * through them). `get_children()` does not care which of the two slugs the
     * parent carries, and neither should anything reading it.
     *
     * Deliberately independent of supported() — this predicate answers "does
     * this type have children", not "can this site migrate this type at all".
     * A store without WooCommerce Subscriptions loaded never reaches a call site
     * asking this in the first place, because ProductTypes::migratableClause()
     * has already excluded the row.
     */
    public static function isVariable(string $type): bool
    {
        return in_array($type, self::VARIABLE_TYPES, true);
    }

    /**
     * `<column> is a product CartShift would migrate`, plus its ordered values.
     *
     * The second branch is the whole point: `NOT IN (every product carrying any
     * product_type term)` is how "this product has no type term at all" is
     * spelled in SQL, and WooCommerce reads that state as `simple`. WooCommerce
     * writes the same accommodation itself — get_wp_query_args() ORs the slug
     * match with a `NOT EXISTS` on the taxonomy whenever variations are in
     * scope — so this is its shape, not an invention.
     *
     * `NOT IN` is safe against the usual NULL trap here: term_relationships
     * .object_id is `bigint(20) unsigned NOT NULL`, so the subquery cannot
     * yield a NULL that would collapse the whole predicate to UNKNOWN.
     *
     * @see woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php::get_wp_query_args() (v11.0.0, line 2249)
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function migratableClause(string $column): array
    {
        global $wpdb;

        $column    = self::sanitizeColumn($column);
        $supported = self::supported();
        $holes     = implode(', ', array_fill(0, count($supported), '%s'));

        $sql = "(
                    {$column} IN (
                        SELECT tr.object_id
                          FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                         WHERE tt.taxonomy = 'product_type'
                           AND t.slug IN ({$holes})
                    )
                    OR {$column} NOT IN (
                        SELECT tr.object_id
                          FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                         WHERE tt.taxonomy = 'product_type'
                    )
                )";

        return [$sql, $supported];
    }

    /**
     * The exact complement of migratableClause(), by construction rather than
     * by a second list someone has to keep in step.
     *
     * Built as `NOT (...)` on purpose. Written independently — as `IN (the
     * unsupported slugs present in this catalogue)`, which is what
     * ScopeConsequences used to do — the two predicates disagree on any row the
     * other one is silent about: a product with no type term counted as
     * unmigratable under the positive form and as migratable under the negative
     * one, which is precisely the divergence this class exists to end.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function unmigratableClause(string $column): array
    {
        [$sql, $values] = self::migratableClause($column);

        return ['NOT ' . $sql, $values];
    }

    /**
     * `SELECT object_id` for every product carrying a `product_type` term.
     *
     * Exposed so the one caller that needs the complement as a standalone
     * subquery — counting products with no type term at all — does not write a
     * third copy of the join.
     */
    public static function typedProductSubquery(): string
    {
        global $wpdb;

        return "SELECT tr.object_id
                  FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tt.taxonomy = 'product_type'";
    }

    /**
     * The column this predicate is applied to, or `p.ID` if it is not one of
     * the two shapes a caller is allowed to ask for.
     *
     * Allow-list, not strip-list. Stripping the characters that "look
     * dangerous" is what lets `p.ID; DROP TABLE wp_posts; --` through as
     * `p.ID DROP TABLE wp_posts` — still nonsense in the query, and nonsense
     * the caller cannot see. Only two forms are ever wanted: a plain
     * `table.column` and the `CAST(im.meta_value AS UNSIGNED)` that
     * ScopeConsequences needs to compare a line-item meta value with a post ID.
     * Anything else is a programming error, and falling back to a valid column
     * keeps it a wrong answer rather than a broken query.
     */
    private static function sanitizeColumn(string $column): string
    {
        return SqlIdentifier::column($column, 'p.ID');
    }
}
