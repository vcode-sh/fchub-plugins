<?php

/**
 * Stubs for PreflightCheck tests.
 *
 * PreflightCheck asks WooCommerce and FluentCart whether they exist and whether HPOS is
 * on. None of them are installed in a unit-test run, so we stand in for them here.
 *
 * The OrderUtil stub is switchable through globals rather than hardcoded, because the
 * interesting cases are all about disagreement between the three signals CartShift
 * reads: HPOS on/off, sync on/off, and sync caught up or not.
 *
 * Everything is guarded so this file is safe to require from more than one test file
 * and safe to load alongside a real WooCommerce, should one ever turn up.
 *
 * Globals honoured:
 *   _cartshift_test_hpos_enabled       bool  OrderUtil::custom_orders_table_usage_is_enabled()
 *   _cartshift_test_hpos_sync_enabled  bool  OrderUtil::custom_orders_table_data_sync_is_enabled()
 *   _cartshift_test_hpos_in_sync       bool  OrderUtil::is_custom_order_tables_in_sync()
 *   _cartshift_test_order_util_throws  bool  make every OrderUtil call throw, to exercise
 *                                            the option fallback
 */

declare(strict_types=1);

namespace {
    // FluentCart presence is a constant check, so it can only ever be switched on.
    if (!defined('FLUENTCART_PLUGIN_PATH')) {
        define('FLUENTCART_PLUGIN_PATH', '/tmp/wordpress/wp-content/plugins/fluent-cart/');
    }

    if (!defined('FLUENTCART_VERSION')) {
        define('FLUENTCART_VERSION', '1.6.0');
    }

    if (!class_exists('WooCommerce', false)) {
        class WooCommerce
        {
        }
    }

    if (!defined('WC_VERSION')) {
        define('WC_VERSION', '11.0.0');
    }
}

namespace Automattic\WooCommerce\Utilities {

    if (!class_exists(OrderUtil::class, false)) {
        /**
         * Stand-in for WooCommerce's OrderUtil.
         *
         * The real one resolves through WooCommerce's DI container and throws when
         * WooCommerce is only half booted — which is why PreflightCheck wraps every call
         * in a try/catch. `_cartshift_test_order_util_throws` reproduces that.
         */
        class OrderUtil
        {
            public static function custom_orders_table_usage_is_enabled(): bool
            {
                self::maybeThrow();

                // Defining this stub must not change the answer for tests that never
                // opted into driving it — those still expect the raw option to decide.
                if (array_key_exists('_cartshift_test_hpos_enabled', $GLOBALS)) {
                    return (bool) $GLOBALS['_cartshift_test_hpos_enabled'];
                }

                return get_option('woocommerce_custom_orders_table_enabled') === 'yes';
            }

            public static function custom_orders_table_data_sync_is_enabled(): bool
            {
                self::maybeThrow();

                if (array_key_exists('_cartshift_test_hpos_sync_enabled', $GLOBALS)) {
                    return (bool) $GLOBALS['_cartshift_test_hpos_sync_enabled'];
                }

                return get_option('woocommerce_custom_orders_table_data_sync_enabled') === 'yes';
            }

            public static function is_custom_order_tables_in_sync(): bool
            {
                self::maybeThrow();

                // Mirrors WooCommerce 11.0.0: false when sync is switched off entirely,
                // regardless of whether the tables actually agree. This is the trap the
                // preflight check has to sidestep.
                if (!self::custom_orders_table_data_sync_is_enabled()) {
                    return false;
                }

                return (bool) ($GLOBALS['_cartshift_test_hpos_in_sync'] ?? true);
            }

            private static function maybeThrow(): void
            {
                if (!empty($GLOBALS['_cartshift_test_order_util_throws'])) {
                    throw new \RuntimeException('WooCommerce container is not booted.');
                }
            }
        }
    }
}
