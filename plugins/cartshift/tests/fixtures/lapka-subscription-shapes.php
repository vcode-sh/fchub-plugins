<?php

declare(strict_types=1);

/**
 * Anonymised WooCommerce Subscriptions shapes, modelled on the Lapka source.
 *
 * This file returns CALLABLE FACTORIES, never rows. Two reasons, and both bite
 * in practice: a shared row object mutated by one test poisons the next, and a
 * factory can take overrides so a test says what it is actually about rather
 * than rebuilding twenty getters to change one date.
 *
 * NOTHING FROM PRODUCTION IS IN HERE. Every ID is synthetic and outside any
 * range the source uses, every email ends in `example.invalid` — the RFC 2606
 * reserved TLD, so it cannot be delivered to even by accident — and every
 * payment identifier is a visibly fake literal containing the word `synthetic`.
 * A real `pm_`, `src_`, `cus_` or PayPal ID must never enter this repository:
 * the plan's Global Constraints forbid customer data and payment tokens in
 * fixtures, and a token that looks plausible is worse than one that does not,
 * because somebody will eventually try it against an account.
 *
 * The aggregate counts under `aggregates` come from the plan's verified Lapka
 * baseline (context pack section 4). They are population statistics — 564
 * subscriptions, 349 guests, 360 empty next-payment dates — and carry no
 * individual identity at all. They exist so a later reconciler test can prove
 * it does not manufacture dates for the 360, rather than quietly inventing 360
 * plausible ones.
 *
 * Money: WooCommerce hands out decimal strings and FluentCart stores integer
 * minor units (MoneyHelper::toCents(), which is `* 100` for every currency).
 * The shapes therefore carry Woo's strings — '29.00' — and the aggregate table
 * carries FluentCart's integers — 2900. Any test that wants to prove the
 * conversion has both ends available and neither is a restatement of the other.
 *
 * @return array<string, callable>
 */

// ──────────────────────────────────────────────
// Doubles
// ──────────────────────────────────────────────

if (!class_exists('CartShiftLapkaProduct')) {
    /**
     * A WC_Product whose meta can be set, so `_subscription_length` is readable.
     * The bootstrap stub only exposes getters over protected defaults.
     */
    class CartShiftLapkaProduct extends WC_Product
    {
        /** @param array<string, mixed> $meta */
        public function __construct(int $id = 0, string $name = '', array $meta = [])
        {
            $this->id   = $id;
            $this->name = $name;
            $this->meta = $meta;
        }
    }
}

if (!class_exists('CartShiftLapkaItem')) {
    /**
     * A subscription line item with a settable product reference, quantity and
     * line total.
     */
    class CartShiftLapkaItem extends WC_Order_Item_Product
    {
        public function __construct(
            int $productId = 0,
            int $variationId = 0,
            string $name = '',
            int $quantity = 1,
            string $lineTotal = '0',
            ?WC_Product $product = null,
        ) {
            $this->product_id   = $productId;
            $this->variation_id = $variationId;
            $this->name         = $name;
            $this->quantity     = $quantity;
            $this->total        = $lineTotal;
            $this->subtotal     = $lineTotal;
            $this->product      = $product;
        }
    }
}

