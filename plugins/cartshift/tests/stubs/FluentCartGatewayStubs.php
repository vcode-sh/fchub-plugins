<?php

declare(strict_types=1);

/**
 * FluentCart's gateway registry and its store-wide subscription policy.
 *
 * The runtime compatibility probe is forbidden from reimplementing FluentCart's
 * settings-and-capability conjunction; it must ask
 * SubscriptionManagementMode::resolveCollectionMethodFor(). A stub that merely
 * returned a canned answer would make every test about *why* a store cannot
 * collect automatically a test of the canned answer, so the logic here is a
 * faithful copy of FluentCart 1.6.0:
 *
 *   SubscriptionManagementMode.php:32-40  getMode()
 *   SubscriptionManagementMode.php:47-56  isSystemChargeEnabled()
 *   SubscriptionManagementMode.php:67-74  resolveCollectionMethodFor()
 *   GatewayManager.php:59-62              gateway()
 *   AbstractPaymentGateway.php:52-55      has()
 *
 * Store settings come from the `fluent_cart_store_settings` option, which is
 * where the real StoreSettings reads them, so a test seeds them through the
 * ordinary `_cartshift_test_options` global.
 *
 * Globals honoured:
 *   _cartshift_test_fc_gateways  array<string, CartShiftFakeGateway>  the registry
 *
 * Registered in tests/stubs/test-bootstrap.php. Guarded throughout so it is
 * safe alongside a real FluentCart, should one ever turn up.
 */

namespace {
    if (!class_exists('CartShiftFakeGateway', false)) {
        /**
         * A registered FluentCart payment gateway, as far as the probe cares.
         *
         * Only `has()` matters: it is the single call behind both
         * `system_subscription` capability reporting and FluentCart's own
         * collection-method resolution.
         */
        final class CartShiftFakeGateway
        {
            /** @param list<string> $supportedFeatures */
            public function __construct(public array $supportedFeatures = [])
            {
            }

            /**
             * Stripe 1.6.0's feature list (Stripe.php:31-32), minus the nested
             * `switch_payment_method` entry the probe never asks about.
             */
            public static function stripe(): self
            {
                return new self([
                    'payment', 'refund', 'webhook', 'custom_payment', 'card_update',
                    'dispute_handler', 'subscriptions', 'zero_recurring',
                    'system_subscription', 'manual_subscription',
                ]);
            }

            /** PayPal 1.6.0's feature list (PayPal.php:27-28). */
            public static function paypal(): self
            {
                return new self([
                    'payment', 'refund', 'webhook', 'custom_payment', 'card_update',
                    'dispute_handler', 'subscriptions', 'resume_subscription',
                    'system_subscription', 'manual_subscription',
                ]);
            }

            /** The same gateway with one feature taken away. */
            public function without(string $feature): self
            {
                return new self(array_values(array_diff($this->supportedFeatures, [$feature])));
            }

            public function has(string $feature): bool
            {
                return in_array($feature, $this->supportedFeatures, true);
            }
        }
    }
}

namespace FluentCart\App\Modules\PaymentMethods\Core {

    if (!class_exists(GatewayManager::class, false)) {
        class GatewayManager
        {
            /**
             * A gateway, or null when nothing registered under that slug.
             *
             * Null is the interesting answer: the plan's supported set is
             * exactly Stripe, PayPal and manual, so an unregistered gateway is
             * a cohort that cannot be migrated at all.
             */
            public static function gateway(string $gatewayName): ?object
            {
                return $GLOBALS['_cartshift_test_fc_gateways'][$gatewayName] ?? null;
            }
        }
    }
}

namespace FluentCart\App\Modules\Subscriptions\Services {

    if (!class_exists(SubscriptionService::class, false)) {
        /**
         * The service plan section 10 forbids, present only so a test can prove
         * nothing calls it.
         *
         * `SubscriptionService::syncSubscriptionStates()` is right for a live
         * renewal and wrong for a historical import: it completes a finite
         * subscription when `bill_count >= bill_times`, clears its next date,
         * and writes `guessNextBillingDate()` into any subscription whose date
         * is empty. 360 of the 564 preserved Lapka subscriptions have an empty
         * one. Without this stub the proof would be "the class does not exist",
         * which stops being a proof the moment somebody loads FluentCart.
         *
         * It records and returns nothing, so a call is loud rather than
         * plausible.
         */
        class SubscriptionService
        {
            /** @param array<string, mixed> $subscriptionUpdateArgs */
            public static function syncSubscriptionStates(
                object $subscriptionModel,
                array $subscriptionUpdateArgs = [],
            ): mixed {
                $GLOBALS['_cartshift_test_fc_sync_subscription_states'][] = (int) ($subscriptionModel->id ?? 0);

                return null;
            }
        }
    }

    if (!class_exists(SubscriptionManagementMode::class, false)) {
        class SubscriptionManagementMode
        {
            public const SETTING_KEY = 'subscription_management_mode';
            public const GATEWAY_MANAGED = 'gateway_managed';
            public const STORE_MANAGED = 'store_managed';
            public const SYSTEM_CHARGE_KEY = 'subscription_system_charge';
            public const MANUAL_FALLBACK_KEY = 'subscription_manual_fallback';
            public const CONFIG_KEY = 'management_mode';

            public static function getMode(): string
            {
                $mode = self::setting(self::SETTING_KEY, self::GATEWAY_MANAGED);

                $mode = apply_filters('fluent_cart/subscription/management_mode', $mode);

                return $mode === self::STORE_MANAGED ? self::STORE_MANAGED : self::GATEWAY_MANAGED;
            }

            public static function isStoreManaged(): bool
            {
                return self::getMode() === self::STORE_MANAGED;
            }

            public static function isSystemChargeEnabled(): bool
            {
                if (!self::isStoreManaged()) {
                    return false;
                }

                $enabled = self::setting(self::SYSTEM_CHARGE_KEY, 'no') === 'yes';

                return (bool) apply_filters('fluent_cart/subscriptions/system_collection_enabled', $enabled);
            }

            public static function resolveCollectionMethodFor($gateway): string
            {
                if (self::isSystemChargeEnabled() && $gateway && $gateway->has('system_subscription')) {
                    return 'system';
                }

                return 'manual';
            }

            private static function setting(string $key, string $default): string
            {
                $settings = get_option('fluent_cart_store_settings', []);

                if (!is_array($settings) || !array_key_exists($key, $settings)) {
                    return $default;
                }

                return (string) $settings[$key];
            }
        }
    }
}
