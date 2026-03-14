<?php

namespace CartShift\Mapper;

defined('ABSPATH') or die;

class StatusMapper
{
    /**
     * Map WooCommerce order status to FluentCart order status.
     * WC statuses come prefixed with "wc-" from wc_get_order_statuses(),
     * but WC_Order::get_status() returns without the prefix.
     */
    public static function orderStatus(string $wcStatus): string
    {
        $wcStatus = str_replace('wc-', '', $wcStatus);

        $map = [
            'pending'    => 'pending',
            'processing' => 'processing',
            'on-hold'    => 'on-hold',
            'completed'  => 'completed',
            'cancelled'  => 'failed',
            'refunded'   => 'refunded',
            'failed'     => 'failed',
        ];

        return $map[$wcStatus] ?? 'pending';
    }

    /**
     * Map WooCommerce order status to FluentCart payment status.
     */
    public static function paymentStatus(string $wcStatus): string
    {
        $wcStatus = str_replace('wc-', '', $wcStatus);

        $map = [
            'pending'    => 'pending',
            'processing' => 'paid',
            'on-hold'    => 'pending',
            'completed'  => 'paid',
            'cancelled'  => 'failed',
            'refunded'   => 'refunded',
            'failed'     => 'failed',
        ];

        return $map[$wcStatus] ?? 'pending';
    }

    /**
     * Map WooCommerce subscription status to FluentCart subscription status.
     */
    public static function subscriptionStatus(string $wcStatus): string
    {
        $wcStatus = str_replace('wc-', '', $wcStatus);

        $map = [
            'active'         => 'active',
            'on-hold'        => 'paused',
            'cancelled'      => 'canceled',
            'expired'        => 'expired',
            'pending-cancel' => 'expiring',
            'pending'        => 'pending',
            'switched'       => 'canceled',
        ];

        return $map[$wcStatus] ?? 'pending';
    }

    /**
     * Map WooCommerce billing period to FluentCart billing interval.
     */
    public static function billingInterval(string $wcPeriod): string
    {
        $map = [
            'day'   => 'daily',
            'week'  => 'weekly',
            'month' => 'monthly',
            'year'  => 'yearly',
        ];

        return $map[$wcPeriod] ?? 'monthly';
    }

    /**
     * Map WooCommerce product status to FluentCart (WordPress) post status.
     */
    public static function productStatus(string $wcStatus): string
    {
        $map = [
            'publish' => 'publish',
            'draft'   => 'draft',
            'pending' => 'draft',
            'private' => 'private',
            'trash'   => 'trash',
        ];

        return $map[$wcStatus] ?? 'draft';
    }

    /**
     * Map WooCommerce coupon type to FluentCart coupon type.
     */
    public static function couponType(string $wcType): string
    {
        $map = [
            'percent'       => 'percentage',
            'fixed_cart'    => 'fixed',
            'fixed_product' => 'fixed',
        ];

        return $map[$wcType] ?? 'percentage';
    }
}
