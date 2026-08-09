<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Source;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\CustomerRecord;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\OrderRecord;
use CartShift\Domain\Subscription\ProductRecord;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Support\MoneyHelper;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Live WooCommerce objects in, canonical payloads out.
 *
 * This class reads WooCommerce and does not decide what a record is. Every
 * payload it assembles goes through `SubscriptionRecordFactory`, which is the
 * only place a payload becomes a record — live and package modes have to funnel
 * through one decoder or they will eventually disagree about something, and the
 * whole point of the fingerprint is that they cannot.
 *
 * Two rules it is built around.
 *
 * RELATIONSHIP TYPES ARE READ ONE AT A TIME. `get_related_orders()` flattens
 * its grouped result and throws the label away, so a single array-valued call
 * cannot tell a renewal from a switch. Four separate typed calls can, and
 * `relatedOrdersByType()` is where they live. The protected
 * `get_related_order_ids()` is never called: it is protected, and reaching into
 * it would bind CartShift to an internal of an add-on that is not even
 * installed on this machine.
 *
 * A SOURCE ROW MAY BE UNREADABLE. Canonicalisation is strict — a record
 * carrying invalid UTF-8, which is exactly what a restored Polish WooCommerce
 * database produces, cannot be encoded and must not be hashed as though it
 * could. Every decode here is therefore wrapped, and an unencodable row becomes
 * a counted `InvalidSourceRecord` whose snapshot contains only values this
 * class controls. An export must not abort because one row out of 564 has a
 * mangled byte in a street name.
 */
final class WooDatasetRecordFactory
{
    /**
     * Section 9.4's dataset code for a row that could not be read at all.
     *
     * Deliberately the same code the decoder uses: from the operator's side
     * "this row is unusable" is one problem, and splitting it by which layer
     * noticed would give retry logic two names for one blocker.
     */
    public const string REASON_INVALID_SOURCE_RECORD = 'invalid_source_record';

    /** The address groups a WooCommerce order carries. */
    private const array ADDRESS_GROUPS = ['billing', 'shipping'];

    /** @var list<string> */
    private const array ADDRESS_FIELDS = [
        'first_name',
        'last_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'phone',
    ];

    /**
     * The WCS product meta that describes a subscription variation's cadence.
     *
     * Read so the mapping screen can gate a target variation on the exact
     * period/multiplier pair rather than on a price that looks about right.
     *
     * @var array<string, string>
     */
    private const array PRODUCT_CADENCE_META = [
        '_subscription_period'          => 'period',
        '_subscription_period_interval' => 'multiplier',
    ];

