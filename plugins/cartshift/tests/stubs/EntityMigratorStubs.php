<?php

declare(strict_types=1);

/**
 * Extra WordPress / WooCommerce stubs needed by the entity migrator tests.
 *
 * Everything is guarded so this file can be required from several test files
 * and can coexist with whatever the shared bootstrap already defines.
 */

// ──────────────────────────────────────────────
// WooCommerce function stubs
// ──────────────────────────────────────────────

if (!function_exists('get_woocommerce_currency')) {
    function get_woocommerce_currency(): string
    {
        return $GLOBALS['_cartshift_test_wc_currency'] ?? 'USD';
    }
}

if (!function_exists('wc_get_order_statuses')) {
    /**
     * Mirrors woocommerce/includes/wc-order-functions.php::wc_get_order_statuses().
     * Keys carry the `wc-` prefix, exactly as the wc_orders.status column stores them.
     *
     * @return array<string, string>
     */
    function wc_get_order_statuses(): array
    {
        return $GLOBALS['_cartshift_test_wc_order_statuses'] ?? [
            'wc-pending'    => 'Pending payment',
            'wc-processing' => 'Processing',
            'wc-on-hold'    => 'On hold',
            'wc-completed'  => 'Completed',
            'wc-cancelled'  => 'Cancelled',
            'wc-refunded'   => 'Refunded',
            'wc-failed'     => 'Failed',
        ];
    }
}

if (!function_exists('get_post_stati')) {
    /**
     * @return array<string, string>
     */
    function get_post_stati(array $args = []): array
    {
        $registered = $GLOBALS['_cartshift_test_post_stati'] ?? [
            'trash'              => ['exclude_from_search' => true],
            'auto-draft'         => ['exclude_from_search' => true],
            'wc-checkout-draft'  => ['exclude_from_search' => true],
            'publish'            => ['exclude_from_search' => false],
        ];

        $matched = [];

        foreach ($registered as $name => $properties) {
            foreach ($args as $key => $expected) {
                if (($properties[$key] ?? null) !== $expected) {
                    continue 2;
                }
            }
            $matched[$name] = $name;
        }

        return $matched;
    }
}

if (!function_exists('wc_get_orders')) {
    /**
     * Records the args it was called with, and resolves 'status' the way
     * OrdersTableQuery::sanitize_status() does, so tests can assert that the
     * COUNT queries and the batch fetches agree on the same status set.
     *
     * @return list<mixed>
     */
    function wc_get_orders(array $args = []): array
    {
        $resolved = $args;
        $statuses = $args['status'] ?? [];

        if (!is_array($statuses)) {
            $statuses = [$statuses];
        }

        $valid = array_keys(wc_get_order_statuses());

        if ($statuses === [] || in_array('any', $statuses, true)) {
            $exclude  = get_post_stati(['exclude_from_search' => true]);
            $statuses = array_values(array_diff($valid, array_values($exclude)));
        } elseif (in_array('all', $statuses, true)) {
            $statuses = [];
        } else {
            $statuses = array_map(
                static fn (string $s): string => in_array('wc-' . $s, $valid, true) ? 'wc-' . $s : $s,
                $statuses,
            );
        }

        $resolved['status'] = array_values(array_unique(array_filter($statuses)));

        $GLOBALS['_cartshift_test_wc_get_orders_calls'][] = $resolved;

        if (isset($GLOBALS['_cartshift_test_wc_get_orders_callback'])) {
            return ($GLOBALS['_cartshift_test_wc_get_orders_callback'])($resolved);
        }

        return $GLOBALS['_cartshift_test_wc_get_orders_return'] ?? [];
    }
}

