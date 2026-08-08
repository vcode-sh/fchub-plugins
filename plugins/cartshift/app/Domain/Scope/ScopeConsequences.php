<?php

declare(strict_types=1);

namespace CartShift\Domain\Scope;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\CouponMapper;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\WooStorage;
use CartShift\Validator\PreflightCheck;

/**
 * What a chosen scope leaves behind, counted before anything migrates.
 *
 * Two separate things end up in this list, and conflating them is what made an
 * earlier version report structural zeroes:
 *
 *  - Losses a narrower scope caused. The closure resolved by ScopeResolver
 *    pulls every product of every in-scope order (see the class doc on
 *    ScopeResolver), so an order can never lose its product link merely because
 *    the scope narrowed. What a narrow scope does leave open is a customer
 *    whose WP user row is gone, a subscription that follows its buyer rather
 *    than its product, and a coupon restricted to products nobody picked.
 *
 *  - Losses the migrators cause under *every* scope. ProductMigrator sources
 *    only the supported product types (see
 *    PreflightCheck::SUPPORTED_PRODUCT_TYPES), so a subscription selling a
 *    LearnDash `course`, or a coupon restricted to one, is left behind just as
 *    surely under a plain "Everything" run. Gating those counts on
 *    MODE_EXPLICIT on the premise that "the whole catalogue travels" reported
 *    zero in exactly the run where the owner had asked for no narrowing at all.
 *
 * Which product types are unsupported comes from PreflightCheck and nowhere
 * else, and which coupon restriction losses disable a coupon comes from
 * CouponMapper::WIDENING_ON_TOTAL_LOSS and nowhere else. Both are read, never
 * re-listed: a preview that disagreed with the migrator it is previewing is
 * the failure mode this whole class exists to avoid.
 *
 * One query per consequence, run eagerly. There is no caching here — unlike
 * PreflightCheck's transient, a scope consequence is read once, on the preview
 * screen, right before the owner decides whether to proceed.
 */
final class ScopeConsequences
{
    /**
     * The WooCommerce postmeta key behind each FluentCart restriction key.
     *
     * The FluentCart names are CouponMapper::mapConditions()'s own; the
     * WooCommerce names are what WC_Coupon reads. This table exists only to
     * translate between them — which of these losses actually disables a
     * coupon is CouponMapper::WIDENING_ON_TOTAL_LOSS's business, and is read
     * from there rather than repeated here.
     *
     * All four restriction keys CouponMapper maps are listed, so any subset of
     * them that list names is resolvable. A key added to the widening list
     * without a row here would be silently unevaluable, which is why
     * testEveryWideningRestrictionIsEvaluable() fails the build instead.
     *
     * @var array<string, string>
     */
    private const array RESTRICTION_META = [
        'included_products'   => '_product_ids',
        'excluded_products'   => '_exclude_product_ids',
        'included_categories' => '_product_categories',
        'excluded_categories' => '_exclude_product_categories',
    ];

    public function __construct(
        private readonly ScopeResolver $resolver,
    ) {
    }

    /**
     * Every consequence, or only those belonging to the entities being
     * migrated.
     *
     * A consequence describes something a migrator will do. Untick Orders and
     * "order items link to no product" is not a smaller number, it is not a
     * fact at all — no order is being migrated to lose a link. Reporting it
     * anyway put a figure on the receipt that no run could have produced.
     *
     * `$entityTypes` is the resolved list, dependencies already included: the
     * caller must not hand this the owner's raw ticks, because ticking Orders
     * alone migrates products and customers too.
     *
     * An empty list means "no filtering" for the benefit of callers that have
     * no entity list to give (the CLI, and tests); the preview always passes
     * one.
     *
     * @param list<string> $entityTypes
     * @return list<array{code: string, label: string, hint: string, severity: string, category: string, count: int, remedy: array{action: string, label: string, product_ids?: list<int>}|null, is_minimum: bool}>
     */
    public function all(array $entityTypes = []): array
    {
        $wanted = static fn (string $entity): bool => $entityTypes === []
            || in_array($entity, $entityTypes, true);

        $consequences = [];

        if ($wanted('product')) {
            // Products are the one entity that can lose something on their own,
            // without an order or a coupon to lose it through. Filtering the
            // order-scoped consequences out of a product-only run was right;
            // leaving nothing in their place let the panel print "Nothing left
            // behind." over a run that drops every course in the shop.
            $consequences[] = $this->describe(MigrationErrorCode::UnsupportedProductType, $this->unsupportedProductCount(), null);
        }

        if ($wanted('order')) {
            // A structural floor, not the true figure — see productLinkMissingCount().
            $consequences[] = $this->describe(MigrationErrorCode::ProductLinkMissing, $this->productLinkMissingCount(), null, true);
            $consequences[] = $this->describe(MigrationErrorCode::CustomerRebuiltFromOrder, $this->customerRebuiltFromOrderCount(), null);
        }

        if ($wanted('subscription')) {
            $consequences = [...$consequences, ...$this->subscriptionPausedMissingProduct()];
        }

        if ($wanted('coupon')) {
            $consequences = [...$consequences, ...$this->couponDisabledMissingRestrictions()];
        }

        return $consequences;
    }

