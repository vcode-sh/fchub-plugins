<?php

declare(strict_types=1);

namespace CartShift\Domain\Scope;

defined('ABSPATH') || exit;

use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\WooStorage;
use CartShift\Validator\PreflightCheck;

/**
 * What a chosen scope leaves behind, counted before anything migrates.
 *
 * The closure resolved by ScopeResolver pulls every product of every in-scope
 * order (see the class doc on ScopeResolver), so a narrower scope can never by
 * itself create a broken reference between an order and a product that failed
 * to migrate. Every count in here is either structurally zero under any scope
 * (product_link_missing — see its method doc) or arises from something the
 * closure deliberately leaves open (a customer whose WP user row is gone, a
 * subscription that follows its buyer rather than its product, a coupon
 * restriction that fell entirely outside an explicit product pick). None of the
 * four counters can be non-zero purely because a scope narrowed the selection.
 *
 * One query per consequence, run eagerly. There is no caching here — unlike
 * PreflightCheck's transient, a scope consequence is read once, on the preview
 * screen, right before the owner decides whether to proceed.
 */
final class ScopeConsequences
{
    /** WooCommerce product types CartShift can migrate. Mirrors PreflightCheck::checkProductTypes(). */
    private const array SUPPORTED_PRODUCT_TYPES = ['simple', 'variable', 'subscription', 'variable-subscription'];

    public function __construct(
        private readonly ScopeResolver $resolver,
    ) {
    }

    /**
     * @return list<array{code: string, label: string, hint: string, severity: string, category: string, count: int, remedy: array{action: string, label: string, product_ids?: list<int>}|null}>
     */
    public function all(): array
    {
        return [
            $this->describe(MigrationErrorCode::ProductLinkMissing, $this->productLinkMissingCount(), null),
            $this->describe(MigrationErrorCode::CustomerRebuiltFromOrder, $this->customerRebuiltFromOrderCount(), null),
            ...$this->subscriptionPausedMissingProduct(),
            ...$this->couponDisabledMissingRestrictions(),
        ];
    }

    /**
     * In-scope orders containing at least one product of an unrecognised type.
     *
     * Structurally always zero as a consequence of the scope: the closure pulls
     * every product referenced by every in-scope order, so an order never loses
     * its product link because the scope narrowed. It loses the link only when
     * ProductMigrator does not source that product at all — an unrecognised
     * product_type term, which is what this counts, or a status outside
     * publish/draft/private, which this does not (see the class doc on the
     * lower-bound caveat below).
     *
     * Reuses PreflightCheck::countOrdersAffectedByTypes(), so the "N of your M
     * orders" figure on the preflight screen and this consequence agree on
     * exactly the same row set. That method deliberately restricts to
     * publish/draft/private, so a product of an unrecognised type sitting in
     * the trash is not counted here either — this is a lower bound, not the
     * true figure. Widening it is a later task (see task-8 report).
     */
    private function productLinkMissingCount(): int
    {
        $unsupported = $this->unsupportedProductTypeSlugs();

        if ($unsupported === []) {
            return 0;
        }

        return PreflightCheck::countOrdersAffectedByTypes($unsupported);
    }

    /**
     * Product type slugs present in the catalogue that CartShift does not
     * migrate. Same query and the same supported-type list as
     * PreflightCheck::checkProductTypes() — kept in step deliberately, because
     * the two are quoted side by side and disagreeing on which types are
     * unsupported would make the numbers contradict each other.
     *
     * @return list<string>
     */
    private function unsupportedProductTypeSlugs(): array
    {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT t.slug, COUNT(*) as count
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'product_type'
               AND p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
             GROUP BY t.slug",
        );

        $slugs = [];

        foreach ($results as $row) {
            if (is_object($row) && isset($row->slug) && (int) ($row->count ?? 0) > 0) {
                $slugs[] = (string) $row->slug;
            }
        }

