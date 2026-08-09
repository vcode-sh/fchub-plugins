<?php

declare(strict_types=1);

/**
 * The one predicate VariationMapper::isSubscription() (and, through it,
 * MigrationOrchestratorFactory::orphanSourceIsSubscription()) asks about a
 * WooCommerce product or variation: is this row a subscription.
 *
 * Real WC_Subscriptions_Product::is_subscription() reads the product's own
 * subscription-detection logic, which is considerably more than one meta key.
 * This double only needs to be right about the one thing every call site here
 * actually depends on — that a row carrying WooCommerce Subscriptions' own
 * `_subscription_period` meta answers true and a plain row answers false — so
 * that is the whole of it.
 *
 * NEVER require this file from a test that is not process-isolated. A class,
 * once declared, cannot be undeclared, so loading it in the shared process
 * would silently move VariationMapper's `class_exists('WC_Subscriptions_Product')`
 * gate to true for every later test in the run — including
 * VariationMapperTest::testSubscriptionDataPreservedWithWeightMerge(), which
 * documents that gate as false on purpose. Isolated callers must carry BOTH
 * #[RunInSeparateProcess] and #[PreserveGlobalState(false)]; the first alone
 * re-serialises the parent's declared classes into the child.
 */

if (!class_exists('WC_Subscriptions_Product')) {
    class WC_Subscriptions_Product
    {
        public static function is_subscription(mixed $product): bool
        {
            if (!$product instanceof WC_Product) {
                return false;
            }

            return $product->get_meta('_subscription_period') !== '';
        }
    }
}
