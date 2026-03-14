<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') or die;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Support\Enums\FcSubscriptionStatus;
use CartShift\Support\MoneyHelper;

final class SubscriptionMapper
{
    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly string $currency,
    ) {}

    /**
     * Map a WC_Subscription to FluentCart subscription data.
     */
    public function map(mixed $subscription): array
    {
        $wcStatus = $subscription->get_status();

        $parentOrderId = null;
        $wcParentId    = $subscription->get_parent_id();
        if ($wcParentId) {
            $parentOrderId = $this->idMap->getFcId('order', (string) $wcParentId);
        }

        $customerId   = null;
        $wcCustomerId = $subscription->get_customer_id();
        if ($wcCustomerId) {
            $customerId = $this->idMap->getFcId('customer', (string) $wcCustomerId);
        }

        $productId   = null;
        $variationId = null;
        $itemName    = '';

        foreach ($subscription->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $wcProductId   = $item->get_product_id();
            $wcVariationId = $item->get_variation_id();

            $productId = $this->idMap->getFcId('product', (string) $wcProductId);

            if ($wcVariationId) {
                $variationId = $this->idMap->getFcId('variation', (string) $wcVariationId);
            }
            if (!$variationId && $wcProductId) {
                $variationId = $this->idMap->getFcId('variation', (string) $wcProductId);
            }

            $itemName = $item->get_name();
            break;
        }

        $period   = $subscription->get_billing_period();
        $interval = (int) $subscription->get_billing_interval();

        $recurringTotal  = MoneyHelper::toCents($subscription->get_total(), $this->currency);
        $recurringTax    = MoneyHelper::toCents($subscription->get_total_tax(), $this->currency);
        $recurringAmount = $recurringTotal - $recurringTax;

        $signupFee   = 0;
        $parentOrder = $subscription->get_parent();
        if ($parentOrder) {
            $orderTotal = MoneyHelper::toCents($parentOrder->get_total(), $this->currency);
            if ($orderTotal > $recurringTotal) {
                $signupFee = $orderTotal - $recurringTotal;
            }
        }

        $length = 0;
        foreach ($subscription->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $length = (int) $product->get_meta('_subscription_length');
            }
            break;
        }
        $billTimes = $length > 0 ? (int) ceil($length / max($interval, 1)) : 0;
        $billCount = (int) $subscription->get_payment_count();

        $trialEnd      = $subscription->get_date('trial_end');
        $nextPayment   = $subscription->get_date('next_payment');
        $cancelledDate = $subscription->get_date('cancelled');
        $endDate       = $subscription->get_date('end');

        $trialDays = 0;
        if ($trialEnd && $trialEnd !== '0') {
            $startDate = $subscription->get_date('start');
            if ($startDate) {
                $trialDays = max(0, (int) floor(
                    (strtotime($trialEnd) - strtotime($startDate)) / DAY_IN_SECONDS,
                ));
            }
        }

        $mapped = [
            'customer_id'            => $customerId,
            'parent_order_id'        => $parentOrderId,
            'product_id'             => $productId,
            'variation_id'           => $variationId,
            'item_name'              => $itemName,
            'billing_interval'       => FcBillingInterval::fromWooCommerce($period, $interval)->value,
            'signup_fee'             => $signupFee,
            'quantity'               => 1,
            'recurring_amount'       => $recurringAmount,
            'recurring_tax_total'    => $recurringTax,
            'recurring_total'        => $recurringTotal,
            'bill_times'             => $billTimes,
            'bill_count'             => $billCount,
            'trial_days'             => $trialDays,
            'trial_ends_at'          => ($trialEnd && $trialEnd !== '0') ? $trialEnd : null,
            'next_billing_date'      => ($nextPayment && $nextPayment !== '0') ? $nextPayment : null,
            'expire_at'              => ($endDate && $endDate !== '0') ? $endDate : null,
            'canceled_at'            => ($cancelledDate && $cancelledDate !== '0') ? $cancelledDate : null,
            'restored_at'            => null,
            'collection_method'      => 'automatic',
            'vendor_customer_id'     => null,
            'vendor_plan_id'         => null,
            'vendor_subscription_id' => null,
            'current_payment_method' => $subscription->get_payment_method() ?: 'wc_migrated',
            'status'                 => FcSubscriptionStatus::fromWooCommerce($wcStatus)->value,
            'original_plan'          => null,
            'vendor_response'        => null,
            'config'                 => [
                'wc_subscription_id' => $subscription->get_id(),
                'migrated'           => true,
                'currency'           => $subscription->get_currency(),
            ],
            'created_at'             => $subscription->get_date('start')
                ?: gmdate('Y-m-d H:i:s'),
        ];

        /** @see 'cartshift/mapper/subscription' */
        return apply_filters('cartshift/mapper/subscription', $mapped, $subscription);
    }
}