        return array_values(array_diff($slugs, self::SUPPORTED_PRODUCT_TYPES));
    }

    /**
     * In-scope orders whose customer_id is a registered customer not in the
     * closed registered set.
     *
     * Only reachable under an explicit scope: "everything" and "since" leave
     * registeredCustomerPredicate() unrestricted (see ScopeResolver), so every
     * registered customer travels and this cannot be non-zero for a reason the
     * scope caused. It is a real, if rare, outcome even so — the WP user row
     * behind a picked order's buyer can simply be gone.
     */
    private function customerRebuiltFromOrderCount(): int
    {
        if ($this->resolver->scope()->mode() !== MigrationScope::MODE_EXPLICIT) {
            return 0;
        }

        global $wpdb;

        $registered = $this->resolver->closedCustomers()['registered'];
        $selection  = $this->resolver->orderPredicate();
        [$scope, $scopeValues] = WooStorage::orderScopeParts();
        $table = WooStorage::ordersTable();

        $notInSql = '';
        $notInValues = [];

        if ($registered !== []) {
            $notInSql = ' AND customer_id NOT IN (' . implode(', ', array_fill(0, count($registered), '%d')) . ')';
            $notInValues = $registered;
        }

        $sql = "SELECT COUNT(*)
                  FROM {$table}
                 WHERE {$scope}
                   AND customer_id > 0"
            . $notInSql
            . $selection->andSql();

        $values = [...$scopeValues, ...$notInValues, ...$selection->values()];

        return (int) ($values === [] ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, ...$values)));
    }

    /**
     * In-scope subscriptions whose product is not in the closed product set.
     *
     * Only reachable under an explicit scope: productPredicate() is
     * unrestricted otherwise, so the whole catalogue travels and no
     * subscription can point at a product left behind. Real and often
     * non-zero in explicit mode, because subscriptions follow their buyer
     * (subscriptionPredicate()), never their product — a customer can be
     * picked whose subscription sells a product nobody picked and no in-scope
     * order ever referenced.
     *
     * @return list<array{code: string, label: string, hint: string, severity: string, category: string, count: int, remedy: array{action: string, label: string, product_ids?: list<int>}|null}>
     */
    private function subscriptionPausedMissingProduct(): array
    {
        if ($this->resolver->scope()->mode() !== MigrationScope::MODE_EXPLICIT) {
            return [$this->describe(MigrationErrorCode::SubscriptionPausedMissingProduct, 0, null)];
        }

        global $wpdb;

        $closedProductIds = $this->resolver->closedProductIds();
        $selection         = $this->resolver->subscriptionPredicate();

        if ($selection->matchesNoRows()) {
            return [$this->describe(MigrationErrorCode::SubscriptionPausedMissingProduct, 0, null)];
        }

        [$scope, $scopeValues] = WooStorage::orderScopeParts();
        $table    = WooStorage::ordersTable();
        $items    = $wpdb->prefix . 'woocommerce_order_items';
        $itemMeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

        // The subscription scope, not the order scope: shop_subscription rows
        // carry line items in the same tables HPOS uses for shop_order rows, so
        // the join shape matches PreflightCheck::countOrdersAffectedByTypes()
        // exactly; only the `type`/status pair differs.
        $subscriptionScope = WooStorage::subscriptionScopeSql();

        $notInSql = '';
        $notInValues = [];

        if ($closedProductIds !== []) {
            $notInSql = ' AND CAST(im.meta_value AS UNSIGNED) NOT IN ('
                . implode(', ', array_fill(0, count($closedProductIds), '%d'))
                . ')';
            $notInValues = $closedProductIds;
        }

        $sql = "SELECT so.id AS subscription_id, im.meta_value AS product_id
                  FROM {$table} so
            INNER JOIN {$items} oi ON oi.order_id = so.id
            INNER JOIN {$itemMeta} im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id'
                 WHERE {$subscriptionScope}"
            . $notInSql
            . $selection->andSql();

        $values = [...$notInValues, ...$selection->values()];

        $rows = $values === []
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, ...$values));

        $subscriptionIds = [];
        $missingProductIds = [];

        foreach ((array) $rows as $row) {
            if (!is_object($row)) {
                continue;
            }

            $subscriptionIds[(int) ($row->subscription_id ?? 0)] = true;

            $productId = (int) ($row->product_id ?? 0);

            if ($productId > 0) {
                $missingProductIds[$productId] = true;
            }
        }

        $count = count($subscriptionIds);
        $remedy = $count > 0
            ? [
                'action'      => 'add_products',
                'label'       => __('Bring those products too', 'cartshift'),
                'product_ids' => array_values(array_map('intval', array_keys($missingProductIds))),
            ]
            : null;

        return [$this->describe(MigrationErrorCode::SubscriptionPausedMissingProduct, $count, $remedy)];
    }

    /**
     * Coupons whose product_ids or exclude_product_ids postmeta list is
     * entirely outside the closed product set.
     *
     * Coupons always travel whole (ScopeResolver::couponPredicate() is none()
     * under every mode), so this counts every coupon in the shop against the
     * closure regardless of scope mode. Only reachable when the scope is
     * explicit: in every other mode the full catalogue is in closedProductIds()'s
     * place (productPredicate() unrestricted), so no restriction list can fall
     * entirely outside it.
     *
     * @return list<array{code: string, label: string, hint: string, severity: string, category: string, count: int, remedy: array{action: string, label: string, product_ids?: list<int>}|null}>
     */
    private function couponDisabledMissingRestrictions(): array
    {
        if ($this->resolver->scope()->mode() !== MigrationScope::MODE_EXPLICIT) {
            return [$this->describe(MigrationErrorCode::CouponDisabledMissingRestrictions, 0, null)];
        }

        global $wpdb;

        $closedProductIds = $this->resolver->closedProductIds();

        $rows = $wpdb->get_results(
            "SELECT p.ID AS coupon_id, pm1.meta_value AS product_ids, pm2.meta_value AS exclude_product_ids
               FROM {$wpdb->posts} p
          LEFT JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = '_product_ids'
          LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_exclude_product_ids'
              WHERE p.post_type = 'shop_coupon'
                AND p.post_status IN ('publish', 'draft', 'private')",
        );

        $affectedCoupons = 0;
        $missingProductIds = [];

        foreach ((array) $rows as $row) {
            if (!is_object($row)) {
                continue;
            }

            $lists = [
                self::parseIdList($row->product_ids ?? ''),
                self::parseIdList($row->exclude_product_ids ?? ''),
            ];

            $affected = false;

            foreach ($lists as $ids) {
                if ($ids === []) {
                    continue;
                }

                $survivors = array_intersect($ids, $closedProductIds);

                if ($survivors === []) {
                    $affected = true;

                    foreach ($ids as $id) {
                        $missingProductIds[$id] = true;
                    }
                }
            }

            if ($affected) {
                ++$affectedCoupons;
            }
        }

        $remedy = $affectedCoupons > 0
            ? [
                'action'      => 'add_products',
                'label'       => __('Bring those products too', 'cartshift'),
                'product_ids' => array_values(array_map('intval', array_keys($missingProductIds))),
            ]
            : null;

        return [$this->describe(MigrationErrorCode::CouponDisabledMissingRestrictions, $affectedCoupons, $remedy)];
    }

    /**
     * Parse WooCommerce's comma-separated `_product_ids` / `_exclude_product_ids`
     * postmeta string into a list of positive product IDs.
     *
     * @return list<int>
     */
    private static function parseIdList(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $piece) {
            $id = (int) trim($piece);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array{action: string, label: string, product_ids?: list<int>}|null $remedy
     * @return array{code: string, label: string, hint: string, severity: string, category: string, count: int, remedy: array{action: string, label: string, product_ids?: list<int>}|null}
     */
    private function describe(MigrationErrorCode $code, int $count, ?array $remedy): array
    {
        return [
            ...$code->toArray(),
            'count'  => $count,
            'remedy' => $remedy,
        ];
    }
}
