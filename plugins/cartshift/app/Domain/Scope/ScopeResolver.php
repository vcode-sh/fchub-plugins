<?php

declare(strict_types=1);

namespace CartShift\Domain\Scope;

defined('ABSPATH') || exit;

use CartShift\Support\WooStorage;

/**
 * Turns a chosen scope into the predicates the migrators need, resolving the
 * dependency closure on the way.
 *
 * The closure runs downward along "needs" edges and terminates after one round,
 * deliberately: a customer pulls their orders, an order pulls its buyer and all
 * of its products, and there it stops. Products do not pull orders, and the
 * customers an order brought in do not pull *their* other orders. Anything else
 * explodes on a real store, and the design spec is explicit that the result is
 * reported as a total rather than as a queue of decisions.
 *
 * Everything is memoised per instance, and an instance lives for one request.
 * That is on purpose: three closure queries per request is cheap, and a cached
 * closure that outlived the request would let a mid-run scope change go
 * unnoticed — which is precisely the silent widening this release exists to
 * prevent. Do not put this in a transient.
 */
final class ScopeResolver
{
    /**
     * Ceiling on any single resolved ID set.
     *
     * Above this, exceedsClosureLimit() returns true rather than the resolver
     * truncating the set — but this class only flags the overflow, it does not
     * refuse anything itself. The refusal lives in the two callers that check
     * the flag before a migration id exists: MigrationController::migrate()
     * (REST, 422 scope_closure_too_large) and MigrateCommand::migrate() (CLI,
     * WP_CLI::error()). Truncating would migrate a subset of what the owner
     * confirmed, which is exactly the class of silent data loss the whole
     * release is against — so both callers refuse outright rather than asking
     * this class to decide what to drop.
     */
    public const int MAX_CLOSURE_IDS = 5000;

    /** @var array{registered: list<int>, guests: list<string>}|null */
    private ?array $closedCustomers = null;

    /** @var list<int>|null */
    private ?array $closedProductIds = null;

    /** @var array<int, true>|null */
    private ?array $closedProductIndex = null;

    private bool $exceeded = false;

    public function __construct(
        private readonly MigrationScope $scope,
    ) {
    }

    public function scope(): MigrationScope
    {
        return $this->scope;
    }

    public function orderPredicate(): ScopePredicate
    {
        return match ($this->scope->mode()) {
            MigrationScope::MODE_EVERYTHING => ScopePredicate::none(),
            MigrationScope::MODE_SINCE      => self::sinceClause($this->scope->since()),
            default                         => $this->seedOrderPredicate(),
        };
    }

    public function subscriptionPredicate(): ScopePredicate
    {
        return match ($this->scope->mode()) {
            MigrationScope::MODE_EVERYTHING => ScopePredicate::none(),
            MigrationScope::MODE_SINCE      => self::sinceClause($this->scope->since()),
            // Subscriptions follow their buyer, never their product. A
            // subscription for a product that is not coming across migrates
            // paused (gap policy 3), which keeps the subscriber and the billing
            // history and charges nobody.
            default                         => $this->seedSubscriptionPredicate(),
        };
    }

    public function registeredCustomerPredicate(): ScopePredicate
    {
        return match ($this->scope->mode()) {
            MigrationScope::MODE_EVERYTHING => ScopePredicate::none(),
            MigrationScope::MODE_SINCE      => self::sinceClause($this->scope->since()),
            default                         => ScopePredicate::intIn(
                'customer_id',
                $this->closedCustomers()['registered'],
            ),
        };
    }

    public function guestCustomerPredicate(): ScopePredicate
    {
        return match ($this->scope->mode()) {
            MigrationScope::MODE_EVERYTHING => ScopePredicate::none(),
            MigrationScope::MODE_SINCE      => self::sinceClause($this->scope->since()),
            default                         => ScopePredicate::stringIn(
                'billing_email',
                $this->closedCustomers()['guests'],
            ),
        };
    }

    /**
     * The catalogue is bounded only when the owner picked products by hand.
     *
     * Open question 1 in the design spec, answered: "everything from a date"
     * takes the whole catalogue. A product costs little to migrate, and an
     * order referencing a product that never arrived is a worse outcome than an
     * unused product sitting in the catalogue.
     */
    public function productPredicate(string $column = 'p.ID'): ScopePredicate
    {
        if ($this->scope->mode() !== MigrationScope::MODE_EXPLICIT) {
            return ScopePredicate::none();
        }

        return ScopePredicate::intIn($column, $this->closedProductIds());
    }

