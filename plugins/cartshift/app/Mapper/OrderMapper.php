<?php

namespace CartShift\Mapper;

defined('ABSPATH') or die;

use CartShift\State\IdMap;

class OrderMapper
{
    /**
     * Map a WC_Order to FluentCart order + related data arrays.
     *
     * @param \WC_Order $order
     * @param IdMap     $idMap
     * @return array{order: array, items: array, addresses: array, transaction: array|null}
     */
    public static function map(\WC_Order $order, IdMap $idMap): array
    {
        $wcStatus = $order->get_status();
        $customerId = self::resolveCustomerId($order, $idMap);

        $orderData = [
            'customer_id'          => $customerId,
            'parent_id'            => self::resolveParentOrderId($order, $idMap),
            'type'                 => self::getOrderType($order),
            'status'               => StatusMapper::orderStatus($wcStatus),
            'payment_status'       => StatusMapper::paymentStatus($wcStatus),
            'payment_method'       => $order->get_payment_method() ?: 'wc_migrated',
            'payment_method_title' => $order->get_payment_method_title() ?: 'WooCommerce (migrated)',
            'currency'             => $order->get_currency(),
            'subtotal'             => ProductMapper::toCents($order->get_subtotal()),
            'discount_tax'         => 0,
            'manual_discount_total'=> 0,
            'coupon_discount_total'=> ProductMapper::toCents($order->get_discount_total()),
            'shipping_tax'         => ProductMapper::toCents($order->get_shipping_tax()),
            'shipping_total'       => ProductMapper::toCents($order->get_shipping_total()),
            'tax_total'            => ProductMapper::toCents($order->get_total_tax()),
            'tax_behavior'         => $order->get_prices_include_tax() ? 1 : 0,
            'total_amount'         => ProductMapper::toCents($order->get_total()),
            'total_paid'           => self::getTotalPaid($order),
            'total_refund'         => ProductMapper::toCents($order->get_total_refunded()),
            'rate'                 => 1,
            'note'                 => $order->get_customer_note() ?: '',
            'ip_address'           => $order->get_customer_ip_address() ?: '',
            'mode'                 => 'live',
            'fulfillment_type'     => self::guessFulfillmentType($order),
            'shipping_status'      => 'unshipped',
            'uuid'                 => wp_generate_uuid4(),
            'config'               => [
                'wc_order_id' => $order->get_id(),
                'migrated'    => true,
            ],
            'created_at'           => $order->get_date_created()
                ? $order->get_date_created()->date('Y-m-d H:i:s')
                : gmdate('Y-m-d H:i:s'),
            'completed_at'         => $order->get_date_completed()
                ? $order->get_date_completed()->date('Y-m-d H:i:s')
                : null,
        ];

        // Order items.
        $items = self::mapItems($order, $idMap);

        // Order addresses.
        $addresses = self::mapAddresses($order);

        // Order transaction.
        $transaction = self::mapTransaction($order);

        return [
            'order'       => $orderData,
            'items'       => $items,
            'addresses'   => $addresses,
            'transaction' => $transaction,
        ];
    }

    /**
     * Map order line items.
     */
    private static function mapItems(\WC_Order $order, IdMap $idMap): array
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

            // Resolve FC product and variation IDs.
            $fcProductId   = $idMap->getFcId('product', $wcProductId);
            $fcVariationId = null;

            if ($wcVariationId) {
                $fcVariationId = $idMap->getFcId('variation', $wcVariationId);
            }

            // Fallback: try using the simple product's first variation.
            if (!$fcVariationId && $fcProductId) {
                $fcVariationId = $idMap->getFcId('variation', $wcProductId);
            }

            $paymentType = 'onetime';
            $otherInfo   = [];

            if ($product && class_exists('WC_Subscriptions_Product') && \WC_Subscriptions_Product::is_subscription($product)) {
                $paymentType = 'subscription';
                $period = $product->get_meta('_subscription_period') ?: 'month';
                $otherInfo['payment_type']    = 'subscription';
                $otherInfo['repeat_interval'] = StatusMapper::billingInterval($period);
                $otherInfo['times']           = (int) ($product->get_meta('_subscription_length') ?: 0);
                $otherInfo['trial_days']      = (int) ($product->get_meta('_subscription_trial_length') ?: 0);
            }