    public function __construct(
        private readonly SubscriptionRecordFactory $records = new SubscriptionRecordFactory(),
    ) {
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    /**
     * One typed call per relationship, and no flattening.
     *
     * @return array<string, list<int>>
     */
    public function relatedOrdersByType(object $subscription): array
    {
        $byType = [];

        foreach (SubscriptionOrderReference::RELATIONSHIPS as $relationship) {
            $ids = [];

            if (method_exists($subscription, 'get_related_orders')) {
                foreach ((array) $subscription->get_related_orders('ids', $relationship) as $orderId) {
                    $orderId = (int) $orderId;

                    if ($orderId > 0) {
                        $ids[] = $orderId;
                    }
                }
            }

            // Sorted and de-duplicated within one type, so two exports of the
            // same subscription cannot differ because WooCommerce returned the
            // renewals in another order. Duplicates ACROSS types survive on
            // purpose — that is `dataset_ambiguous_order_relationship`, and the
            // closure validator is what says so.
            $ids = array_values(array_unique($ids));
            sort($ids);

            $byType[$relationship] = $ids;
        }

        return $byType;
    }

    // ──────────────────────────────────────────────
    // Records
    // ──────────────────────────────────────────────

    /**
     * @param array<string, list<int>> $relatedOrdersByType
     */
    public function subscription(
        string $sourceKey,
        object $subscription,
        array $relatedOrdersByType,
    ): SubscriptionRecord|InvalidSourceRecord {
        $sourceRef = SubscriptionRecordFactory::ref(
            SubscriptionRecord::KIND,
            (int) $this->call($subscription, 'get_id'),
        );

        return $this->guard(
            $sourceKey,
            SubscriptionRecord::KIND,
            $sourceRef,
            fn (): SubscriptionRecord|InvalidSourceRecord => $this->records->subscriptionFromWoo(
                $sourceKey,
                $subscription,
                $relatedOrdersByType,
            ),
        );
    }

    public function customer(string $sourceKey, object $subscription): CustomerRecord|InvalidSourceRecord
    {
        return $this->guard(
            $sourceKey,
            CustomerRecord::KIND,
            SubscriptionRecordFactory::ref(CustomerRecord::KIND, (int) $this->call($subscription, 'get_customer_id')),
            fn (): CustomerRecord|InvalidSourceRecord => $this->records->customerFromWoo($sourceKey, $subscription),
        );
    }

    public function order(string $sourceKey, object $order): OrderRecord|InvalidSourceRecord
    {
        $orderId = (int) $this->call($order, 'get_id');

        return $this->guard(
            $sourceKey,
            OrderRecord::KIND,
            SubscriptionRecordFactory::ref(OrderRecord::KIND, $orderId),
            fn (): OrderRecord|InvalidSourceRecord => $this->records->orderFromPayload(
                $sourceKey,
                $this->orderPayload($order),
            ),
        );
    }

    /**
     * @param callable(int): (object|null) $resolveVariation
     */
    public function product(
        string $sourceKey,
        object $product,
        callable $resolveVariation,
    ): ProductRecord|InvalidSourceRecord {
        $productId = (int) $this->call($product, 'get_id');

        return $this->guard(
            $sourceKey,
            ProductRecord::KIND,
            SubscriptionRecordFactory::ref(ProductRecord::KIND, $productId),
            fn (): ProductRecord|InvalidSourceRecord => $this->records->productFromPayload(
                $sourceKey,
                $this->productPayload($product, $resolveVariation),
            ),
        );
    }

    /**
     * A source row that could not be read, named and counted.
     *
     * @param list<string>         $reasonCodes
     * @param array<string, mixed> $safeSnapshot
     */
    public function invalid(
        string $sourceKey,
        string $entityKind,
        string $sourceRef,
        array $reasonCodes,
        array $safeSnapshot = [],
    ): InvalidSourceRecord {
        return $this->records->invalid($sourceKey, $entityKind, $sourceRef, $reasonCodes, $safeSnapshot);
    }

    // ──────────────────────────────────────────────
    // Payloads
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(object $order): array
    {
        $orderId = (int) $this->call($order, 'get_id');
        $currency = strtoupper((string) ($this->call($order, 'get_currency') ?? ''));
        $total = MoneyHelper::toCents((string) ($this->call($order, 'get_total') ?? '0'));
        $paidAt = self::utc($this->call($order, 'get_date_paid'));

        return [
            'source_order_id'     => $orderId,
            'status'              => (string) ($this->call($order, 'get_status') ?? ''),
            'currency'            => $currency,
            'source_customer_id'  => (int) ($this->call($order, 'get_customer_id') ?? 0),
            'billing_email'       => (string) ($this->call($order, 'get_billing_email') ?? ''),
            'addresses'           => $this->addresses($order),
            'items'               => $this->lineItems($order),
            // WooCommerce records one payment per order. That single succeeded
            // charge is what FluentCart's `calculateBillCount()` counts, so an
            // order that arrived without one contributes nothing to the paid
            // cycles and the count silently disagrees with the source for ever.
            'transactions'        => $paidAt === null || $total <= 0 ? [] : [[
                'source_transaction_id' => (string) ($this->call($order, 'get_transaction_id') ?: ''),
                'type'                  => 'charge',
                'status'                => 'succeeded',
                'total'                 => $total,
                'currency'              => $currency,
                'gateway'               => (string) ($this->call($order, 'get_payment_method') ?? ''),
                'paid_at_utc'           => $paidAt,
            ]],
            'totals'              => [
                'subtotal' => MoneyHelper::toCents((string) ($this->call($order, 'get_subtotal') ?? '0')),
                'tax'      => MoneyHelper::toCents((string) ($this->call($order, 'get_total_tax') ?? '0')),
                'shipping' => MoneyHelper::toCents((string) ($this->call($order, 'get_shipping_total') ?? '0')),
                'discount' => MoneyHelper::toCents((string) ($this->call($order, 'get_discount_total') ?? '0')),
                'refunded' => MoneyHelper::toCents((string) ($this->call($order, 'get_total_refunded') ?? '0')),
                'total'    => $total,
            ],
            'dates'               => [
                'created_utc'   => self::utc($this->call($order, 'get_date_created')),
                'paid_utc'      => $paidAt,
                'completed_utc' => self::utc($this->call($order, 'get_date_completed')),
            ],
        ];
    }

    /**
     * @param callable(int): (object|null) $resolveVariation
     * @return array<string, mixed>
     */
    private function productPayload(object $product, callable $resolveVariation): array
    {
        $productId = (int) $this->call($product, 'get_id');
        $children = array_values(array_filter(array_map(
            intval(...),
            (array) ($this->call($product, 'get_children') ?? []),
        )));

        // A simple subscription product gets exactly one variation whose pseudo
        // key is the product ID. That is not ceremony: it is what lets both
        // Lapka source products map to the same FluentCart product and pick
        // different target variations, through the mapping decision that
        // already exists rather than a second mapping kingdom.
        if ($children === []) {
            $variations = [$this->variationEntry($product, $productId, 0)];
        } else {
            $variations = [];

            foreach ($children as $childId) {
                $variation = $resolveVariation($childId);

                $variations[] = $variation === null
                    ? ['source_variation_id' => $childId, 'pseudo_variation_key' => (string) $childId]
                    : $this->variationEntry($variation, $productId, $childId);
            }
        }

        return [
            'source_product_id' => $productId,
            'type'              => (string) ($this->call($product, 'get_type') ?? ''),
            'name'              => (string) ($this->call($product, 'get_name') ?? ''),
            'sku'               => (string) ($this->call($product, 'get_sku') ?? ''),
            'variations'        => $variations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variationEntry(object $subject, int $productId, int $variationId): array
    {
        $entry = [
            'source_variation_id'  => $variationId,
            'pseudo_variation_key' => (string) ($variationId > 0 ? $variationId : $productId),
            'name'                 => (string) ($this->call($subject, 'get_name') ?? ''),
            'sku'                  => (string) ($this->call($subject, 'get_sku') ?? ''),
            // The catalogue price, kept as an informational signal only. It can
            // never rewrite a subscriber's contract: 167 Lapka subscribers pay
            // PLN 24 for a product priced at PLN 29, and "correcting" them is
            // not a migration.
            'catalogue_price'      => MoneyHelper::toCents(
                (string) ($this->call($subject, 'get_regular_price') ?: ($this->call($subject, 'get_price') ?: '0')),
            ),
        ];

        foreach (self::PRODUCT_CADENCE_META as $metaKey => $field) {
            $value = trim((string) ($this->call($subject, 'get_meta', $metaKey) ?? ''));

            if ($value !== '') {
                $entry[$field] = $field === 'multiplier' ? (int) $value : $value;
            }
        }

        return $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lineItems(object $order): array
    {
        $items = [];

        // WooCommerce keys line items by order-item ID and that key is the only
        // place the item's own ID appears.
        foreach ((array) ($this->call($order, 'get_items') ?? []) as $itemId => $item) {
            if (!is_object($item)) {
                continue;
            }

            $productId = (int) $item->get_product_id();
            $variationId = (int) $item->get_variation_id();

            $items[] = [
                'source_item_id'       => (int) $itemId,
                'source_product_id'    => $productId,
                'source_variation_id'  => $variationId,
                'pseudo_variation_key' => (string) ($variationId > 0 ? $variationId : $productId),
                'name'                 => (string) $item->get_name(),
                'quantity'             => (int) $item->get_quantity(),
                'line_total'           => MoneyHelper::toCents((string) $item->get_total()),
                'line_tax'             => MoneyHelper::toCents((string) $item->get_total_tax()),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function addresses(object $order): array
    {
        $addresses = [];

        foreach (self::ADDRESS_GROUPS as $group) {
            $address = [];

            foreach (self::ADDRESS_FIELDS as $field) {
                $value = trim((string) ($this->call($order, 'get_' . $group . '_' . $field) ?? ''));

                if ($value !== '') {
                    $address[$field] = $value;
                }
            }

            if ($address !== []) {
                ksort($address);
                $addresses[$group] = $address;
            }
        }

        return $addresses;
    }

    // ──────────────────────────────────────────────
    // Guards
    // ──────────────────────────────────────────────

    /**
     * Decode a source row, or record that it could not be decoded.
     *
     * The snapshot carries the reference and nothing else. Anything copied out
     * of the offending row could be the very bytes that made it unencodable,
     * and an invalid record whose own fingerprint cannot be taken would be a
     * neat recursion into the same failure.
     *
     * @template T of CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord
     * @param callable(): T $decode
     * @return T|InvalidSourceRecord
     */
    private function guard(string $sourceKey, string $entityKind, string $sourceRef, callable $decode): object
    {
        try {
            return $decode();
        } catch (\Throwable $failure) {
            return $this->records->invalid(
                $sourceKey,
                $entityKind,
                $sourceRef,
                [self::REASON_INVALID_SOURCE_RECORD],
                ['unreadable' => true, 'failure' => $failure::class],
            );
        }
    }

    /**
     * Call a getter if the object has one, otherwise answer null.
     *
     * WooCommerce Subscriptions is not installed on this machine, so the source
     * object is whatever the runtime hands over. Guarding each call keeps a
     * missing optional getter from turning into a fatal error halfway through
     * an export.
     */
    private function call(object $subject, string $method, mixed ...$arguments): mixed
    {
        return method_exists($subject, $method) ? $subject->{$method}(...$arguments) : null;
    }

    /**
     * A WooCommerce date object as an explicit UTC `Y-m-d H:i:s` string.
     *
     * Through the timestamp rather than through `format()`: `WC_DateTime`
     * carries the site's timezone, so formatting it renders local time and a
     * package exported from a shop in Warsaw would disagree with the same
     * package read anywhere else. An epoch is the same instant everywhere.
     */
    private static function utc(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return (new DateTimeImmutable('@' . $value->getTimestamp()))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        if (is_object($value) && method_exists($value, 'getTimestamp')) {
            return (new DateTimeImmutable('@' . (int) $value->getTimestamp()))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