    /**
     * Is this one WooCommerce product inside the run's catalogue selection?
     *
     * productPredicate() in PHP rather than in SQL, and deliberately the same
     * answer: a caller holding one id in hand — MappingPromoter, deciding
     * whether to promote a mapping decision — must not grow a second opinion
     * about what "in scope" means. Anything that widens or narrows
     * productPredicate() has to move this with it, which is why they live
     * three lines apart.
     *
     * Two of the three modes are answered without touching the database, and
     * that is not an optimisation, it is what the modes mean:
     *
     *  - **Everything** takes the whole catalogue. Filtering would be a pure
     *    narrowing of a run that asked for no narrowing at all.
     *  - **Since** also takes the whole catalogue. Products are not selected by
     *    the date and never were — see productPredicate()'s note, and open
     *    question 1 in the design spec. A date-limited run migrates every
     *    product precisely so an in-scope order can never reference one that
     *    did not arrive, so *every* mapped product is in scope for it, and a
     *    per-decision "which orders touched this product" query would be
     *    expensive, and would also be answering the wrong question.
     *  - **Explicit** is the only mode that bounds the catalogue, and the bound
     *    is closedProductIds() — memoised, at most two queries per request, and
     *    already paid for by productPredicate() on any run that reads products.
     *
     * Flipped into a lookup table once rather than in_array()'d per call: the
     * closure runs to MAX_CLOSURE_IDS entries and promotion asks this question
     * once per staged decision.
     */
    public function includesProduct(int $wcProductId): bool
    {
        if ($this->scope->mode() !== MigrationScope::MODE_EXPLICIT) {
            return true;
        }

        $this->closedProductIndex ??= array_fill_keys($this->closedProductIds(), true);

        return isset($this->closedProductIndex[$wcProductId]);
    }

    /**
     * Coupons always travel whole.
     *
     * A coupon is cheap, and the 1.2.1 gap policy already migrates one whose
     * restrictions did not survive in a disabled state rather than letting it
     * discount the shop. Filtering coupons by scope would add a second way to
     * lose a coupon and no way to notice.
     */
    public function couponPredicate(): ScopePredicate
    {
        return ScopePredicate::none();
    }

    /**
     * Everyone who bought one of the orders this scope selects.
     *
     * @return array{registered: list<int>, guests: list<string>}
     */
    public function closedCustomers(): array
    {
        if ($this->closedCustomers !== null) {
            return $this->closedCustomers;
        }

        if ($this->scope->mode() !== MigrationScope::MODE_EXPLICIT) {
            return $this->closedCustomers = ['registered' => [], 'guests' => []];
        }

        $registered = $this->scope->customerIds();
        $guests     = $this->scope->guestEmails();

        // Only the product-driven orders can bring in buyers who were not
        // picked. Orders selected *by* a picked customer are, by construction,
        // that customer's — so the second hop is skipped entirely when the
        // upward offer was declined.
        if ($this->scope->includesOrdersForProducts() && $this->scope->productIds() !== []) {
            $seed = $this->seedOrderPredicate();

            $registered = self::mergeInts(
                $registered,
                array_map(intval(...), $this->closureCol('DISTINCT customer_id', $seed, 'customer_id > 0')),
            );

            // The `customer_id` guard is what keeps the two sets disjoint. Every
            // order carries a billing_email, registered or not, so without it a
            // registered buyer lands in *both* sets — and closure.customers, the
            // number the receipt panel reports as "that also brings in N
            // customers", double-counts every registered buyer the offer pulled
            // in. It also matches how CustomerMigrator's guest phase defines a
            // guest: an order with no user behind it.
            $guests = self::mergeStrings(
                $guests,
                array_map(
                    static fn (string $email): string => strtolower(trim($email)),
                    $this->closureCol(
                        'DISTINCT billing_email',
                        $seed,
                        "billing_email != '' AND (customer_id IS NULL OR customer_id = 0)",
                    ),
                ),
            );
        }

        $this->noteSize($registered);
        $this->noteSize($guests);

        return $this->closedCustomers = ['registered' => $registered, 'guests' => $guests];
    }

    /**
     * Every product in every order this scope selects, plus the picked ones.
     *
     * An order comes complete: a EUR 100 order with three line items migrated
     * with two is not a partial migration, it is corrupted books.
     *
     * @return list<int>
     */
    public function closedProductIds(): array
    {
        if ($this->closedProductIds !== null) {
            return $this->closedProductIds;
        }

        if ($this->scope->mode() !== MigrationScope::MODE_EXPLICIT) {
            return $this->closedProductIds = [];
        }

        global $wpdb;

        $ids  = $this->scope->productIds();
        $seed = $this->seedOrderPredicate();

        if (!$seed->matchesNoRows()) {
            $orders   = WooStorage::ordersTable();
            $items    = $wpdb->prefix . 'woocommerce_order_items';
            $itemMeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

            [$orderScope, $orderScopeValues] = WooStorage::orderScopeParts();

            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT im.meta_value
                   FROM {$items} oi
             INNER JOIN {$itemMeta} im
                     ON im.order_item_id = oi.order_item_id
                    AND im.meta_key = '_product_id'
                  WHERE oi.order_id IN (
                            SELECT id FROM {$orders} WHERE {$orderScope} AND ({$seed->sql()})
                        )",
                ...[...$orderScopeValues, ...$seed->values()],
            ));