    /**
     * In-scope products of a type ProductMigrator cannot source.
     *
     * The one consequence that belongs to products themselves. Every other
     * counter here describes something an order, a subscription or a coupon
     * loses; this is the catalogue losing rows outright, and without it a
     * product-only selection reported "Nothing left behind." over a run that
     * drops every LearnDash `course` in the shop. Preflight discloses the same
     * types on an earlier screen — which is not a defence, because this panel
     * is what the owner reads immediately before pressing Start.
     *
     * Scope-aware through productPredicate(), so an explicit pick that happens
     * to contain no unmigratable product correctly reports zero, while
     * "Everything" reports the whole catalogue's worth. Same status trio and
     * same unsupported-slug list as everything else — see
     * PreflightCheck::SUPPORTED_PRODUCT_TYPES.
     *
     * No remedy: nothing the owner can add to the scope makes a `course`
     * migratable. The honest move is to say so and let them decide.
     */
    private function unsupportedProductCount(): int
    {
        [$unsupportedSql, $unsupportedValues] = self::unsupportedTypeClause('p.ID');

        if ($unsupportedSql === '') {
            return 0;
        }

        global $wpdb;

        $selection = $this->resolver->productPredicate('p.ID');

        if ($selection->matchesNoRows()) {
            return 0;
        }

        $sql = "SELECT COUNT(*)
                  FROM {$wpdb->posts} p
                 WHERE p.post_type = 'product'
                   AND p.post_status IN ('publish', 'draft', 'private')
                   AND {$unsupportedSql}"
            . $selection->andSql();

        // Always at least one value — the slug list is what got us past the
        // empty-clause return above — so prepare() never sees a placeholder-free
        // query here.
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...[...$unsupportedValues, ...$selection->values()]));
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
     *
     * Which types are "unrecognised" also comes from PreflightCheck —
     * unsupportedProductTypeCounts() — rather than a second copy of the
     * supported-type list kept here. See PreflightCheck::SUPPORTED_PRODUCT_TYPES
     * for why there must be only one: a preflight warning and a consequence
     * count that disagreed about which types are unsupported would be quoted
     * side by side to the same user.
     *
     * The lower-bound caveat above is not just a comment for the next PHP
     * author — it travels on the wire. all() passes `isMinimum: true` for
     * this code, which is the only thing that tells the UI to render "at
     * least N" instead of a bare number. Widening this to also count the
     * trashed case (see task-8 report) would make that call site's `true`
     * wrong, not just this docblock stale — change both together.
     */
    private function productLinkMissingCount(): int
    {
        $unsupported = array_keys(PreflightCheck::unsupportedProductTypeCounts());

        if ($unsupported === []) {
            return 0;
        }

        return PreflightCheck::countOrdersAffectedByTypes($unsupported);
    }

    /**
     * In-scope orders whose customer_id is a registered customer not in the
     * closed registered set.
     *
     * Only reachable under an explicit scope: "everything" and "since" leave
     * registeredCustomerPredicate() unrestricted (see ScopeResolver), so every
     * registered customer travels and this cannot be non-zero for a reason the
     * scope caused. Real triggers under an explicit scope: the WP user row
     * behind a picked order's buyer can simply be gone, or a picked guest
     * email happens to match the billing_email on an order whose customer_id
     * is a registered buyer nobody picked — that order enters scope through
     * the guest-email predicate without its buyer entering closedCustomers().
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
     * In-scope subscriptions whose product is not going to arrive.
     *
     * Two ways a subscription's product fails to arrive, and both are counted
     * in every mode:
     *
     *  - It is not in the closed product set. Explicit scopes only:
     *    productPredicate() is unrestricted otherwise. Often non-zero, because
     *    subscriptions follow their buyer (subscriptionPredicate()), never
     *    their product — a customer can be picked whose subscription sells a
     *    product nobody picked and no in-scope order ever referenced.
     *
     *  - Its product type is one ProductMigrator does not source. This does
     *    not care about the scope at all. An earlier version returned 0 for
     *    every non-explicit mode on the premise that "the whole catalogue
     *    travels" — the catalogue does not travel whole, it travels minus
     *    every unsupported type, so a subscription selling a LearnDash
     *    `course` migrated paused under a plain "Everything" run while this
     *    receipt said nothing would be left behind. The same premise made the
     *    explicit branch wrong too: closedProductIds() is not filtered by
     *    product type, so a course product sitting inside the closure counted
     *    as fine.
     *
     * The unsupported types come from PreflightCheck — see
     * PreflightCheck::SUPPORTED_PRODUCT_TYPES for why there must be only one
     * such list — and are applied in SQL rather than by fetching every
     * in-scope subscription and filtering in PHP, which on an "Everything" run
     * means every subscription in the shop.
     *
     * @return list<array{code: string, label: string, hint: string, severity: string, category: string, count: int, remedy: array{action: string, label: string, product_ids?: list<int>}|null}>
     */
    private function subscriptionPausedMissingProduct(): array
    {
        global $wpdb;

        $selection = $this->resolver->subscriptionPredicate();

        if ($selection->matchesNoRows()) {
            return [$this->describe(MigrationErrorCode::SubscriptionPausedMissingProduct, 0, null)];
        }

        $missingSql    = [];
        $missingValues = [];

        if ($this->resolver->scope()->mode() === MigrationScope::MODE_EXPLICIT) {
            $closedProductIds = $this->resolver->closedProductIds();

            if ($closedProductIds === []) {
                // An explicit scope that closed over no products at all: every
                // product every in-scope subscription sells is missing. Not the
                // same as "no clause", which would mean the opposite.
                $missingSql[] = '1 = 1';
            } else {
                $missingSql[] = 'CAST(im.meta_value AS UNSIGNED) NOT IN ('
                    . implode(', ', array_fill(0, count($closedProductIds), '%d'))
                    . ')';
                $missingValues = $closedProductIds;
            }
        }

        [$unsupportedSql, $unsupportedValues] = self::unsupportedTypeClause('CAST(im.meta_value AS UNSIGNED)');

        if ($unsupportedSql !== '') {
            $missingSql[]  = $unsupportedSql;
            $missingValues = [...$missingValues, ...$unsupportedValues];
        }

        if ($missingSql === []) {
            // Nothing narrows the catalogue and nothing in it is unmigratable:
            // no subscription can point at a product that will not arrive.
            return [$this->describe(MigrationErrorCode::SubscriptionPausedMissingProduct, 0, null)];
        }

        $table    = WooStorage::ordersTable();
        $items    = $wpdb->prefix . 'woocommerce_order_items';
        $itemMeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

        // The subscription scope, not the order scope: shop_subscription rows
        // carry line items in the same tables HPOS uses for shop_order rows, so
        // the join shape matches PreflightCheck::countOrdersAffectedByTypes()
        // exactly; only the `type`/status pair differs.
        $subscriptionScope = WooStorage::subscriptionScopeSql();

        $sql = "SELECT so.id AS subscription_id, im.meta_value AS product_id
                  FROM {$table} so
            INNER JOIN {$items} oi ON oi.order_id = so.id
            INNER JOIN {$itemMeta} im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id'
                 WHERE {$subscriptionScope}
                   AND (" . implode(' OR ', $missingSql) . ')'
            . $selection->andSql();

        $values = [...$missingValues, ...$selection->values()];

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
     * Coupons that would come across as a shop-wide discount, and so migrate
     * disabled.
     *
     * The rule for "which restriction, lost entirely, widens the coupon" is
     * CouponMapper::WIDENING_ON_TOTAL_LOSS, and it is read from there rather
     * than restated. It has to be: the earlier version of this method listed
     * its own keys and got them wrong in both directions at once. It counted
     * `_exclude_product_ids`, which CouponMapper deliberately excludes — an
     * excluded product that never arrived has no cart item to have protected,
     * so the coupon stays active and merely narrower — and it ignored the two
     * category keys, which do widen. It over-reported and under-reported
     * simultaneously, and no test could catch either because both sides were
     * spelled out twice.
     *
     * Coupons always travel whole (ScopeResolver::couponPredicate() is none()
     * under every mode), so every coupon in the shop is weighed. There is no
     * mode gate either, for the same reason as
     * subscriptionPausedMissingProduct(): a restriction naming only products
     * of an unsupported type is lost under "Everything" just as surely as
     * under a narrow pick.
     *
     * @return list<array{code: string, label: string, hint: string, severity: string, category: string, count: int, remedy: array{action: string, label: string, product_ids?: list<int>}|null}>
     */
    private function couponDisabledMissingRestrictions(): array
    {
        global $wpdb;

        $metaKeys = self::wideningMetaKeys();

        if ($metaKeys === []) {
            return [$this->describe(MigrationErrorCode::CouponDisabledMissingRestrictions, 0, null)];
        }

        // INNER JOIN, not LEFT: a coupon carrying none of these restrictions
        // has nothing to lose and never reaches the loop below.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS coupon_id, pm.meta_key AS meta_key, pm.meta_value AS meta_value
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm
                 ON pm.post_id = p.ID
                AND pm.meta_key IN (" . implode(', ', array_fill(0, count($metaKeys), '%s')) . ")
              WHERE p.post_type = 'shop_coupon'
                AND p.post_status IN ('publish', 'draft', 'private')",
            ...array_values($metaKeys),
        ));

        /** @var array<int, array<string, list<int>>> $byCoupon */
        $byCoupon = [];
        $productIds = [];
        $categoryIds = [];

        foreach ((array) $rows as $row) {
            if (!is_object($row)) {
                continue;
            }

            $ids = self::parseIdList($row->meta_value ?? '');

            if ($ids === []) {
                continue;
            }

            $metaKey = (string) ($row->meta_key ?? '');
            $byCoupon[(int) ($row->coupon_id ?? 0)][$metaKey] = $ids;

            if (self::isCategoryMeta($metaKey)) {
                $categoryIds = [...$categoryIds, ...$ids];
            } else {
                $productIds = [...$productIds, ...$ids];
            }
        }

        // Two batch lookups for the whole shop's coupons, rather than one per
        // restriction list.
        $survivingProducts   = array_flip($this->migratableProductIds(array_values(array_unique($productIds))));
        $survivingCategories = array_flip(self::migratableCategoryIds(array_values(array_unique($categoryIds))));

        $affectedCoupons = 0;
        $missingProductIds = [];

        foreach ($byCoupon as $lists) {
            $affected = false;

            foreach ($lists as $metaKey => $ids) {
                $surviving = self::isCategoryMeta($metaKey) ? $survivingCategories : $survivingProducts;

                foreach ($ids as $id) {
                    if (isset($surviving[$id])) {
                        continue 2;
                    }
                }

                $affected = true;

                // Only products can be added back to the scope, so only they
                // go in the remedy. A lost category is not a scope decision —
                // migrateCategories() takes the taxonomy whole.
                if (!self::isCategoryMeta($metaKey)) {
                    foreach ($ids as $id) {
                        $missingProductIds[$id] = true;
                    }
                }
            }

            if ($affected) {
                ++$affectedCoupons;
            }
        }

        // No ids, no remedy. A coupon disabled purely by a lost category has
        // nothing the owner could add to the scope to fix it — offering a
        // button that adds an empty list is a promise the panel cannot keep.
        $remedy = $missingProductIds !== []
            ? [
                'action'      => 'add_products',
                'label'       => __('Bring those products too', 'cartshift'),
                'product_ids' => array_values(array_map('intval', array_keys($missingProductIds))),
            ]
            : null;

        return [$this->describe(MigrationErrorCode::CouponDisabledMissingRestrictions, $affectedCoupons, $remedy)];
    }

    /**
     * The WooCommerce postmeta keys whose total loss disables a coupon.
     *
     * Keyed by the FluentCart restriction name so a caller can say which one
     * it was looking at. The membership decision is CouponMapper's; this only
     * translates its vocabulary into the one postmeta speaks.
     *
     * @return array<string, string>
     */
    private static function wideningMetaKeys(): array
    {
        $keys = [];

        foreach (CouponMapper::WIDENING_ON_TOTAL_LOSS as $restriction) {
            if (isset(self::RESTRICTION_META[$restriction])) {
                $keys[$restriction] = self::RESTRICTION_META[$restriction];
            }
        }

        return $keys;
    }

    private static function isCategoryMeta(string $metaKey): bool
    {
        return $metaKey === '_product_categories' || $metaKey === '_exclude_product_categories';
    }

    /**
     * Which of these WooCommerce product IDs a run under this scope would
     * actually create in FluentCart.
     *
     * Three ways to fail, all of them reasons CouponMapper's ID map lookup
     * comes back empty: the post is gone or trashed, its product_type is one
     * ProductMigrator does not source, or — explicit scopes only — it is
     * outside the closure. The type list is PreflightCheck's, never a copy.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function migratableProductIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        if ($this->resolver->scope()->mode() === MigrationScope::MODE_EXPLICIT) {
            $ids = array_values(array_intersect($ids, $this->resolver->closedProductIds()));

            if ($ids === []) {
                return [];
            }
        }

        global $wpdb;

        [$unsupportedSql, $unsupportedValues] = self::unsupportedTypeClause('p.ID');

        $exclusion = $unsupportedSql === '' ? '' : " AND NOT ({$unsupportedSql})";

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
              WHERE p.ID IN (" . implode(', ', array_fill(0, count($ids), '%d')) . ")
                AND p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')"
            . $exclusion,
            ...[...$ids, ...$unsupportedValues],
        ));

        return array_values(array_map(intval(...), (array) $rows));
    }

    /**
     * Which of these WooCommerce product_cat term IDs would arrive in
     * FluentCart.
     *
     * Categories do not depend on the scope at all — migrateCategories() takes
     * `product_cat` whole, whatever is being migrated — so the only ways a
     * category restriction is lost are the two that method itself creates: the
     * term is `uncategorized`, which it skips outright, or the term does not
     * exist any more and never had a chance.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function migratableCategoryIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT tt.term_id
               FROM {$wpdb->term_taxonomy} tt
         INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
              WHERE tt.taxonomy = 'product_cat'
                AND t.slug <> 'uncategorized'
                AND tt.term_id IN (" . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            ...$ids,
        ));

        return array_values(array_map(intval(...), (array) $rows));
    }

    /**
     * `<column> IN (every product of a type CartShift cannot migrate)`.
     *
     * Empty SQL when the shop has no unsupported types at all, so the caller
     * can drop the clause entirely rather than emit `IN ()`.
     *
     * The slug list comes from PreflightCheck::unsupportedProductTypeCounts()
     * — the single definition, see PreflightCheck::SUPPORTED_PRODUCT_TYPES.
     * Products carrying no product_type term at all are not matched here,
     * which mirrors the picker's own exclusion (PreviewController) rather than
     * ProductMigrator's positive IN list; the two differ on that edge, and
     * that difference predates this method.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function unsupportedTypeClause(string $column): array
    {
        $slugs = array_values(array_keys(PreflightCheck::unsupportedProductTypeCounts()));

        if ($slugs === []) {
            return ['', []];
        }

        global $wpdb;

        $holes = implode(', ', array_fill(0, count($slugs), '%s'));

        return [
            "{$column} IN (
                    SELECT tr.object_id
                      FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = 'product_type'
                       AND t.slug IN ({$holes})
                 )",
            $slugs,
        ];
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
     * @return array{code: string, label: string, hint: string, severity: string, category: string, count: int, remedy: array{action: string, label: string, product_ids?: list<int>}|null, is_minimum: bool}
     */
    private function describe(MigrationErrorCode $code, int $count, ?array $remedy, bool $isMinimum = false): array
    {
        return [
            ...$code->toArray(),
            'count'      => $count,
            'remedy'     => $remedy,
            // True only for a count built from a structurally narrower query
            // than the truth (currently just product_link_missing, see its
            // method doc) — never inferred from the code string on the
            // reading end. The UI renders this, not `code === '...'`.
            'is_minimum' => $isMinimum,
        ];
    }
}
