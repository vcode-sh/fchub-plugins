<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Support\Enums\FcOrderStatus;
use CartShift\Support\Enums\FcOrderType;
use CartShift\Support\Enums\FcPaymentStatus;
use CartShift\Support\MoneyHelper;

final class OrderMapper
{
    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly string $currency,
    ) {}

    /**
     * Map a WC_Order to FluentCart order + related data arrays.
     *
     * @return array{order: array, items: array, addresses: array, transaction: array|null}
     */
    public function map(\WC_Order $order): array
    {
        $wcStatus   = $order->get_status();
        $customerId = $this->resolveCustomerId($order);

        $orderData = [
            'customer_id'           => $customerId,
            'parent_id'             => $this->resolveParentOrderId($order),
            'type'                  => $this->getOrderType($order),
            'status'                => FcOrderStatus::fromWooCommerce($wcStatus)->value,
            'payment_status'        => FcPaymentStatus::fromWooCommerce($wcStatus)->value,
            'payment_method'        => $order->get_payment_method() ?: 'wc_migrated',
            'payment_method_title'  => $order->get_payment_method_title() ?: 'WooCommerce (migrated)',
            'currency'              => $order->get_currency(),
            'subtotal'              => MoneyHelper::toCents($order->get_subtotal(), $this->currency),
            'discount_tax'          => 0,
            'manual_discount_total' => 0,
            'coupon_discount_total' => MoneyHelper::toCents($order->get_discount_total(), $this->currency),
            'shipping_tax'          => MoneyHelper::toCents($order->get_shipping_tax(), $this->currency),
            'shipping_total'        => MoneyHelper::toCents($order->get_shipping_total(), $this->currency),
            'tax_total'             => MoneyHelper::toCents($order->get_total_tax(), $this->currency),
            'tax_behavior'          => $this->getTaxBehavior($order),
            'total_amount'          => MoneyHelper::toCents($order->get_total(), $this->currency),
            'total_paid'            => $this->getTotalPaid($order),
            'total_refund'          => MoneyHelper::toCents($order->get_total_refunded(), $this->currency),
            'rate'                  => self::getExchangeRate($order),
            'note'                  => $order->get_customer_note() ?: '',
            'ip_address'            => $order->get_customer_ip_address() ?: '',
            'mode'                  => 'live',
            'fulfillment_type'      => self::guessFulfillmentType($order),
            'shipping_status'       => 'unshipped',
            'uuid'                  => wp_generate_uuid4(),
            'config'                => array_filter([
                'wc_order_id'    => $order->get_id(),
                'migrated'       => true,
                'shipping_lines' => self::mapShippingLines($order) ?: null,
                'fee_lines'      => self::mapFeeLines($order) ?: null,
            ]),
            'created_at'            => $order->get_date_created()
                ? $order->get_date_created()->date('Y-m-d H:i:s')
                : gmdate('Y-m-d H:i:s'),
            'completed_at'          => $order->get_date_completed()
                ? $order->get_date_completed()->date('Y-m-d H:i:s')
                : null,
        ];

        $mapped = [
            'order'       => $orderData,
            'items'       => $this->mapItems($order),
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
                'created_at'       => $order->get_date_created()
                    ? $order->get_date_created()->date('Y-m-d H:i:s')
                    : gmdate('Y-m-d H:i:s'),
            ];
        }

        return $items;
    }

    /**
     * Map billing and shipping addresses.
     */
    private static function mapAddresses(\WC_Order $order): array
    {
        $addresses = [];

        $billingName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $billingMeta = [];
        if ($order->get_billing_phone()) {
            $billingMeta['other_data']['phone'] = $order->get_billing_phone();
        }
        if ($order->get_billing_company()) {
            $billingMeta['other_data']['company_name'] = $order->get_billing_company();
        }

        $addresses[] = [
            'type'      => 'billing',
            'name'      => $billingName,
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city'      => $order->get_billing_city(),
            'state'     => $order->get_billing_state(),
            'postcode'  => $order->get_billing_postcode(),
            'country'   => $order->get_billing_country(),
            'meta'      => !empty($billingMeta) ? $billingMeta : [],
        ];

        $shippingFirst = $order->get_shipping_first_name();
        if ($shippingFirst) {
            $shippingName = trim($shippingFirst . ' ' . $order->get_shipping_last_name());
            $shippingMeta = [];
            if ($order->get_shipping_company()) {
                $shippingMeta['other_data']['company_name'] = $order->get_shipping_company();
            }

            $addresses[] = [
                'type'      => 'shipping',
                'name'      => $shippingName,
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city'      => $order->get_shipping_city(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'country'   => $order->get_shipping_country(),
                'meta'      => !empty($shippingMeta) ? $shippingMeta : [],
            ];
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
            in_array($wcStatus, ['processing', 'completed'], true) => 'succeeded',
            $wcStatus === 'refunded'                               => 'refunded',
            in_array($wcStatus, ['failed', 'cancelled'], true)    => 'failed',
            default                                                => 'pending',
        };

        return [
            'order_type'          => 'order',
            'vendor_charge_id'    => $order->get_transaction_id() ?: '',
            'payment_method'      => $order->get_payment_method() ?: 'free',
            'payment_mode'        => 'live',
            'payment_method_type' => 'wc_migrated',
            'currency'            => $order->get_currency(),
            'transaction_type'    => 'charge',
            'status'              => $status,
            'total'               => $total,
            'rate'                => self::getExchangeRate($order),
            'meta'                => [
                'wc_order_id'    => $order->get_id(),
                'wc_transaction' => $order->get_transaction_id(),
            ],
            'created_at'          => $order->get_date_paid()
                ? $order->get_date_paid()->date('Y-m-d H:i:s')
                : ($order->get_date_created()
                    ? $order->get_date_created()->date('Y-m-d H:i:s')
                    : gmdate('Y-m-d H:i:s')),
        ];
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
     */
    private function resolveParentOrderId(\WC_Order $order): ?int
    {
        $parentId = $order->get_parent_id();
        if ($parentId) {
            return $this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $parentId);
        }

        return null;
    }

    /**
     * Determine the order type using FcOrderType enum.
     * FIX C3: Never returns 'refund'. Parent orders with renewals = renewal,
     * subscription orders = subscription, everything else = payment.
     */
    private function getOrderType(\WC_Order $order): string
    {
        if ($order->get_parent_id() > 0) {
            if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
                return FcOrderType::Renewal->value;
            }

            return FcOrderType::Payment->value;
        }

        if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
            return FcOrderType::Subscription->value;
        }

        return FcOrderType::Payment->value;
    }

    /**
     * Calculate total paid amount.
     */
    private function getTotalPaid(\WC_Order $order): int
    {
        $wcStatus = $order->get_status();

        if (in_array($wcStatus, ['processing', 'completed'], true)) {
            return MoneyHelper::toCents($order->get_total(), $this->currency)
                - MoneyHelper::toCents($order->get_total_refunded(), $this->currency);
        }

        return 0;
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