            $ids = self::mergeInts($ids, array_map(intval(...), $rows));
        }

        $this->noteSize($ids);

        return $this->closedProductIds = $ids;
    }

    public function exceedsClosureLimit(): bool
    {
        // Force the closure so the flag is answerable before anything is asked
        // of it.
        $this->closedCustomers();
        $this->closedProductIds();

        return $this->exceeded;
    }

    /**
     * The orders the owner's picks select, before any closure.
     *
     * Three ways in, OR'd: the buyer is a picked registered customer, the buyer
     * is a picked guest email, or — only when the upward offer was accepted —
     * the order contains a picked product.
     *
     * An explicit scope that selects no orders yields '1 = 0' rather than an
     * empty predicate. That is the difference between "no orders" and "all
     * orders", and getting it the wrong way round migrates the entire shop.
     */
    private function seedOrderPredicate(): ScopePredicate
    {
        $parts = [
            ScopePredicate::intIn('customer_id', $this->scope->customerIds()),
            ScopePredicate::stringIn('billing_email', $this->scope->guestEmails()),
        ];

        if ($this->scope->includesOrdersForProducts() && $this->scope->productIds() !== []) {
            $parts[] = $this->ordersContainingProducts($this->scope->productIds());
        }

        $predicate = ScopePredicate::any(...$parts);

        return $predicate->isEmpty() ? ScopePredicate::matchesNothing() : $predicate;
    }

    /**
     * The subscriptions an explicit scope selects, by buyer.
     *
     * The guard on the way out is not decoration. ScopePredicate::any() drops
     * every matchesNothing() part, and join() returns none() when nothing
     * survives — so a scope that picked only products, with the upward offer
     * declined, would OR two empty sets into "no clause at all" and migrate
     * every subscription in the shop under a scope that selected no customers.
     *
     * Same guard, same reason, as seedOrderPredicate().
     */
    private function seedSubscriptionPredicate(): ScopePredicate
    {
        $closed = $this->closedCustomers();

        $predicate = ScopePredicate::any(
            ScopePredicate::intIn('customer_id', $closed['registered']),
            ScopePredicate::stringIn('billing_email', $closed['guests']),
        );

        return $predicate->isEmpty() ? ScopePredicate::matchesNothing() : $predicate;
    }

    /**
     * `id IN (SELECT order_id …)` for orders containing any of these products.
     *
     * Line items live in woocommerce_order_items / woocommerce_order_itemmeta
     * under HPOS just as they did before it — HPOS moved the order header, not
     * the items. `_product_id` is the parent product post id, which is exactly
     * what a picked product is.
     *
     * @param list<int> $productIds
     */
    private function ordersContainingProducts(array $productIds): ScopePredicate
    {
        global $wpdb;

        $items    = $wpdb->prefix . 'woocommerce_order_items';
        $itemMeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $holes    = implode(', ', array_fill(0, count($productIds), '%d'));

        return ScopePredicate::raw(
            "id IN (
                SELECT oi.order_id
                  FROM {$items} oi
            INNER JOIN {$itemMeta} im
                    ON im.order_item_id = oi.order_item_id
                   AND im.meta_key = '_product_id'
                 WHERE im.meta_value IN ({$holes})
             )",
            $productIds,
        );
    }

    /**
     * One DISTINCT column off the orders this scope selects.
     *
     * @return list<string>
     */
    private function closureCol(string $projection, ScopePredicate $seed, string $extra): array
    {
        global $wpdb;

        $orders = WooStorage::ordersTable();

        [$orderScope, $orderScopeValues] = WooStorage::orderScopeParts();

        return array_values((array) $wpdb->get_col($wpdb->prepare(
            "SELECT {$projection}
               FROM {$orders}
              WHERE {$orderScope}
                AND {$extra}
                AND ({$seed->sql()})",
            ...[...$orderScopeValues, ...$seed->values()],
        )));
    }

    private static function sinceClause(?string $since): ScopePredicate
    {
        return $since === null
            ? ScopePredicate::none()
            : ScopePredicate::raw('date_created_gmt >= %s', [$since]);
    }

    /**
     * @param array<int, int|string> $values
     */
    private function noteSize(array $values): void
    {
        if (count($values) > self::MAX_CLOSURE_IDS) {
            $this->exceeded = true;
        }
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function mergeInts(array $a, array $b): array
    {
        $merged = array_values(array_unique([...$a, ...$b]));
        $merged = array_values(array_filter($merged, static fn (int $id): bool => $id > 0));
        sort($merged);

        return $merged;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private static function mergeStrings(array $a, array $b): array
    {
        $merged = array_values(array_unique(array_filter([...$a, ...$b], static fn (string $s): bool => $s !== '')));
        sort($merged);

        return $merged;
    }
}