if (!class_exists('CartShiftLapkaSubscription')) {
    /**
     * A WC_Subscription stand-in built from a spec array.
     *
     * Only the getters CartShift actually calls are implemented, plus the two
     * — `is_manual()` and `get_requires_manual_renewal()` — that the plan's
     * payment precedence rules turn on. WooCommerce Subscriptions is not
     * installed on this machine and its wider API cannot be verified, so
     * nothing beyond the contracts the plan pins is invented here.
     */
    final class CartShiftLapkaSubscription
    {
        /** @param array<string, mixed> $spec */
        public function __construct(private readonly array $spec) {}

        public function get_id(): int
        {
            return (int) $this->spec['id'];
        }

        public function get_status(): string
        {
            return (string) $this->spec['status'];
        }

        public function get_customer_id(): int
        {
            return (int) $this->spec['customer_id'];
        }

        public function get_billing_email(): string
        {
            return (string) $this->spec['billing_email'];
        }

        public function get_parent_id(): int
        {
            return (int) $this->spec['parent_id'];
        }

        /**
         * WooCommerce hands back the parent order object, or null when there is
         * no parent order to hand back. The malformed Lapka record is exactly
         * the second case, which is why this is nullable rather than assumed.
         */
        public function get_parent(): ?WC_Order
        {
            $parentId = $this->get_parent_id();

            return $parentId <= 0 ? null : $this->relatedOrder($parentId);
        }

        /**
         * WCS's typed related-order lookup, one relationship at a time.
         *
         * The signature is the plan's: `get_related_orders($returnFields,
         * $relationshipType)`, called once per type. It deliberately does NOT
         * accept an array of types and does NOT group its result, because the
         * real method flattens a grouped result and discards the label — which
         * is exactly the mistake the four separate calls exist to prevent. A
         * caller that asks for `any` gets everything with no way to tell what
         * anything is, which is what the real one does too.
         *
         * @return list<int>|list<WC_Order>
         */
        public function get_related_orders(string $returnFields = 'ids', string $relationshipType = 'any'): array
        {
            $byType = $this->relatedOrdersByType();

            $ids = $relationshipType === 'any'
                ? array_merge(...array_values($byType))
                : ($byType[$relationshipType] ?? []);

            if ($returnFields === 'ids') {
                return array_values($ids);
            }

            return array_values(array_map(
                fn (int $orderId): WC_Order => $this->relatedOrder($orderId),
                $ids,
            ));
        }

        /**
         * The spec's related orders, defaulted from the parent.
         *
         * A subscription with a parent order always has that parent under the
         * `parent` relationship — anything else would make the fixture disagree
         * with `get_parent_id()`. The malformed record has no parent, and so
         * has no relationships at all.
         *
         * @return array<string, list<int>>
         */
        private function relatedOrdersByType(): array
        {
            $parentId = $this->get_parent_id();

            $defaults = [
                'parent'      => $parentId > 0 ? [$parentId] : [],
                'renewal'     => [],
                'switch'      => [],
                'resubscribe' => [],
            ];

            return array_merge($defaults, (array) ($this->spec['related_orders'] ?? []));
        }

        /**
         * A paid order for one related ID — parent and renewals alike.
         *
         * Same total and currency as the subscription's own contract, because a
         * renewal charges the contract, and because both Lapka source products
         * have a zero setup fee: a parent total picked at random would
         * manufacture the phantom fee `SubscriptionMapper`'s
         * `parent total - recurring total` inference already produces, and hide
         * it inside a fixture.
         *
         * Paid, with a transaction ID, because FluentCart recomputes
         * `bill_count` from succeeded positive charges and an order that
         * arrived without one contributes nothing.
         */
        private function relatedOrder(int $orderId): WC_Order
        {
            $order = new WC_Order();
            $paidAt = $this->spec['dates']['start'] ?? '';

            $properties = [
                'id'             => $orderId,
                'status'         => 'completed',
                'billing_email'  => $this->get_billing_email(),
                'total'          => (string) $this->spec['total'],
                'total_tax'      => (string) $this->spec['total_tax'],
                'currency'       => (string) $this->spec['currency'],
                'customer_id'    => (int) $this->spec['customer_id'],
                'payment_method' => (string) $this->spec['payment_method'],
                'transaction_id' => 'txn-fixture-' . $orderId,
                'date_created'   => $paidAt === '' ? null : new DateTimeImmutable($paidAt . ' UTC'),
                'date_paid'      => $paidAt === '' ? null : new DateTimeImmutable($paidAt . ' UTC'),
                'items'          => $this->spec['items'],
            ];

            foreach ($properties as $property => $value) {
                (new ReflectionProperty(WC_Order::class, $property))->setValue($order, $value);
            }

            return $order;
        }

        public function get_currency(): string
        {
            return (string) $this->spec['currency'];
        }

        public function get_total(): string
        {
            return (string) $this->spec['total'];
        }

        public function get_total_tax(): string
        {
            return (string) $this->spec['total_tax'];
        }

        public function get_billing_period(): string
        {
            return (string) $this->spec['billing_period'];
        }

        public function get_billing_interval(): int
        {
            return (int) $this->spec['billing_interval'];
        }

        public function get_payment_method(): string
        {
            return (string) $this->spec['payment_method'];
        }

        public function get_payment_count(): int
        {
            return (int) $this->spec['payment_count'];
        }

        public function is_manual(): bool
        {
            return (bool) $this->spec['requires_manual_renewal'];
        }

        public function get_requires_manual_renewal(): bool
        {
            return (bool) $this->spec['requires_manual_renewal'];
        }

        public function get_meta(string $key, bool $single = true): mixed
        {
            return $this->spec['meta'][$key] ?? '';
        }

        /**
         * WooCommerce keys line items by order-item ID, not by offset. Mirrored
         * here so a fixture catches code that assumes 0-based keys.
         *
         * @return array<int, CartShiftLapkaItem>
         */
        public function get_items(string $type = 'line_item'): array
        {
            $keyed  = [];
            $itemId = 41_000;

            foreach ($this->spec['items'] as $item) {
                $keyed[$itemId] = $item;
                $itemId += 13;
            }

            return $keyed;
        }

        /**
         * WooCommerce Subscriptions answers the INTEGER `0` for a date that is
         * not set — NOT `''`.
         *
         * This double used to return `''`, and that one wrong character is why
         * 1,946 unit tests passed while the live export rejected all 564
         * records: `'0'` is present-and-unparseable where `''` is absent, so
         * the decoder called every real subscription
         * `required_reference_missing`. The spec array still writes `''` for
         * "not set" because that is readable; the sentinel is applied here, on
         * the way out, exactly where WooCommerce applies it.
         *
         * See `WC_Subscription::get_date()`, documented `@return string|int`,
         * and `WC_Subscription::get_date_or_zero()`. The Lapka baseline: 551 of
         * 564 have no trial end, 360 have no next payment, 204 have no
         * cancellation or end — every single record has at least one of these.
         */
        public function get_date(string $type): string|int
        {
            $value = trim((string) ($this->spec['dates'][$type] ?? ''));

            return $value === '' ? 0 : $value;
        }

        public function get_date_created(): ?DateTimeImmutable
        {
            $start = $this->get_date('start');

            return is_string($start) ? new DateTimeImmutable($start . ' UTC') : null;
        }
    }
}

