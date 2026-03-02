<?php

namespace FChubMemberships\FluentCRM\Helpers;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

class CheckoutUrlHelper
{
    /**
     * Get the FluentCart checkout URL for a given plan.
     *
     * Looks up the integration feed that links the plan to a FluentCart product,
     * gets the product's default variant ID, and builds an instant checkout URL.
     *
     * @return string Checkout URL or empty string if unavailable
     */
    public static function getCheckoutUrl(int $planId): string
    {
        $productId = self::getLinkedProductId($planId);
        if (!$productId) {
            return '';
        }

        $variantId = self::getDefaultVariantId($productId);
        if (!$variantId) {
            return '';
        }

        $checkoutPageUrl = self::getCheckoutPageUrl();
        if (!$checkoutPageUrl) {
            return '';
        }

        return add_query_arg('fct_cart_hash', $variantId, $checkoutPageUrl);
    }

    /**
     * Get the checkout URL for the next higher-tier plan.
     *
     * Finds the user's current plan level, then locates the next higher-level
     * plan that has a linked FluentCart product.
     *
     * @return string Upgrade URL or empty string if already on highest plan
     */
    public static function getUpgradeUrl(int $currentPlanId): string
    {
        $planRepo = new PlanRepository();
        $currentPlan = $planRepo->find($currentPlanId);

        if (!$currentPlan) {
            return '';
        }

        $currentLevel = (int) ($currentPlan['level'] ?? 0);

        $allPlans = $planRepo->getActivePlans();

        // Find the next higher-level plan that has a linked product
        $candidate = null;
        foreach ($allPlans as $plan) {
            if ((int) $plan['level'] <= $currentLevel) {
                continue;
            }
            if ($plan['id'] === $currentPlanId) {
                continue;
            }

            $productId = self::getLinkedProductId($plan['id']);
            if (!$productId) {
                continue;
            }

            if ($candidate === null || (int) $plan['level'] < (int) $candidate['level']) {
                $candidate = $plan;
            }
        }

        if (!$candidate) {
            return '';
        }

        return self::getCheckoutUrl($candidate['id']);
    }

    /**
     * Get the next billing date from a FluentCart subscription.
     *
     * @param int $subscriptionId The FluentCart subscription ID
     * @return string Formatted date or empty string
     */
    public static function getNextBillingDate(int $subscriptionId): string
    {
        if (!class_exists('FluentCart\App\Models\Subscription')) {
            return '';
        }

        $subscription = \FluentCart\App\Models\Subscription::find($subscriptionId);
        if (!$subscription) {
            return '';
        }

        $activeStatuses = ['active', 'trialing'];
        if (!in_array($subscription->status, $activeStatuses, true)) {
            return '';
        }

        $nextBillingDate = $subscription->next_billing_date;
        if (!$nextBillingDate) {
            return '';
        }

        return date_i18n(get_option('date_format'), strtotime($nextBillingDate));
    }

    /**
     * Get the payment update URL for a FluentCart subscription.
     *
     * @param int $subscriptionId The FluentCart subscription ID
     * @return string Payment update URL or empty string
     */
    public static function getPaymentUpdateUrl(int $subscriptionId): string
    {
        if (!class_exists('FluentCart\App\Models\Subscription')) {
            return '';
        }

        $subscription = \FluentCart\App\Models\Subscription::find($subscriptionId);
        if (!$subscription || empty($subscription->uuid)) {
            return '';
        }

        if (!class_exists('FluentCart\App\Services\TemplateService')) {
            return '';
        }

        return \FluentCart\App\Services\TemplateService::getCustomerProfileUrl(
            'subscription/' . $subscription->uuid
        );
    }

    /**
     * Get the linked FluentCart product ID for a membership plan.
     *
     * Queries the fct_order_integration_feeds table for feeds with
     * integration_key='memberships' that reference this plan.
     *
     * @return int|null Product ID or null
     */
    public static function getLinkedProductId(int $planId): ?int
    {
        global $wpdb;

        $planRepo = new PlanRepository();
        $plan = $planRepo->find($planId);
        if (!$plan) {
            return null;
        }

        $feedsTable = $wpdb->prefix . 'fct_order_integration_feeds';
        $feeds = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, settings FROM {$feedsTable} WHERE integration_key = %s AND enabled = 1",
            'memberships'
        ), ARRAY_A);

        foreach ($feeds ?: [] as $feed) {
            $settings = json_decode($feed['settings'] ?? '{}', true) ?: [];
            $matchBySlug = ($settings['plan_slug'] ?? '') === $plan['slug'];
            $matchById = (int) ($settings['plan_id'] ?? 0) === $planId;

            if ($matchBySlug || $matchById) {
                return (int) $feed['product_id'];
            }
        }

        return null;
    }

    /**
     * Get the default variant ID for a FluentCart product.
     *
     * Uses ProductDetail.default_variation_id if set, otherwise the first variant.
     *
     * @return int|null Variant ID or null
     */
    public static function getDefaultVariantId(int $productId): ?int
    {
        global $wpdb;

        $detailsTable = $wpdb->prefix . 'fct_product_details';
        $detail = $wpdb->get_row($wpdb->prepare(
            "SELECT default_variation_id FROM {$detailsTable} WHERE post_id = %d",
            $productId
        ), ARRAY_A);

        if ($detail && !empty($detail['default_variation_id'])) {
            return (int) $detail['default_variation_id'];
        }

        // Fall back to the first variant
        $variationsTable = $wpdb->prefix . 'fct_product_variations';
        $variantId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$variationsTable} WHERE post_id = %d ORDER BY serial_index ASC LIMIT 1",
            $productId
        ));

        return $variantId ? (int) $variantId : null;
    }

    /**
     * Get the FluentCart checkout page URL.
     *
     * @return string Checkout page URL or empty string
     */
    public static function getCheckoutPageUrl(): string
    {
        if (!class_exists('FluentCart\Api\StoreSettings')) {
            return '';
        }

        $storeSettings = new \FluentCart\Api\StoreSettings();
        return $storeSettings->getCheckoutPage();
    }

    /**
     * Find the subscription ID from a user's active grant.
     *
     * @return int|null Subscription ID or null
     */
    public static function getSubscriptionIdFromGrant(array $grant): ?int
    {
        if (($grant['source_type'] ?? '') !== 'subscription') {
            return null;
        }

        $sourceId = (int) ($grant['source_id'] ?? 0);
        return $sourceId > 0 ? $sourceId : null;
    }
}
