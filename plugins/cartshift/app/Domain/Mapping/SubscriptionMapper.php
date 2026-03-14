<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Support\Enums\FcSubscriptionStatus;
use CartShift\Support\MoneyHelper;

final class SubscriptionMapper
{
    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly string $currency,
    ) {}

    /** @var string[] Warnings collected during the last map() call. */
    private array $warnings = [];

    /**
     * Map a WC_Subscription to FluentCart subscription data.
     */
    public function map(mixed $subscription): array
    {
        $this->warnings = [];

        $wcStatus = $subscription->get_status();

        $parentOrderId = null;
        $wcParentId    = $subscription->get_parent_id();
        if ($wcParentId) {
            $parentOrderId = $this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $wcParentId);
        }

        $customerId   = null;
        $wcCustomerId = $subscription->get_customer_id();
        if ($wcCustomerId) {
            $customerId = $this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $wcCustomerId);
        }

        $productId   = null;
        $variationId = null;
        $itemName    = '';

        // FIX M2: detect multi-item subscriptions — only the first item is migrated.
        $items = array_values($subscription->get_items());
        if (count($items) > 1) {
            $droppedNames = array_map(
                static fn ($item) => $item->get_name(),
                array_slice($items, 1),
            );
            $this->warnings[] = sprintf(
                'Subscription #%d has %d items — only the first will be migrated. Items dropped: [%s]',
                $subscription->get_id(),
                count($items),
                implode(', ', $droppedNames),
            );
        }

        if (!empty($items)) {
            /** @var \WC_Order_Item_Product $firstItem */
            $firstItem     = $items[0];
            $wcProductId   = $firstItem->get_product_id();
            $wcVariationId = $firstItem->get_variation_id();

            $productId = $this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcProductId);

            if ($wcVariationId) {
                $variationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcVariationId);
            }
            if (!$variationId && $wcProductId) {
                $variationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcProductId);
            }

            $itemName = $firstItem->get_name();
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

        // FIX M1: map vendor IDs from WC subscription meta.
        $paymentMethod = $subscription->get_payment_method() ?: '';
        [$vendorCustomerId, $vendorPlanId, $vendorSubscriptionId] = $this->resolveVendorIds(
            $subscription,
            $paymentMethod,
        );

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
            'vendor_customer_id'     => $vendorCustomerId,
            'vendor_plan_id'         => $vendorPlanId,
            'vendor_subscription_id' => $vendorSubscriptionId,
            'current_payment_method' => $paymentMethod ?: 'wc_migrated',
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

    /**
     * Warnings collected during the last map() call.
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * FIX M1: resolve gateway-specific vendor IDs from WC subscription meta.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [customer, plan, subscription]
     */
    private function resolveVendorIds(mixed $subscription, string $paymentMethod): array
    {
        // Stripe.
        $stripeCustomerId     = $subscription->get_meta('_stripe_customer_id') ?: null;
        $stripePlanId         = $subscription->get_meta('_stripe_plan_id') ?: null;
        $stripeSubscriptionId = $subscription->get_meta('_stripe_subscription_id') ?: null;

        if ($stripeCustomerId || $stripeSubscriptionId) {
            return [$stripeCustomerId, $stripePlanId, $stripeSubscriptionId];
        }

        // PayPal.
        $paypalSubscriptionId = $subscription->get_meta('_paypal_subscription_id') ?: null;

        if ($paypalSubscriptionId) {
            return [$paypalSubscriptionId, null, $paypalSubscriptionId];
        }

        // Unknown gateway with a payment method set — log for visibility.
        if ($paymentMethod && !in_array($paymentMethod, ['', 'manual', 'bacs', 'cheque', 'cod'], true)) {
            $this->warnings[] = sprintf(
                'Subscription #%d uses gateway "%s" — no vendor ID mapping defined. vendor_*_id fields left empty.',
                $subscription->get_id(),
                $paymentMethod,
            );
        }

        return [null, null, null];
    }
}
