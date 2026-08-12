<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\OrderIdentity;
use CartShift\Domain\Subscription\SubscriptionHistoryIndex;
use CartShift\Domain\Transfer\Order\AddressProjection;
use CartShift\Domain\Transfer\Order\AddressRecord;
use CartShift\Domain\Transfer\Order\OrderStatusPolicy;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\MoneyHelper;
use CartShift\Support\UtcDateTime;
use CartShift\Domain\Transfer\SourceRecordException;

final class OrderMapper
{
    /**
     * Warnings collected during the last map() call, each with its code.
     *
     * @var list<array{message: string, code: MigrationErrorCode}>
     */
    private array $warnings = [];

    /**
     * @param SubscriptionHistoryIndex $history What the dataset says this order's
     *     relationship to a subscription is. Empty by default, which is what
     *     "no subscription dataset was loaded" means and leaves every order a
     *     plain `checkout` exactly as before.
     */
    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly string $currency,
        private readonly SubscriptionHistoryIndex $history = new SubscriptionHistoryIndex(
            Constants::DEFAULT_SOURCE_KEY,
        ),
        private readonly OrderStatusPolicy $statusPolicy = new OrderStatusPolicy(),
    ) {}

    /**
     * Map a WC_Order to FluentCart order + related data arrays.
     *
     * @return array{order: array, items: array, addresses: array, transaction: array|null}
     */
    public function map(\WC_Order $order): array
    {
        $this->warnings = [];

        $wcStatus   = $order->get_status();
        $customerId = $this->resolveCustomerId($order);
        $historicalPaymentMode = $this->historicalPaymentMode($order);
        $statusProjection = $this->statusPolicy->project($wcStatus);

        $this->warnOnDisputedRelationship($order);

        $orderData = [
            'customer_id'           => $customerId,
            'parent_id'             => $this->resolveParentOrderId($order),
            // FluentCart's own vocabulary when the dataset says this order is
            // part of a subscription's history — `subscription` for the parent,
            // `renewal` for a renewal (Status::ORDER_TYPE_*). Mapping every
            // order `checkout` is the plan's P1: `RenewalController`,
            // `CustomerOrderController` and `Subscription::renewalOrders()` all
            // filter on the type, so a renewal typed `checkout` disappears from
            // the subscriber's own invoice list.
            'type'                  => $this->history->fluentCartOrderType($order->get_id()) ?? 'checkout',
            'status'                => $statusProjection->orderStatus,
            'payment_status'        => $statusProjection->paymentStatus,
            'payment_method'        => 'wc_migrated',
            'payment_method_title'  => 'WooCommerce (historical import)',
            'currency'              => $order->get_currency(),
            'subtotal'              => MoneyHelper::toCents($order->get_subtotal(), $this->currency),
            'discount_tax'          => MoneyHelper::toCents($order->get_discount_tax(), $this->currency),
            'manual_discount_total' => 0,
            'coupon_discount_total' => MoneyHelper::toCents($order->get_discount_total(), $this->currency),
            'shipping_tax'          => MoneyHelper::toCents($order->get_shipping_tax(), $this->currency),
            'shipping_total'        => MoneyHelper::toCents($order->get_shipping_total(), $this->currency),
            // Woo's total tax includes shipping tax; FluentCart stores the two
            // ledgers separately and its total equation adds both columns.
            'tax_total'             => $this->getCartTax($order),
            'fee_total'             => $this->getFeeTotal($order),
            'tax_behavior'          => $this->getTaxBehavior($order),
            'total_amount'          => MoneyHelper::toCents($order->get_total(), $this->currency),
            'total_paid'            => $this->getTotalPaid($order),
            'total_refund'          => MoneyHelper::toCents($order->get_total_refunded(), $this->currency),
            'rate'                  => self::getExchangeRate($order),
            'note'                  => $order->get_customer_note() ?: '',
            'ip_address'            => $order->get_customer_ip_address() ?: '',
            'mode'                  => $historicalPaymentMode,
            'fulfillment_type'      => self::guessFulfillmentType($order),
            'shipping_status'       => 'unshipped',
            'invoice_no'            => OrderIdentity::invoiceNo($order->get_id()),
            'uuid'                  => wp_generate_uuid4(),
            'config'                => array_filter([
                'wc_order_id'    => $order->get_id(),
                'migrated'       => true,
                'shipping_lines' => self::mapShippingLines($order) ?: null,
                'fee_lines'      => self::mapFeeLines($order) ?: null,
            ]),
            // UTC. FluentCart's own WooCommerce importer fills fct_orders.created_at from
            // wc_orders.date_created_gmt and falls back to current_time('mysql', true)
            // (app/Modules/WooCommerceMigrator/Services/OrderMigrationService.php:225-226),
            // so every fct_* timestamp this mapper produces is UTC. WC_DateTime::date() renders
            // site-local, which mixed local and UTC values into one column depending on whether
            // the WC date happened to be set.
            'created_at'            => self::toUtcString($order->get_date_created())
                ?? UtcDateTime::target(time()),
            'completed_at'          => self::toUtcString($order->get_date_completed()),
        ];

        $items = $this->mapItems($order);
        $feeItems = $this->mapFeeItems($order);
        if (!empty($feeItems)) {
            $items = array_merge($items, $feeItems);
        }

        $mapped = [
            'order'       => $orderData,
            'items'       => $items,
            'addresses'   => self::mapAddresses($order),
            'transaction' => $this->mapTransaction($order),
        ];

        /** @see 'cartshift/mapper/order' */
        return apply_filters('cartshift/mapper/order', $mapped, $order);
    }

    /**
     * Map order line items.
     */
    private function mapItems(\WC_Order $order): array
    {
        $items = [];

        /** @var list<string> $unlinked Names of items whose product did not migrate. */
        $unlinked = [];

        /** @var list<string> $variantless Names of items whose product resolved and whose variation did not. */
        $variantless = [];

        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $wcProductId   = $item->get_product_id();
            $wcVariationId = $item->get_variation_id();
            $product       = $item->get_product();

            $fcProductId   = $this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcProductId);
            $fcVariationId = null;

            if ($wcVariationId) {
                $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcVariationId);
            }

            if (!$fcVariationId && $fcProductId) {
                $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcProductId);
            }

            // The item still carries its name and its price, so the order's
            // books balance; only the link to a product page is gone. That is
            // the right trade for a record of something that already happened —
            // but it has never been said out loud, which is the part that was
            // wrong. Names are collected and reported once for the whole order.
            if ($wcProductId > 0 && !$fcProductId) {
                $unlinked[] = $item->get_name() !== ''
                    ? sprintf('"%s" (WC product %d)', $item->get_name(), $wcProductId)
                    : sprintf('WC product %d', $wcProductId);
            }

            // The quieter half of the same failure, and the more expensive one
            // to leave unsaid. `object_id` below falls back to 0, and
            // FluentCart's product reporting groups by `object_id` rather than
            // by `post_id` (ProductReportService), so every zeroed line across
            // every product collapses into one nameless bucket and this
            // product's per-variant sales vanish. The order detail page still
            // shows the right name and the right money, which is exactly why
            // nobody notices.
            //
            // Reachable without mapping too — a WooCommerce line item on a
            // variable product can carry _variation_id = 0 — so this is not a
            // warning about links, it is a warning about the line.
            if ($fcProductId && !$fcVariationId) {
                $variantless[] = self::describeItemVariation($item, $wcProductId, $wcVariationId);
            }

            $paymentType = 'onetime';
            $otherInfo   = [];

            if ($product && class_exists('WC_Subscriptions_Product') && \WC_Subscriptions_Product::is_subscription($product)) {
                $paymentType = 'subscription';
                $period   = $product->get_meta('_subscription_period') ?: 'month';
                $interval = (int) ($product->get_meta('_subscription_period_interval') ?: 1);

                $otherInfo['payment_type']    = 'subscription';
                $otherInfo['repeat_interval'] = FcBillingInterval::fromWooCommerce($period, $interval)->value;
                $otherInfo['times']           = (int) ($product->get_meta('_subscription_length') ?: 0);
                $otherInfo['trial_days']      = (int) ($product->get_meta('_subscription_trial_length') ?: 0);
            }

            $fulfillmentType = 'physical';
            if ($product) {
                $fulfillmentType = match (true) {
                    $product->is_downloadable() => 'digital',
                    $product->is_virtual()      => 'service',
                    default                     => 'physical',
                };
            }

            $quantity  = $item->get_quantity();
            $subtotal  = MoneyHelper::toCents($item->get_subtotal(), $this->currency);
            $unitPrice = $quantity > 0
                ? intval(round($subtotal / $quantity))
                : $subtotal;

            $lineTotal = MoneyHelper::toCents($item->get_total(), $this->currency);

            $items[] = [
                'post_id'          => $fcProductId ?: 0,
                'object_id'        => $fcVariationId ?: 0,
                'post_title'       => $item->get_name(),
                'title'            => $item->get_name(),
                'fulfillment_type' => $fulfillmentType,
                'quantity'         => $quantity,
                'unit_price'       => $unitPrice,
                'cost'             => 0,
                'subtotal'         => $subtotal,
                'tax_amount'       => MoneyHelper::toCents($item->get_total_tax(), $this->currency),
                'discount_total'   => MoneyHelper::toCents(
                    $item->get_subtotal() - $item->get_total(),
                    $this->currency,
                ),
                'refund_total'     => 0,
                'line_total'       => $lineTotal,
                'rate'             => self::getExchangeRate($order),
                'payment_type'     => $paymentType,
                'other_info'       => !empty($otherInfo) ? $otherInfo : [],
                'line_meta'        => self::extractItemMeta($item),
                'created_at'       => self::toUtcString($order->get_date_created())
                    ?? UtcDateTime::target(time()),
            ];
        }

        if ($unlinked !== []) {
            $this->warnings[] = [
                'message' => sprintf(
                    'Order #%d contains %d item(s) whose product was not migrated: %s. The items keep their '
                    . 'name and price, so the order total is unchanged, but they link to no product in FluentCart.',
                    $order->get_id(),
                    count($unlinked),
                    implode(', ', $unlinked),
                ),
                'code' => MigrationErrorCode::ProductLinkMissing,
            ];
        }

        if ($variantless !== []) {
            $this->warnings[] = [
                'message' => sprintf(
                    'Order #%d contains %d item(s) linked to a product but to no variant: %s. The order '
                    . 'total and the item names are unaffected, but FluentCart counts per-variant sales by '
                    . 'variant, so these lines will not appear in the product report.',
                    $order->get_id(),
                    count($variantless),
                    implode(', ', $variantless),
                ),
                'code' => MigrationErrorCode::VariationLinkMissing,
            ];
        }

        return $items;
    }

    /**
     * One line item named for a warning about its variation.
     *
     * The WooCommerce variation ID when there is one, and the product ID when
     * there is not — because "variation 0" is not a thing anybody can go and
     * look at, and a line item with no variation ID at all is a distinct problem
     * with a distinct fix.
     */
    private static function describeItemVariation(
        \WC_Order_Item_Product $item,
        int $wcProductId,
        int $wcVariationId,
    ): string {
        $what = $wcVariationId > 0
            ? sprintf('WC variation %d', $wcVariationId)
            : sprintf('WC product %d, no variation on the line', $wcProductId);

        return $item->get_name() !== ''
            ? sprintf('"%s" (%s)', $item->get_name(), $what)
            : $what;
    }

    /**
     * Say so when two subscription relationships claim this order.
     *
     * The order still migrates — it is a real purchase and the money is real —
     * but as a plain `checkout`, because typing it `renewal` on the strength of
     * whichever relationship was read first decides whether it counts towards
     * somebody's paid cycles. That refusal is correct and, without this, utterly
     * silent: on the live order path no closure validator runs, so a disputed
     * order and an order that simply has no subscription look identical in the
     * log and in the database.
     */
    private function warnOnDisputedRelationship(\WC_Order $order): void
    {
        if (!$this->history->isAmbiguous($order->get_id())) {
            return;
        }

        $this->warnings[] = [
            'message' => sprintf(
                'Order #%d is claimed by two different subscription relationships, so CartShift will not '
                . 'decide which it is: it migrated as an ordinary checkout rather than as a renewal. '
                . 'Whichever relationship is right decides whether this order counts towards a '
                . 'subscriber\'s paid cycles, which is not a coin toss. Settle it in WooCommerce and '
                . 're-run.',
                $order->get_id(),
            ),
            'code' => MigrationErrorCode::SubscriptionAmbiguousOrderRelationship,
        ];
    }

    /**
     * Warnings collected during the last map() call.
     *
     * Plain sentences, because that is what every existing caller expects.
     * getCodedWarnings() is the one to reach for when the code matters too.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return array_map(
            static fn (array $warning): string => $warning['message'],
            $this->warnings,
        );
    }

    /**
     * The same warnings, each paired with the reason code it stands for.
     *
     * @return list<array{message: string, code: MigrationErrorCode}>
     */
    public function getCodedWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Map WC fee line items as FC order items (post_id=0).
     * Matches FC's migrateFeeItem() pattern.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapFeeItems(\WC_Order $order): array
    {
        $items = [];

        /** @var \WC_Order_Item_Fee $fee */
        foreach ($order->get_items('fee') as $fee) {
            $feeAmount = MoneyHelper::toCents($fee->get_total(), $this->currency);
            $feeTax    = MoneyHelper::toCents($fee->get_total_tax(), $this->currency);
            $feeTotal  = $feeAmount + $feeTax;

            if ($feeTotal <= 0) {
                continue;
            }

            $items[] = [
                'post_id'          => 0,
                'object_id'        => 0,
                'post_title'       => $fee->get_name(),
                'title'            => $fee->get_name(),
                'fulfillment_type' => 'digital',
                'quantity'         => 1,
                'unit_price'       => $order->get_prices_include_tax() ? $feeTotal : $feeAmount,
                'cost'             => 0,
                'subtotal'         => $order->get_prices_include_tax() ? $feeTotal : $feeAmount,
                // FluentCart CheckoutProcessor keeps fee tax on the order and
                // tax-rate rows, not on the fee item itself.
                'tax_amount'       => 0,
                'discount_total'   => 0,
                'refund_total'     => 0,
                'line_total'       => $order->get_prices_include_tax() ? $feeTotal : $feeAmount,
                'rate'             => self::getExchangeRate($order),
                'payment_type'     => 'fee',
                'other_info'       => ['is_custom' => true],
                'line_meta'        => [],
                'created_at'       => self::toUtcString($order->get_date_created())
                    ?? UtcDateTime::target(time()),
            ];
        }

        return $items;
    }

    /**
     * Map billing and shipping addresses.
     */
    /**
     * One order meta value as a trimmed string.
     *
     * get_meta() hands back '' for an absent key under HPOS and false under some
     * legacy paths, and a hand-edited row can hold anything at all. Everything
     * that is not scalar collapses to '', so the caller's "only when non-empty"
     * test is the only test needed.
     */
    private static function stringMeta(\WC_Order $order, string $key): string
    {
        $value = $order->get_meta($key);

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private static function mapAddresses(\WC_Order $order): array
    {
        $addresses = [];

        $nip = self::stringMeta($order, '_billing_nip');
        $vat = self::stringMeta($order, '_billing_vat_number');
        if ($nip !== '' && $vat !== '') {
            $normalize = static fn (string $value): string => preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';
            $normalizedNip = preg_replace('/\APL/', '', $normalize($nip)) ?? '';
            $normalizedVat = preg_replace('/\APL/', '', $normalize($vat)) ?? '';
            if ($normalizedNip !== $normalizedVat) {
                throw new SourceRecordException('source_identity_conflict', 'Billing NIP and VAT-number evidence disagree.');
            }
        }
        $businessTaxId = $vat !== '' ? $vat : $nip;

        foreach (['billing', 'shipping'] as $index => $type) {
            $getter = static fn (string $field): string => (string) $order->{'get_' . $type . '_' . $field}();
            $record = new AddressRecord(
                new SourceIdentity(
                    'legacy-runtime',
                    'order',
                    $order->get_id() . ':address:' . ($index + 1),
                ),
                $type,
                $getter('first_name'),
                $getter('last_name'),
                $getter('company'),
                $getter('address_1'),
                $getter('address_2'),
                $getter('city'),
                $getter('state'),
                $getter('postcode'),
                $getter('country'),
                $type === 'billing' ? $order->get_billing_email() : '',
                $getter('phone'),
                $type === 'billing' ? $businessTaxId : '',
            );
            $projection = AddressProjection::project($record);
            if ($projection === null) {
                continue;
            }
            $row = $projection->row;
            unset($row['source_identity']);
            $addresses[] = $row;
        }

        return $addresses;
    }

    /**
     * Map the primary payment transaction.
     * FIX M8: Free completed/processing orders get a zero-amount succeeded transaction.
     */
    private function mapTransaction(\WC_Order $order): ?array
    {
        $total    = MoneyHelper::toCents($order->get_total(), $this->currency);
        $wcStatus = $order->get_status();

        // Free order that was paid/completed still needs a transaction record.
        if ($total <= 0 && !in_array($wcStatus, ['processing', 'completed'], true)) {
            return null;
        }

        $status = match (true) {
            in_array($wcStatus, ['processing', 'completed', 'refunded'], true) => 'succeeded',
            in_array($wcStatus, ['failed', 'cancelled'], true)    => 'failed',
            default                                                => 'pending',
        };

        return [
            // FluentCart mirrors the order's own type onto its transaction —
            // `CheckoutProcessor` writes `$this->orderModel->type` and
            // `SubscriptionService` writes `renewal` — and the transaction type
            // is half of what `calculateBillCount()` reads.
            'order_type'          => $this->history->fluentCartOrderType($order->get_id()) ?? 'order',
            'vendor_charge_id'    => '',
            'payment_method'      => 'wc_migrated',
            'payment_mode'        => $this->historicalPaymentMode($order),
            'payment_method_type' => 'historical_provenance',
            'currency'            => $order->get_currency(),
            'transaction_type'    => 'charge',
            'status'              => $status,
            'total'               => $total,
            'rate'                => self::getExchangeRate($order),
            'meta'                => [
                'wc_order_id' => $order->get_id(),
                'cartshift_source_payment' => [
                    'gateway' => $order->get_payment_method(),
                    'source_mode' => $this->historicalPaymentMode($order),
                    'provider_reference' => $order->get_transaction_id(),
                    'evidence_kind' => $order->get_transaction_id() !== ''
                        ? 'provider_reference'
                        : ($total === 0 ? 'free_no_charge' : 'manual_paid_without_provider'),
                ],
            ],
            'created_at'          => self::toUtcString($order->get_date_paid())
                ?? self::toUtcString($order->get_date_created())
                ?? UtcDateTime::target(time()),
        ];
    }

    /**
     * Render a WC_DateTime as a UTC 'Y-m-d H:i:s' string.
     *
     * WC_DateTime::date() formats against getOffsetTimestamp() (site-local); getTimestamp()
     * is the plain UTC epoch, so gmdate() over it is the UTC rendering that every fct_* column
     * expects.
     */
    private static function toUtcString(?object $date): ?string
    {
        return UtcDateTime::target($date);
    }

    private function historicalPaymentMode(\WC_Order $order): string
    {
        $mode = (string) $order->get_meta('_cartshift_historical_payment_mode', true);
        if (!in_array($mode, ['live', 'test'], true)) {
            throw new SourceRecordException(
                'target_schema_unrepresentable',
                'Legacy order mapping requires explicit historical payment-mode evidence.',
            );
        }
        return $mode;
    }

    /**
     * Resolve the FC customer ID for an order.
     */
    private function resolveCustomerId(\WC_Order $order): ?int
    {
        $wcCustomerId = $order->get_customer_id();

        if ($wcCustomerId > 0) {
            $fcId = $this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $wcCustomerId);
            if ($fcId) {
                return $fcId;
            }
        }

        $email = $order->get_billing_email();
        if ($email) {
            $fcId = $this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $email);
            if ($fcId) {
                return $fcId;
            }
        }

        return null;
    }

    /**
     * Resolve parent order ID for renewals.
     *
     * The dataset's typed relationship comes first and `post_parent` second,
     * and the order matters rather more than it looks. WooCommerce Subscriptions
     * renewal orders carry no useful parent link — plan section 4.8 — so on the
     * Lapka source `get_parent_id()` answers 0 for all 4,702 of them. FluentCart
     * parents a renewal on the SUBSCRIPTION'S parent order
     * (`SubscriptionService::createRenewalOrders()`), and both
     * `Subscription::guessNextBillingDate()` and `SystemChargeService` read the
     * history back through `where('parent_id', $subscription->parent_order_id)`.
     * A renewal with a null parent is invisible to every one of them.
     */
    private function resolveParentOrderId(\WC_Order $order): ?int
    {
        $declared = $this->history->parentSourceOrderId($order->get_id());

        if ($declared !== null) {
            return $this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $declared);
        }

        $parentId = $order->get_parent_id();
        if ($parentId) {
            return $this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $parentId);
        }

        return null;
    }

    /**
     * Calculate total paid amount.
     */
    private function getTotalPaid(\WC_Order $order): int
    {
        $wcStatus = $order->get_status();

        if (in_array($wcStatus, ['processing', 'completed', 'refunded'], true)) {
            return MoneyHelper::toCents($order->get_total(), $this->currency);
        }

        return 0;
    }

    /**
     * Woo get_total_tax() includes shipping tax; FluentCart does not.
     */
    private function getCartTax(\WC_Order $order): int
    {
        $totalTax = MoneyHelper::toCents($order->get_total_tax(), $this->currency);
        $shippingTax = MoneyHelper::toCents($order->get_shipping_tax(), $this->currency);

        if ($shippingTax > $totalTax) {
            throw new SourceRecordException(
                'order_money_mismatch',
                'WooCommerce shipping tax exceeds the order total-tax ledger.',
            );
        }

        return $totalTax - $shippingTax;
    }

    /**
     * FluentCart's fee_total is gross for both tax behaviours, even though an
     * exclusive fee item stores only its net subtotal.
     */
    private function getFeeTotal(\WC_Order $order): int
    {
        $total = 0;
        foreach ($order->get_items('fee') as $fee) {
            $amount = MoneyHelper::toCents($fee->get_total(), $this->currency);
            $tax = MoneyHelper::toCents($fee->get_total_tax(), $this->currency);
            if ($amount < 0 || $tax < 0) {
                throw new SourceRecordException(
                    'target_schema_unrepresentable',
                    'Negative or credit-like WooCommerce fees have no proved FluentCart parity.',
                );
            }
            $total += $amount + $tax;
        }

        return $total;
    }

    /**
     * Determine tax_behavior: 0=no tax, 1=exclusive, 2=inclusive.
     * FIX H7: Properly distinguish no-tax, exclusive, and inclusive.
     */
    private function getTaxBehavior(\WC_Order $order): int
    {
        $totalTax = floatval($order->get_total_tax());

        if ($totalTax <= 0) {
            return 0;
        }

        return $order->get_prices_include_tax() ? 2 : 1;
    }

    /**
     * Read the exchange rate from multi-currency plugin meta, falling back to '1'.
     * Checks WCML, Aelia, and generic _currency_rate meta keys.
     */
    private static function getExchangeRate(\WC_Order $order): string
    {
        $metaKeys = [
            '_wcml_order_currency_rate',
            '_wc_aelia_exchange_rate',
            '_currency_rate',
        ];

        foreach ($metaKeys as $key) {
            $value = $order->get_meta($key);
            if ($value !== '' && $value !== false && is_numeric($value)) {
                return (string) $value;
            }
        }

        return '1';
    }

    /**
     * Map WC fee line items to a portable array.
     *
     * @return array<int, array{name: string, total: int, tax: int}>
     */
    private static function mapFeeLines(\WC_Order $order): array
    {
        $lines = [];
        $currency = $order->get_currency();

        /** @var \WC_Order_Item_Fee $fee */
        foreach ($order->get_items('fee') as $fee) {
            $lines[] = [
                'name'  => $fee->get_name(),
                'total' => MoneyHelper::toCents($fee->get_total(), $currency),
                'tax'   => MoneyHelper::toCents($fee->get_total_tax(), $currency),
            ];
        }

        return $lines;
    }

    /**
     * Extract visible (non-internal) meta data from an order item.
     *
     * @return array<int, array{key: string, value: string}>
     */
    private static function extractItemMeta(\WC_Order_Item_Product $item): array
    {
        $meta = [];

        foreach ($item->get_meta_data() as $metaObj) {
            $key = $metaObj->key;

            // Skip internal WC meta (prefixed with _).
            if (str_starts_with($key, '_')) {
                continue;
            }

            $value = $metaObj->value;
            if (is_array($value) || is_object($value)) {
                $value = wp_json_encode($value);
            }

            $meta[] = [
                'key'   => $key,
                'value' => (string) $value,
            ];
        }

        return $meta;
    }

    /**
     * Map WC shipping line items to a portable array.
     * FIX M9: Preserve shipping method details in config.
     *
     * @return array<int, array{method_title: string, total: int, tax: int}>
     */
    private static function mapShippingLines(\WC_Order $order): array
    {
        $lines = [];
        $currency = $order->get_currency();

        /** @var \WC_Order_Item_Shipping $shipping */
        foreach ($order->get_items('shipping') as $shipping) {
            $lines[] = [
                'method_title' => $shipping->get_method_title(),
                'total'        => MoneyHelper::toCents($shipping->get_total(), $currency),
                'tax'          => MoneyHelper::toCents($shipping->get_total_tax(), $currency),
            ];
        }

        return $lines;
    }

    /**
     * Guess the fulfillment type based on order items.
     */
    private static function guessFulfillmentType(\WC_Order $order): string
    {
        $hasPhysical = false;
        $hasDigital  = false;

        foreach ($order->get_items() as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            if ($product->is_downloadable()) {
                $hasDigital = true;
            } elseif (!$product->is_virtual()) {
                $hasPhysical = true;
            }
        }

        if ($hasPhysical) {
            return 'physical';
        }
        if ($hasDigital) {
            return 'digital';
        }

        return 'service';
    }
}