            $fulfillmentType = 'physical';
            if ($product) {
                if ($product->is_downloadable()) {
                    $fulfillmentType = 'digital';
                } elseif ($product->is_virtual()) {
                    $fulfillmentType = 'service';
                }
            }

            $lineTotal = ProductMapper::toCents($item->get_total());
            $unitPrice = $item->get_quantity() > 0
                ? intval(round($lineTotal / $item->get_quantity()))
                : $lineTotal;

            $items[] = [
                'post_id'          => $fcProductId ?: 0,
                'object_id'        => $fcVariationId ?: 0,
                'post_title'       => $item->get_name(),
                'title'            => $item->get_name(),
                'fulfillment_type' => $fulfillmentType,
                'quantity'         => $item->get_quantity(),
                'unit_price'       => $unitPrice,
                'cost'             => 0,
                'subtotal'         => ProductMapper::toCents($item->get_subtotal()),
                'tax_amount'       => ProductMapper::toCents($item->get_total_tax()),
                'discount_total'   => ProductMapper::toCents($item->get_subtotal() - $item->get_total()),
                'refund_total'     => 0,
                'line_total'       => $lineTotal,
                'rate'             => 1,
                'payment_type'     => $paymentType,
                'other_info'       => !empty($otherInfo) ? $otherInfo : [],
                'line_meta'        => [],
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

        // Billing address.
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
            'meta'      => !empty($billingMeta) ? json_encode($billingMeta) : '[]',
        ];

        // Shipping address (if present).
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
                'meta'      => !empty($shippingMeta) ? json_encode($shippingMeta) : '[]',
            ];
        }

        return $addresses;
    }

    /**
     * Map the primary payment transaction.
     */
    private static function mapTransaction(\WC_Order $order): ?array
    {
        $total = ProductMapper::toCents($order->get_total());
        if ($total <= 0) {
            return null;
        }

        $status = 'pending';
        $wcStatus = $order->get_status();
        if (in_array($wcStatus, ['processing', 'completed'], true)) {
            $status = 'succeeded';
        } elseif ($wcStatus === 'refunded') {
            $status = 'refunded';
        } elseif (in_array($wcStatus, ['failed', 'cancelled'], true)) {
            $status = 'failed';
        }

        return [
            'order_type'          => 'order',
            'vendor_charge_id'    => $order->get_transaction_id() ?: '',
            'payment_method'      => $order->get_payment_method() ?: 'wc_migrated',
            'payment_mode'        => 'live',
            'payment_method_type' => 'wc_migrated',
            'currency'            => $order->get_currency(),
            'transaction_type'    => 'charge',
            'status'              => $status,
            'total'               => $total,
            'rate'                => 1,
            'meta'                => json_encode([
                'wc_order_id'     => $order->get_id(),
                'wc_transaction'  => $order->get_transaction_id(),
            ]),
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
    private static function resolveCustomerId(\WC_Order $order, IdMap $idMap): ?int
    {
        $wcCustomerId = $order->get_customer_id();

        if ($wcCustomerId > 0) {
            $fcId = $idMap->getFcId('customer', $wcCustomerId);
            if ($fcId) {
                return $fcId;
            }
        }

        // For guests, try by email.
        $email = $order->get_billing_email();
        if ($email) {
            $fcId = $idMap->getFcId('guest_customer', crc32($email));
            if ($fcId) {
                return $fcId;
            }
        }

        return null;
    }

    /**
     * Resolve parent order ID for renewals/refunds.
     */
    private static function resolveParentOrderId(\WC_Order $order, IdMap $idMap): ?int
    {
        $parentId = $order->get_parent_id();
        if ($parentId) {
            return $idMap->getFcId('order', $parentId);
        }
        return null;
    }

    /**
     * Determine the order type.
     */
    private static function getOrderType(\WC_Order $order): string
    {
        if ($order->get_parent_id() > 0) {
            // Check if this is a WCS renewal.
            if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
                return 'renewal';
            }
            return 'refund';
        }

        // Check if the order contains subscription products.
        if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
            return 'subscription';
        }

        return 'onetime';
    }

    /**
     * Calculate total paid amount.
     */
    private static function getTotalPaid(\WC_Order $order): int
    {
        $wcStatus = $order->get_status();
        if (in_array($wcStatus, ['processing', 'completed'], true)) {
            return ProductMapper::toCents($order->get_total()) - ProductMapper::toCents($order->get_total_refunded());
        }
        if ($wcStatus === 'refunded') {
            return 0;
        }
        return 0;
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