// ──────────────────────────────────────────────
// Synthetic ID space
// ──────────────────────────────────────────────

/**
 * Deliberately far above anything the source uses, so a fixture ID appearing in
 * a real log is instantly recognisable as a fixture ID.
 *
 * define() rather than const because this file is required by more than one
 * test class, and a top-level `const` in a re-required file is a redeclaration.
 */
if (!defined('CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID')) {
    define('CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID', 770_001);
}

if (!defined('CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID')) {
    define('CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID', 770_002);
}

/**
 * @param array<string, mixed> $spec
 * @param array<string, mixed> $overrides
 */
$build = static function (array $spec, array $overrides): CartShiftLapkaSubscription {
    // NO `_subscription_length` IS ADDED HERE, AND THAT IS THE POINT.
    //
    // In the preserved Lapka source that key occurs four times in the whole
    // dump, against 1,128 occurrences of `_schedule_next_payment` for 564
    // subscriptions: WooCommerce Subscriptions wrote the term on the two source
    // PRODUCTS and on none of the subscriptions. A fixture that put it on the
    // subscription would model a source that does not exist, and would have
    // hidden the fact that every one of the 564 relies on section 9.2's
    // product fallback. The products carry it — see $monthlyProduct below.

    // Dates merge rather than replace, so an override of one date does not
    // silently drop the other four.
    if (isset($overrides['dates'])) {
        $overrides['dates'] = array_merge($spec['dates'], $overrides['dates']);
    }

    // Meta merges the same way, with one addition: an override of `null`
    // REMOVES the key. A shape needs to be able to express "WooCommerce never
    // wrote this at all", and `''` cannot say it — an empty string is a value
    // WCS does not write either, and the whole distinction under test is
    // absent versus present-and-empty.
    if (isset($overrides['meta'])) {
        $overrides['meta'] = array_filter(
            array_merge($spec['meta'] ?? [], $overrides['meta']),
            static fn (mixed $value): bool => $value !== null,
        );
    }

    return new CartShiftLapkaSubscription(array_merge($spec, $overrides));
};

if (!function_exists('cartshift_lapka_subscription_product')) {
    /**
     * A source subscription product, which is where the term actually lives.
     *
     * Both Lapka source products are unlimited (context pack 4.5), which
     * WooCommerce Subscriptions encodes as `_subscription_length = 0` — a
     * declared answer, not a silence. A plain function rather than a closure
     * because the shape factories below are `static` and would each need a
     * `use` clause to see one.
     */
    function cartshift_lapka_subscription_product(
        int $id,
        string $name,
        string $length = '0',
    ): CartShiftLapkaProduct {
        return new CartShiftLapkaProduct($id, $name, ['_subscription_length' => $length]);
    }
}

/**
 * The shape every factory below starts from: one monthly PLN 29 subscription on
 * Stripe, held by a registered customer, next payment in the future.
 *
 * @return array<string, mixed>
 */
$base = static function (int $id): array {
    return [
        'id'                      => $id,
        'status'                  => 'active',
        'customer_id'             => 660_001,
        'billing_email'           => 'subscriber-660001@example.invalid',
        'parent_id'               => 880_000 + ($id % 1000),
        'currency'                => 'PLN',
        'total'                   => '29.00',
        'total_tax'               => '0.00',
        'billing_period'          => 'month',
        'billing_interval'        => 1,
        'payment_method'          => 'stripe',
        'payment_count'           => 7,
        'requires_manual_renewal' => false,
        'meta'                    => [
            // Synthetic on purpose. Woo Stripe stores the token the renewal
            // charge would use; the plan's Lapka cohort has 120 of these and no
            // `_stripe_subscription_id` at all.
            '_stripe_customer_id' => 'cus_synthetic_fixture_0001',
            '_stripe_source_id'   => 'pm_synthetic_fixture_0001',
        ],
        'items'                   => [
            new CartShiftLapkaItem(
                CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                0,
                'Monthly membership (fixture)',
                1,
                '29.00',
                cartshift_lapka_subscription_product(
                    CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                    'Monthly subscription (fixture)',
                ),
            ),
        ],
        'dates'                   => [
            'start'        => '2023-04-11 09:15:00',
            'trial_end'    => '',
            'next_payment' => '2099-05-11 09:15:00',
            'cancelled'    => '',
            'end'          => '',
        ],
    ];
};

/** @return list<CartShiftLapkaItem> */
$monthlyItem = static fn (string $lineTotal): array => [
    new CartShiftLapkaItem(
        CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
        0,
        'Monthly membership (fixture)',
        1,
        $lineTotal,
        cartshift_lapka_subscription_product(
            CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
            'Monthly subscription (fixture)',
        ),
    ),
];