if (!function_exists('wcs_get_subscription')) {
    /**
     * Hydrate one subscription by ID.
     *
     * Reads the same flat list `wcs_get_subscriptions()` pages through, so a
     * test seeds one global and both functions agree. That agreement is under
     * test in its own right: the subscription dataset source builds an ID
     * stream with the plural function and hydrates each ID with this one, and
     * a count and a fetch that disagreed about the source would reproduce the
     * exact defect the plan lists as a P0.
     *
     * Lives beside `wcs_get_subscriptions()` because there must be exactly one
     * definition of each — see the header of HttpCliStubs.php for what a
     * competing second definition does to a suite.
     */
    function wcs_get_subscription(int|string|object $subscription): ?object
    {
        if (is_object($subscription)) {
            return $subscription;
        }

        $id = (int) $subscription;

        // A row the query lists and the hydrator will not hand back: deleted
        // mid-run, corrupt, or filtered away by a third party. Tests seed the
        // IDs in $GLOBALS['_cartshift_test_wcs_unhydratable'], because a source
        // that lists more rows than it can produce is the case that used to end
        // a migration early and report success.
        if (in_array($id, $GLOBALS['_cartshift_test_wcs_unhydratable'] ?? [], true)) {
            return null;
        }

        foreach ($GLOBALS['_cartshift_test_wcs_pages'] ?? [] as $candidate) {
            if (is_object($candidate) && (int) $candidate->get_id() === $id) {
                return $candidate;
            }
        }

        return null;
    }
}

if (!function_exists('wc_get_order')) {
    /**
     * Hydrate one order by ID.
     *
     * WooCommerce answers `false` for an ID it cannot find, not null, and the
     * difference matters: a reader that treats `false` as an object fatals on
     * the first getter. Counted, so a test can prove each unique related order
     * is hydrated once rather than once per relationship type.
     */
    function wc_get_order(int|string|object $order): mixed
    {
        $GLOBALS['_cartshift_test_wc_order_lookups'] =
            ($GLOBALS['_cartshift_test_wc_order_lookups'] ?? 0) + 1;

        if (is_object($order)) {
            return $order;
        }

        return $GLOBALS['_cartshift_test_wc_orders'][(int) $order] ?? false;
    }
}

if (!function_exists('wc_get_order_item_meta')) {
    function wc_get_order_item_meta(int $itemId, string $key, bool $single = true): mixed
    {
        return $GLOBALS['_cartshift_test_order_item_meta'][$itemId][$key] ?? '';
    }
}

// ──────────────────────────────────────────────
// Test doubles
// ──────────────────────────────────────────────

if (!function_exists('cartshift_test_id_map_reader')) {
    /**
     * The `get_var()` callback every subscription test needs, in one place.
     *
     * Two queries reach `$wpdb->get_var()` on the subscription path and they
     * have to be answered together: `IdMapRepository::getFcId()`, and the
     * variation-ownership lookup section 9.3 requires before a subscription is
     * written. A test that answered only the first got `null` for the second,
     * which reads — correctly — as "that variation is not on that product", and
     * every otherwise-healthy fixture blocked.
     *
     * Ownership comes from `_cartshift_test_fc_variation_owner` and from
     * nowhere else. It used to fall back to DERIVING the answer from the ID map
     * — a destination variation belongs to whichever destination product was
     * mapped from the same source ID — which was convenient and wrong: it made
     * a mapping row and a catalogue row indistinguishable, which is precisely
     * the conflation the ownership gate exists to stop trusting. A test that
     * wants the pairing to hold now says so, with `cartshift_test_own_variation()`.
     */
    function cartshift_test_id_map_reader(): callable
    {
        return static function (string $query): int|null {
            if (preg_match('/fct_product_variations WHERE id = (\d+)/', $query, $matches) === 1) {
                $owner = $GLOBALS['_cartshift_test_fc_variation_owner'][(int) $matches[1]] ?? null;

                return $owner === null ? null : (int) $owner;
            }

            if (preg_match("/entity_type = '([^']*)' AND wc_id = '([^']*)'/", $query, $matches) === 1) {
                return $GLOBALS['_cartshift_test_id_map'][$matches[1]][$matches[2]] ?? null;
            }

            return null;
        };
    }
}

if (!function_exists('cartshift_test_own_variation')) {
    /**
     * State that a destination variation sits on a destination product.
     */
    function cartshift_test_own_variation(int $fcVariationId, int $fcProductId): void
    {
        $GLOBALS['_cartshift_test_fc_variation_owner'][$fcVariationId] = $fcProductId;
    }
}

