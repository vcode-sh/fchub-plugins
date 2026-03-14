<?php

namespace CartShift\Mapper;

defined('ABSPATH') or die;

use CartShift\State\IdMap;

class SubscriptionMapper
{
    /**
     * Map a WC_Subscription to FluentCart subscription data.
     *
     * @param \WC_Subscription $subscription
     * @param IdMap            $idMap
     * @return array
     */
    public static function map($subscription, IdMap $idMap): array
    {
        $wcStatus = $subscription->get_status();

        // Resolve parent order.
        $parentOrderId = null;
        $wcParentId = $subscription->get_parent_id();
        if ($wcParentId) {
            $parentOrderId = $idMap->getFcId('order', $wcParentId);
        }

        // Resolve customer.
        $customerId = null;
        $wcCustomerId = $subscription->get_customer_id();
        if ($wcCustomerId) {
            $customerId = $idMap->getFcId('customer', $wcCustomerId);
        }

        // Get the subscription product/variation.
        $productId   = null;
        $variationId = null;
        $itemName    = '';

        foreach ($subscription->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $wcProductId   = $item->get_product_id();
            $wcVariationId = $item->get_variation_id();

            $productId = $idMap->getFcId('product', $wcProductId);

            if ($wcVariationId) {
                $variationId = $idMap->getFcId('variation', $wcVariationId);
            }
            if (!$variationId && $wcProductId) {
                $variationId = $idMap->getFcId('variation', $wcProductId);
            }

            $itemName = $item->get_name();
            break; // FC subscriptions are per-product; use the first item.
        }

        // Billing interval.
        $period   = $subscription->get_billing_period();
        $interval = (int) $subscription->get_billing_interval();
        $billingInterval = StatusMapper::billingInterval($period);

        // Recurring amount (cents).
        $recurringTotal = ProductMapper::toCents($subscription->get_total());
        $recurringTax   = ProductMapper::toCents($subscription->get_total_tax());
        $recurringAmount = $recurringTotal - $recurringTax;

        // Signup fee (from first order, if present).
        $signupFee = 0;
        $parentOrder = $subscription->get_parent();
        if ($parentOrder) {
            $orderTotal = ProductMapper::toCents($parentOrder->get_total());
            if ($orderTotal > $recurringTotal) {
                $signupFee = $orderTotal - $recurringTotal;
            }
        }

        // Bill times: subscription length / interval.
        $length = 0;
        foreach ($subscription->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $length = (int) $product->get_meta('_subscription_length');
            }
            break;
        }
        $billTimes = $length > 0 ? (int) ceil($length / max($interval, 1)) : 0;

        // Bill count: number of completed payments.
        $billCount = (int) $subscription->get_payment_count();

        // Dates.
        $trialEnd       = $subscription->get_date('trial_end');
        $nextPayment    = $subscription->get_date('next_payment');
        $cancelledDate  = $subscription->get_date('cancelled');
        $endDate        = $subscription->get_date('end');

        // Trial days.
        $trialDays = 0;
        if ($trialEnd && $trialEnd !== '0') {
            $startDate = $subscription->get_date('start');
            if ($startDate) {
                $trialDays = max(0, (int) floor(
                    (strtotime($trialEnd) - strtotime($startDate)) / DAY_IN_SECONDS
                ));
            }
        }

        $config = [
            'wc_subscription_id' => $subscription->get_id(),
            'migrated'           => true,
            'currency'           => $subscription->get_currency(),
        ];

        return [
            'customer_id'            => $customerId,
            'parent_order_id'        => $parentOrderId,
            'product_id'             => $productId,
            'variation_id'           => $variationId,
            'item_name'              => $itemName,
            'billing_interval'       => $billingInterval,
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
            'collection_method'      => 'charge_automatically',
            'vendor_customer_id'     => null,
            'vendor_plan_id'         => null,
            'vendor_subscription_id' => null,
            'current_payment_method' => $subscription->get_payment_method() ?: 'wc_migrated',
            'status'                 => StatusMapper::subscriptionStatus($wcStatus),
            'original_plan'          => null,
            'vendor_response'        => null,
            'config'                 => json_encode($config),
            'created_at'             => $subscription->get_date('start')
                ?: gmdate('Y-m-d H:i:s'),
        ];
    }
}