/** @return list<CartShiftLapkaItem> */
$yearlyItem = static fn (string $lineTotal): array => [
    new CartShiftLapkaItem(
        CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
        0,
        'Yearly membership (fixture)',
        1,
        $lineTotal,
        cartshift_lapka_subscription_product(
            CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
            'Yearly subscription (fixture)',
        ),
    ),
];

return [
    // ──────────────────────────────────────────────
    // Contract shapes: cadence and the amount actually charged
    // ──────────────────────────────────────────────

    /**
     * The current catalogue price, on the current catalogue cadence. 208 of the
     * 375 Lapka monthly subscriptions look like this.
     */
    'monthlyPln29' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build($base(910_001), $overrides),

    /**
     * The older monthly contract. 167 records, and the reason a catalogue-price
     * match cannot be a hard mapping gate: PLN 24 is the subscriber's contract,
     * not a stale copy of PLN 29 to be corrected on the way in.
     */
    'monthlyPln24' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_002), [
            'total' => '24.00',
            'items' => $monthlyItem('24.00'),
        ]), $overrides),

    /** 112 records at the current yearly contract. */
    'yearlyPln290' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_003), [
            'total'            => '290.00',
            'billing_period'   => 'year',
            'billing_interval' => 1,
            'items'            => $yearlyItem('290.00'),
        ]), $overrides),

    /** 76 records at the older yearly contract. */
    'yearlyPln240' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_004), [
            'total'            => '240.00',
            'billing_period'   => 'year',
            'billing_interval' => 1,
            'items'            => $yearlyItem('240.00'),
        ]), $overrides),

    // ──────────────────────────────────────────────
    // Identity
    // ──────────────────────────────────────────────

    /** A subscription owned by a WordPress user on the source site. */
    'registeredCustomer' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build($base(910_005), $overrides),

    /**
     * `_customer_user = 0` with a billing email — 349 of the 564. The plan's
     * cross-site rule resolves these by normalised email, never by copying a
     * source user ID into another WordPress install.
     */
    'guestCustomer' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_006), [
            'customer_id'   => 0,
            'billing_email' => 'guest-910006@example.invalid',
        ]), $overrides),

    // ──────────────────────────────────────────────
    // Gateways — the deliberately small strategy set
    // ──────────────────────────────────────────────

    /**
     * Modern Stripe token. 120 of the 367 Stripe records. No
     * `_stripe_subscription_id`, because none of the 367 has one: these are
     * locally-charged WCS renewals, not remote Stripe subscriptions.
     */
    'stripePaymentMethod' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build($base(910_007), $overrides),

    /**
     * Legacy Stripe source. 246 of the 367, and the reason the plan holds them
     * at `confirmation_required`: a `src_` value must not be posted into
     * FluentCart's payment-method field without sandbox proof.
     */
    'stripeLegacySource' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_008), [
            'meta' => [
                '_stripe_customer_id' => 'cus_synthetic_fixture_0002',
                '_stripe_source_id'   => 'src_synthetic_fixture_0002',
            ],
        ]), $overrides),

    /**
     * PPCP. 71 records, and the restored payment-token table holds no PayPal
     * rows at all — a customer reference is not a reusable billing mandate.
     */
    'paypalGateway' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_009), [
            'payment_method' => 'ppcp-gateway',
            'meta'           => [
                '_ppcp_synthetic_payer_id' => 'PAYER-SYNTHETIC-FIXTURE-0001',
            ],
        ]), $overrides),

    /**
     * Bank transfer, and `requires_manual_renewal` set. The explicit flag beats
     * the gateway slug in the plan's precedence order, so this shape has both.
     */
    'manualRenewal' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_010), [
            'payment_method'          => 'bacs',
            'requires_manual_renewal' => true,
            'meta'                    => [],
        ]), $overrides),

    /**
     * The blank gateway — 55 records. Not a missing value to be filled in with
     * something plausible; it is what the source says.
     */
    'blankGateway' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_011), [
            'payment_method'          => '',
            'requires_manual_renewal' => true,
            'meta'                    => [],
        ]), $overrides),

    // ──────────────────────────────────────────────
    // Statuses
    // ──────────────────────────────────────────────

    /**
     * Active with a next-payment date already in the past. Two of the 78 active
     * Lapka records are this, and neither is activation-ready.
     */
    'activePastDue' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_012), [
            'dates' => array_merge($base(910_012)['dates'], ['next_payment' => '2024-11-02 06:00:00']),
        ]), $overrides),

    /** 355 of the 564. History, not a live instruction. */
    'cancelled' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_013), [
            'status' => 'cancelled',
            'dates'  => array_merge($base(910_013)['dates'], [
                'next_payment' => '',
                'cancelled'    => '2024-02-19 12:41:00',
                'end'          => '2024-02-19 12:41:00',
            ]),
        ]), $overrides),

    /**
     * The malformed active record: no line item, no parent order, blank
     * gateway, a customer, and a future next-payment date. One of these exists
     * and CartShift must block it rather than invent a product, a variation or
     * a parent order to make it fit.
     */
    'malformedNoItemNoParent' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_014), [
            'payment_method' => '',
            'parent_id'      => 0,
            'items'          => [],
            'meta'           => [],
        ]), $overrides),

    /**
     * Not present in the Lapka snapshot — all 563 item-bearing records carry
     * exactly one line — but FluentCart's subscription row holds one product
     * contract, so the shape has to exist somewhere the guard can be tested
     * against. "Keep the first item" is data loss, not a migration policy.
     */
    'multiItem' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_015), [
            'total' => '53.00',
            'items' => [
                new CartShiftLapkaItem(
                    CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                    0,
                    'Monthly membership (fixture)',
                    1,
                    '29.00',
                    cartshift_lapka_subscription_product(
                        CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                        'Monthly subscription (fixture)',
                    ),
                ),
                new CartShiftLapkaItem(
                    CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
                    0,
                    'Yearly membership (fixture)',
                    1,
                    '24.00',
                    cartshift_lapka_subscription_product(
                        CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
                        'Yearly subscription (fixture)',
                    ),
                ),
            ],
        ]), $overrides),

    /**
     * Nobody declared a term: not the subscription, not the product.
     *
     * Section 9.2's three states are the subscription's own answer, the
     * product's as fallback, and nothing at all. The Lapka source is the middle
     * one — `_subscription_length` occurs four times in the whole dump, on the
     * two products and on none of the 564 subscriptions — so the third has to
     * be built deliberately, and this is it: the same monthly contract, with a
     * product that declares nothing either.
     *
     * `_subscription_length => null` removes the key rather than blanking it,
     * because "WooCommerce never wrote this" and "WooCommerce wrote an empty
     * string" are different source rows and only the first is what this shape
     * is about.
     */
    'termDeclaredNowhere' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_024), [
            'items' => [
                new CartShiftLapkaItem(
                    CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                    0,
                    'Monthly membership (fixture)',
                    1,
                    '29.00',
                    new CartShiftLapkaProduct(
                        CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                        'Monthly subscription, term unrecorded (fixture)',
                    ),
                ),
            ],
        ]), array_merge($overrides, [
            // Passed as an OVERRIDE, which is the only place the removal
            // actually happens: `$build()` filters nulls out of the merged meta
            // and `array_merge` at the top level would otherwise have replaced
            // the base's meta wholesale, taking the Stripe references with it
            // and making this a two-variable fixture. Merged onto the caller's
            // own meta rather than replacing it, so an override can still add
            // keys without silently restoring the one this shape is about.
            'meta' => array_merge(
                ['_subscription_length' => null],
                (array) ($overrides['meta'] ?? []),
            ),
        ])),

    /** A line item carrying no product reference at all. */
    'itemWithNoProductReference' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_016), [
            'items' => [new CartShiftLapkaItem(0, 0, 'Unreferenced line', 1, '29.00')],
        ]), $overrides),

    /** A line item with no name, which FluentCart's NOT NULL `item_name` refuses. */
    'itemWithNoName' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_017), [
            'items' => [
                new CartShiftLapkaItem(
                    CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                    0,
                    '',
                    1,
                    '29.00',
                    cartshift_lapka_subscription_product(
                        CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                        'Monthly subscription (fixture)',
                    ),
                ),
            ],
        ]), $overrides),

    // ──────────────────────────────────────────────
    // Lifecycle projection (plan section 9.3)
    // ──────────────────────────────────────────────

    /** Terminal, no next date: preserve the status and the null date. */
    'terminalNoNextDate' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_018), [
            'status' => 'cancelled',
            'dates'  => array_merge($base(910_018)['dates'], [
                'next_payment' => '',
                'cancelled'    => '2023-11-30 08:00:00',
            ]),
        ]), $overrides),

    /**
     * On hold, no next date. 125 Lapka records are on hold and 360 have no next
     * date, so this overlap is the common case rather than a corner. The date
     * stays null; nothing may invent one.
     */
    'onHoldNoNextDate' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_019), [
            'status' => 'on-hold',
            'dates'  => array_merge($base(910_019)['dates'], ['next_payment' => '']),
        ]), $overrides),

    /** Active with a future date — 76 of the 78 active records. */
    'activeFutureDate' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build($base(910_020), $overrides),

    /** Active with a past date — the other 2. Blocked until reconciled. */
    'activePastDate' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_021), [
            'dates' => array_merge($base(910_021)['dates'], ['next_payment' => '2024-09-30 07:30:00']),
        ]), $overrides),

    /** Active with no date at all. Nothing owns the next charge. */
    'activeMissingNextDate' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_022), [
            'dates' => array_merge($base(910_022)['dates'], ['next_payment' => '']),
        ]), $overrides),

    /**
     * A finite contract whose paid cycles have already reached its term, while
     * the source still calls it active. `finite_term_state_conflict`: either
     * the source is wrong or the count is, and guessing which is how a customer
     * gets billed a thirteenth time on a twelve-month plan.
     */
    'finitePaidToTermConflict' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_023), [
            'payment_count' => 12,
            'items'         => [
                new CartShiftLapkaItem(
                    CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                    0,
                    'Monthly membership, twelve months (fixture)',
                    1,
                    '29.00',
                    cartshift_lapka_subscription_product(
                        CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                        'Monthly membership, twelve months (fixture)',
                        '12',
                    ),
                ),
            ],
        ]), $overrides),

    // ──────────────────────────────────────────────
    // Shared parent order
    // ──────────────────────────────────────────────

    /**
     * Two live subscriptions pointing at one parent order. FluentCart's renewal
     * service assumes one subscription per parent order, so allocating a shared
     * one is `shared_parent_order_requires_projection` — a block, not a
     * tie-break.
     *
     * Whether the Lapka source contains any of these is NOT established: the
     * plan's verified baseline never measured parent-order multiplicity. See
     * `aggregates()['parent_orders']`, which records that gap explicitly rather
     * than reporting a comfortable zero.
     */
    'sharedParentOrderFirst' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_024), ['parent_id' => 889_900]), $overrides),

    'sharedParentOrderSecond' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_025), ['parent_id' => 889_900]), $overrides),

    // ──────────────────────────────────────────────
    // Typed relationships
    // ──────────────────────────────────────────────

    /**
     * All four WCS relationship types on one subscription.
     *
     * The Lapka snapshot's 4,702 renewal relationships are the bulk of the
     * history, but `switch` and `resubscribe` exist in WCS and a reader that
     * collapses them loses the distinction silently. `payment_count` is 3 — the
     * parent and the two renewals — because switch and resubscribe orders are
     * not renewal charges and must not inflate the paid-cycle evidence.
     */
    'typedRelatedOrders' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_030), [
            'payment_count'  => 3,
            'related_orders' => [
                'parent'      => [880_030],
                'renewal'     => [880_531, 880_532],
                'switch'      => [880_631],
                'resubscribe' => [880_731],
            ],
        ]), $overrides),

    /**
     * One order ID claimed by two relationship types.
     *
     * `dataset_ambiguous_order_relationship`, not a first-wins choice: whichever
     * type happened to be iterated first would decide whether the order becomes
     * a FluentCart renewal or a switch, and the answer would depend on the order
     * of a foreach.
     */
    'ambiguousRelatedOrder' => static fn (array $overrides = []): CartShiftLapkaSubscription
        => $build(array_merge($base(910_031), [
            'payment_count'  => 2,
            'related_orders' => [
                'parent'      => [880_031],
                'renewal'     => [880_541],
                'switch'      => [880_541],
            ],
        ]), $overrides),

    // ──────────────────────────────────────────────
    // Dataset payloads — the canonical record shape
    // ──────────────────────────────────────────────
    //
    // The shapes above are WooCommerce objects: what a live source reads. The
    // payloads below are the canonical, transport-neutral form the same record
    // takes inside a package file. Both go through SubscriptionRecordFactory,
    // and the whole point of having both in one fixture file is that a test can
    // put the live object in one end and the payload in the other and prove the
    // two fingerprints agree. Money is already in FluentCart's integer minor
    // units here, because canonicalisation happens before the fingerprint, not
    // after it.

    /** A registered source customer. */
    'customerPayload' => static fn (array $overrides = []): array => array_merge([
        'source_ref'       => 'customer:660001',
        'source_user_id'   => 660_001,
        'email'            => 'subscriber-660001@example.invalid',
        'billing_identity' => [
            'first_name' => 'Anonymised',
            'last_name'  => 'Fixture',
            'city'       => 'Warszawa',
            'country'    => 'PL',
        ],
    ], $overrides),

    /**
     * A guest. `_customer_user = 0` for 349 of the 564, and every one of them
     * still has a billing email — which is the only thing that can tell them
     * apart, since the numeric ID says zero for all of them.
     */
    'guestCustomerPayload' => static fn (array $overrides = []): array => array_merge([
        'source_ref'       => '',
        'source_user_id'   => null,
        'email'            => 'guest-910006@example.invalid',
        'billing_identity' => ['country' => 'PL'],
    ], $overrides),

    /** The monthly source product, and its pseudo-variation. */
    'monthlyProductPayload' => static fn (array $overrides = []): array => array_merge([
        'source_ref'         => 'product:' . CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
        'source_product_id'  => CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
        'type'               => 'subscription',
        'name'               => 'Monthly membership (fixture)',
        'sku'                => 'FIXTURE-MONTHLY',
        'variations'         => [[
            'source_variation_id'  => 0,
            'pseudo_variation_key' => (string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
            'name'                 => 'Monthly membership (fixture)',
            'sku'                  => 'FIXTURE-MONTHLY',
            'catalogue_price'      => 2900,
            'period'               => 'month',
            'multiplier'           => 1,
        ]],
    ], $overrides),

    'yearlyProductPayload' => static fn (array $overrides = []): array => array_merge([
        'source_ref'        => 'product:' . CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
        'source_product_id' => CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
        'type'              => 'subscription',
        'name'              => 'Yearly membership (fixture)',
        'sku'               => 'FIXTURE-YEARLY',
        'variations'        => [[
            'source_variation_id'  => 0,
            'pseudo_variation_key' => (string) CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
            'name'                 => 'Yearly membership (fixture)',
            'sku'                  => 'FIXTURE-YEARLY',
            'catalogue_price'      => 34_800,
            'period'               => 'year',
            'multiplier'           => 1,
        ]],
    ], $overrides),

    /**
     * A paid parent order carrying one succeeded charge. Its total equals the
     * recurring total for the same reason `get_parent()` above does: both Lapka
     * source products have a zero setup fee, and a parent total plucked out of
     * the air would manufacture the phantom fee the plan reports as a defect.
     */
    'parentOrderPayload' => static fn (array $overrides = []): array => array_merge([
        'source_ref'          => 'order:880001',
        'source_order_id'     => 880_001,
        'status'              => 'completed',
        'currency'            => 'PLN',
        'source_customer_ref' => 'customer:660001',
        'billing_email'       => 'subscriber-660001@example.invalid',
        'addresses'           => ['billing' => ['country' => 'PL']],
        'items'               => [[
            'source_item_id'       => 41_000,
            'source_product_id'    => CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
            'source_variation_id'  => 0,
            'pseudo_variation_key' => (string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
            'name'                 => 'Monthly membership (fixture)',
            'quantity'             => 1,
            'line_total'           => 2900,
            'line_tax'             => 0,
        ]],
        'transactions'        => [[
            'source_transaction_id' => 'txn-fixture-880001',
            'type'                  => 'charge',
            'status'                => 'succeeded',
            'total'                 => 2900,
            'currency'              => 'PLN',
            'gateway'               => 'stripe',
            'paid_at_utc'           => '2023-04-11 09:15:00',
        ]],
        'totals'              => ['subtotal' => 2900, 'tax' => 0, 'total' => 2900, 'refunded' => 0],
        'dates'               => [
            'created_utc' => '2023-04-11 09:15:00',
            'paid_utc'    => '2023-04-11 09:15:00',
        ],
    ], $overrides),

    /** A renewal order, same shape, later date, different ID. */
    'renewalOrderPayload' => static fn (array $overrides = []): array => array_merge([
        'source_ref'          => 'order:880501',
        'source_order_id'     => 880_501,
        'status'              => 'completed',
        'currency'            => 'PLN',
        'source_customer_ref' => 'customer:660001',
        'billing_email'       => 'subscriber-660001@example.invalid',
        'addresses'           => ['billing' => ['country' => 'PL']],
        'items'               => [[
            'source_item_id'       => 41_500,
            'source_product_id'    => CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
            'source_variation_id'  => 0,
            'pseudo_variation_key' => (string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
            'name'                 => 'Monthly membership (fixture)',
            'quantity'             => 1,
            'line_total'           => 2900,
            'line_tax'             => 0,
        ]],
        'transactions'        => [[
            'source_transaction_id' => 'txn-fixture-880501',
            'type'                  => 'charge',
            'status'                => 'succeeded',
            'total'                 => 2900,
            'currency'              => 'PLN',
            'gateway'               => 'stripe',
            'paid_at_utc'           => '2023-05-11 09:15:00',
        ]],
        'totals'              => ['subtotal' => 2900, 'tax' => 0, 'total' => 2900, 'refunded' => 0],
        'dates'               => [
            'created_utc' => '2023-05-11 09:15:00',
            'paid_utc'    => '2023-05-11 09:15:00',
        ],
    ], $overrides),

    /**
     * The canonical form of `monthlyPln29`, with its parent and one renewal
     * declared under their own relationship types. Two paid orders, so
     * `source_payment_count` is 2 and the history evidence agrees with it.
     */
    'subscriptionPayload' => static fn (array $overrides = []): array => array_merge([
        'source_ref'             => 'subscription:910001',
        'source_subscription_id' => 910_001,
        'status'                 => 'active',
        'currency'               => 'PLN',
        'source_customer_ref'    => 'customer:660001',
        'source_customer_id'     => 660_001,
        'billing_email'          => 'subscriber-660001@example.invalid',
        'billing_identity'       => ['country' => 'PL'],
        'parent_order_id'        => 880_001,
        'items'                  => [[
            'source_item_id'       => 41_000,
            'source_product_id'    => CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
            'source_variation_id'  => 0,
            'pseudo_variation_key' => (string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
            'name'                 => 'Monthly membership (fixture)',
            'quantity'             => 1,
            'line_total'           => 2900,
            'line_tax'             => 0,
        ]],
        'contract'               => [
            'period'           => 'month',
            'multiplier'       => 1,
            'recurring_amount' => 2900,
            'recurring_tax'    => 0,
            'recurring_total'  => 2900,
            'finite_cycles'    => null,
            'trial_length'     => 0,
            'trial_period'     => '',
            'setup_fee'        => 0,
            'source_plan'      => [],
        ],
        'gateway'                => 'stripe',
        'requires_manual_renewal' => false,
        'payment_references'      => [
            'stripe_customer_id' => 'cus_synthetic_fixture_0001',
            'stripe_source_id'   => 'pm_synthetic_fixture_0001',
        ],
        'dates'                  => [
            'start_utc'        => '2023-04-11 09:15:00',
            'trial_end_utc'    => null,
            'next_payment_utc' => '2099-05-11 09:15:00',
            'cancelled_utc'    => null,
            'end_utc'          => null,
        ],
        'related_orders'         => [
            ['source_order_id' => 880_001, 'relationship' => 'parent'],
            ['source_order_id' => 880_501, 'relationship' => 'renewal'],
        ],
        'source_payment_count'   => 2,
    ], $overrides),

    // ──────────────────────────────────────────────
    // Population characterisation
    // ──────────────────────────────────────────────

    /**
     * The verified Lapka baseline as counts. Population statistics only — no
     * identity, no payment references, nothing that could identify a customer.
     *
     * @return array<string, mixed>
     */
    'aggregates' => static fn (): array => [
        'total' => 564,

        'statuses' => [
            'active'         => 78,
            'cancelled'      => 355,
            'on-hold'        => 125,
            'pending'        => 1,
            'pending-cancel' => 5,
        ],

        'cadence' => [
            'monthly' => 375,
            'yearly'  => 189,
        ],

        'currencies' => ['PLN' => 564],

        // The 360 is the headline of this file. A reconciler that produces 564
        // next-payment dates from a source holding 204 has invented 360, and
        // the only way to catch that is to have written the 360 down first.
        'next_payment_dates' => [
            'missing' => 360,
            'past'    => 127,
            'future'  => 77,
        ],

        /**
         * Where `_subscription_length` actually is, counted so nobody has to
         * open a 174 MB dump to settle it again.
         *
         * Occurrences of the key across the whole preserved source
         * (`runtime/db/klub.sql`), beside `_schedule_next_payment` as the
         * control: that one is per-subscription meta and appears 1,128 times
         * for 564 subscriptions, so a per-subscription key would be in the
         * thousands. `_subscription_length` appears four times, in the same
         * order of magnitude as the other four plan keys — all of which are
         * product meta for the two source products.
         *
         * The consequence is section 9.2's whole point: every one of the 564
         * subscriptions is silent about its own term, and the product's is the
         * only evidence there is.
         */
        'subscription_length_meta' => [
            'occurrences_in_source'       => 4,
            'on_subscriptions'            => 0,
            'on_products'                 => 2,
            'schedule_next_payment_control' => 1128,
        ],

        'active_next_payment_dates' => [
            'future' => 76,
            'past'   => 2,
        ],

        'gateways' => [
            'stripe'        => 367,
            'ppcp-gateway'  => 71,
            'bacs'          => 58,
            ''              => 55,
            'stripe_p24'    => 10,
            'ppcp-blik'     => 3,
        ],

        'manual_renewal' => [
            'required'     => 127,
            'not_required' => 437,
        ],

        'stripe_evidence' => [
            'with_vendor_subscription_id' => 0,
            'payment_method_token'        => 120,
            'legacy_source_token'         => 246,
            'no_usable_token'             => 1,
        ],

        'identity' => [
            'guest_customer_rows'      => 349,
            'guests_with_billing_email' => 349,
            'with_resolvable_email'    => 564,
            'unique_emails'            => 215,
            'target_wordpress_users'   => 518,
            'emails_matching_a_target_user' => 43,
        ],

        'line_items' => [
            'exactly_one' => 563,
            'none'        => 1,
            'more_than_one' => 0,
        ],

        'products' => [
            'monthly' => [
                'subscriptions'         => 375,
                'catalogue_price_minor' => 2900,
                // FluentCart's storage format: PLN 29 -> 2900.
                'recurring_total_minor' => [2900 => 208, 2400 => 167],
            ],
            'yearly' => [
                'subscriptions'         => 189,
                'item_rows'             => 188,
                'catalogue_price_minor' => 34800,
                'recurring_total_minor' => [29000 => 112, 24000 => 76],
                // Recorded verbatim and left unreconciled. The plan's section
                // 4.5 lists "PLN 290 for 112; PLN 240 for 76; one zero" against
                // 188 item rows, and 112 + 76 already accounts for all 188 —
                // so the zero row cannot also be a 189th. Whichever of the four
                // numbers is wrong, inventing a reconciliation here would bake
                // the error in. A verified re-read has to settle it.
                'zero_recurring_total_unreconciled' => 1,
                'malformed_no_item'     => 1,
            ],
        ],

        // NOT MEASURED. The verified baseline never counted how many source
        // subscriptions share a parent order, and a fixture is not the place to
        // guess. Null rather than 0 so a later summary cannot quietly inherit
        // "none" from a fixture that never looked.
        'parent_orders' => [
            'multiplicity_measured'  => false,
            'sharing_a_parent_order' => null,
            'without_a_parent_order' => 1,
        ],

        'renewals' => [
            'relationships' => 4702,
            'statuses'      => [
                'completed' => 4448,
                'failed'    => 114,
                'pending'   => 125,
                'on-hold'   => 6,
                'refunded'  => 6,
                'cancelled' => 3,
            ],
            'paid_renewal_payments' => 4452,
            'paid_parent_payments'  => 513,
        ],

        'target' => [
            'fct_customers'          => 0,
            'fct_orders'             => 0,
            'fct_order_items'        => 0,
            'fct_order_transactions' => 0,
            'fct_subscriptions'      => 0,
            'products'               => 5,
            'variations'             => 13,
        ],
    ],
];
