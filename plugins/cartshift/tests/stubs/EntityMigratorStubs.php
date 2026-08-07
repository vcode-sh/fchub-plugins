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

// ──────────────────────────────────────────────
// Test doubles
// ──────────────────────────────────────────────

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
        ) {
        }

        public function get_id(): int
        {
            return $this->id;
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
            return 0;
        }

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
         * WooCommerce Subscriptions returns '' for a date that is not set, and
         * the mapper reads that as "no date".
         */
        public function get_date(string $type): string
        {
            return $type === 'start' ? '2024-01-01 00:00:00' : '';
        }

        public function get_meta(string $key, bool $single = true): mixed
        {
            return '';
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