if (!function_exists('cartshift_test_accept_manual_fallback')) {
    /**
     * Accept the manual-renewal behaviour change for this test.
     *
     * `SubscriptionMigrator` leaves `manualFallbackConfirmed` at
     * `PaymentEnvironment`'s own `false`, so a subscription WooCommerce was
     * charging automatically comes back `confirmation_required` and is not
     * written — section 8.4 holds it there until an operator accepts that its
     * customer will now receive an invoice instead. A test that wants a live
     * record to migrate has to say so, in the same words an integrator would.
     */
    function cartshift_test_accept_manual_fallback(): void
    {
        add_filter('cartshift/subscription/manual_fallback_confirmed', static fn (): bool => true);
    }
}

if (!class_exists('CartShiftTestWpdb')) {
    /**
     * The shared wpdb stub declares no $comments / $commentmeta. Declaring them
     * on a subclass keeps PHP 8.2+ happy — assigning them dynamically would be
     * a deprecation.
     */
    class CartShiftTestWpdb extends wpdb
    {
        public string $comments = 'wp_comments';
        public string $commentmeta = 'wp_commentmeta';
    }
}

if (!class_exists('CartShiftTestOrderItem')) {
    /**
     * A WC_Order_Item_Product whose product/variation IDs can actually be set.
     * The bootstrap stub only exposes getters over protected defaults.
     */
    class CartShiftTestOrderItem extends WC_Order_Item_Product
    {
        public function __construct(int $productId = 0, int $variationId = 0, string $name = '')
        {
            $this->product_id   = $productId;
            $this->variation_id = $variationId;
            $this->name         = $name;
        }
    }
}

if (!class_exists('CartShiftTestSubscription')) {
    /**
     * Stand-in for WC_Subscription.
     *
     * It started as "only what the migrator touches", which meant the ID, the
     * customer and the line items. The gap-policy tests migrate a subscription
     * for real, so SubscriptionMapper has to be able to read it too — hence the
     * billing, date and gateway getters below. All of them return the inert
     * value WooCommerce would return for a subscription with nothing set, so no
     * existing test sees a different subscription than it did before.
     */
    class CartShiftTestSubscription
    {
        /** @param list<CartShiftTestOrderItem> $items */
        public function __construct(
            private readonly int $id = 0,
            private readonly array $items = [],
            private readonly int $customerId = 1,
            private readonly string $status = 'active',
            private readonly string $billingEmail = '',
            private readonly ?string $createdGmt = null,
            // Last, and defaulting to the 0 this class always returned, so no
            // existing fixture changes shape. FluentCart's
            // fct_subscriptions.parent_order_id is NOT NULL, so a test that
            // wants a subscription to migrate has to say which order it came
            // from — and one that wants to prove the parent-order gate bites
            // simply leaves this alone.
            private readonly int $parentId = 0,
            /**
             * The source's own dates, keyed as WCS keys them.
             *
             * Appended last and defaulting to empty, so no existing fixture
             * changes shape. It exists because section 9.3 now refuses an
             * active subscription with no next-payment date — nothing would own
             * its next charge — so a fixture that means "a healthy live record"
             * has to say when it bills next rather than leaving the suite to
             * infer one.
             *
             * @var array<string, string>
             */
            private readonly array $dates = [],
        ) {
        }

        public function get_id(): int
        {
            return $this->id;
        }

        /**
         * A synthetic address when the fixture did not name one.
         *
         * Every one of the 564 preserved Lapka subscriptions resolves a billing
         * email — including all 349 whose `_customer_user` is 0 — so a
         * subscription with no email at all is not a shape this source
         * produces. It matters because email is now the identity a
         * subscription is decoded with, so a blank default would turn every
         * fixture in the suite into an unreadable source record and make each
         * of these tests measure the decoder rather than the thing it is about.
         *
         * `example.invalid` is the RFC 2606 reserved TLD: it cannot be
         * delivered to even by accident.
         */
        public function get_billing_email(): string
        {
            return $this->billingEmail !== ''
                ? $this->billingEmail
                : sprintf('subscriber-%d@example.invalid', $this->id);
        }

        /**
         * WooCommerce hands back a WC_DateTime here, or null when unset. Only
         * getTimestamp() is used by the code under test, and DateTimeImmutable
         * answers that identically — the point of the comparison is the instant,
         * not the rendering.
         */
        public function get_date_created(): ?\DateTimeImmutable
        {
            return $this->createdGmt === null
                ? null
                : new \DateTimeImmutable($this->createdGmt . ' UTC');
        }

        public function get_customer_id(): int
        {
            return $this->customerId;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function get_parent_id(): int
        {
            return $this->parentId;
        }

        /**
         * Deliberately still null even when there is a parent ID.
         *
         * SubscriptionMapper only reads the parent object to infer a setup fee
         * from `parent total - recurring total`, and inventing a parent total
         * here would manufacture a phantom fee inside every fixture that gained
         * a parent ID. WooCommerce can also hand back nothing for an order that
         * has been deleted, so this is a shape the source really produces.
         */
        public function get_parent(): ?object
        {
            return null;
        }

        public function get_currency(): string
        {
            return 'USD';
        }

        public function get_total(): string
        {
            return '10.00';
        }

        public function get_total_tax(): string
        {
            return '0.00';
        }

        public function get_billing_period(): string
        {
            return 'month';
        }

        public function get_billing_interval(): int
        {
            return 1;
        }

        public function get_payment_count(): int
        {
            return 3;
        }

        public function get_payment_method(): string
        {
            return '';
        }

        /**
         * WooCommerce Subscriptions answers the INTEGER `0` for a date that is
         * not set, never `''` — see the note on `CartShiftLapkaSubscription`.
         * The `dates` spec keeps writing `''` for readability and the sentinel
         * is applied here, where WooCommerce applies it.
         */
        public function get_date(string $type): string|int
        {
            $value = array_key_exists($type, $this->dates)
                ? trim((string) $this->dates[$type])
                : ($type === 'start' ? '2024-01-01 00:00:00' : '');

            return $value === '' ? 0 : $value;
        }

        /**
         * Inert for everything except the one key WooCommerce Subscriptions
         * writes on every subscription it creates.
         *
         * `_subscription_length = 0` is WCS's own encoding of "unlimited", and
         * it is what both Lapka source products carry. A stub answering `''`
         * models a source row where the term was never recorded at all, which
         * is a distinct condition the writer refuses — so every fixture here
         * would otherwise be blocked for a fault none of them are about.
         */
        public function get_meta(string $key, bool $single = true): mixed
        {
            return $key === '_subscription_length' ? '0' : '';
        }

        /**
         * WooCommerce keys line items by order-item ID, not by offset. Mirror
         * that so tests catch code which assumes 0-based keys.
         *
         * @return array<int, CartShiftTestOrderItem>
         */
        public function get_items(string $type = 'line_item'): array
        {
            $keyed = [];
            $itemId = 5000;

            foreach ($this->items as $item) {
                $keyed[$itemId] = $item;
                $itemId += 7;
            }

            return $keyed;
        }
    }
}

if (!function_exists('wcs_get_subscriptions')) {
    /**
     * Stand-in for the WooCommerce Subscriptions query function.
     *
     * Driven by a flat list of subscription objects in
     * $GLOBALS['_cartshift_test_wcs_pages'], sliced by offset and page size —
     * which is exactly the OFFSET paging SubscriptionMigrator relies on. Absent
     * global means an empty source, so every test that does not opt in sees the
     * same "nothing to migrate" the suite saw before this function existed.
     *
     * PluginTestCase clears every `_cartshift_test_*` global between tests, so
     * the source is isolated per test with no bookkeeping here.
     *
     * @param array<string, mixed> $args
     *
     * @return list<object>
     */
    function wcs_get_subscriptions(array $args = []): array
    {
        // Counted, because "was this read at all" is a real question. A
        // relationship index built eagerly makes a read-only preview page every
        // subscription in the store, and the only way to prove it does not is to
        // be able to say the query never ran.
        $GLOBALS['_cartshift_test_wcs_query_count'] = ($GLOBALS['_cartshift_test_wcs_query_count'] ?? 0) + 1;

        $source = $GLOBALS['_cartshift_test_wcs_pages'] ?? [];

        if (!is_array($source)) {
            return [];
        }

        $offset = max(0, (int) ($args['offset'] ?? 0));
        $limit  = max(1, (int) ($args['subscriptions_per_page'] ?? 10));

        return array_values(array_slice(array_values($source), $offset, $limit));
    }
}
