<?php

declare(strict_types=1);

/**
 * Extra stubs for the HTTP controller and WP-CLI tests.
 *
 * Everything here is guarded so it can coexist with the shared test bootstrap
 * and with a real WordPress / WooCommerce / Action Scheduler runtime.
 *
 * NOT private to the HTTP/CLI tests despite the name: other suites require this
 * file for wc_get_products(). Before changing a signature or a return shape,
 * grep for `HttpCliStubs` — the callers are not all in tests/Unit/Http.
 *
 * DO NOT define a competing wc_get_products() elsewhere. The `function_exists`
 * guards mean whichever file loads first wins, and a second definition that
 * simply returns [] would silently turn every queued-batch test into a
 * vacuous pass. A duplicate here fails quietly, which is the worst way to fail.
 * Add cases to this file instead.
 */

if (!function_exists('as_has_scheduled_action')) {
    /**
     * Action Scheduler's pending-action lookup.
     *
     * Tests seed $GLOBALS['_cartshift_test_as_pending'] with entries shaped
     * ['hook' => string, 'args' => array|null, 'group' => string].
     *
     * @param array<int, mixed>|null $args
     */
    function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool
    {
        foreach ($GLOBALS['_cartshift_test_as_pending'] ?? [] as $pending) {
            if (
                ($pending['hook'] ?? null) === $hook
                && ($pending['args'] ?? null) === $args
                && ($pending['group'] ?? null) === $group
            ) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('get_woocommerce_currency')) {
    /**
     * Store currency. Every migrator constructor asks for it.
     */
    function get_woocommerce_currency(): string
    {
        return $GLOBALS['_cartshift_test_wc_currency'] ?? 'USD';
    }
}

if (!function_exists('wp_count_posts')) {
    /**
     * Post-status tally. CouponMigrator::countTotal() reads publish/draft/private
     * off this rather than issuing a query of its own.
     *
     * Tests seed $GLOBALS['_cartshift_test_post_status_counts'][$type] with an
     * object or array shaped like WordPress's real return; an unseeded type
     * answers all-zero rather than fatalling; a coupon count of zero is exactly
     * what /preview must report when nothing was set up for it.
     */
    function wp_count_posts(string $type = 'post'): object
    {
        $seeded = $GLOBALS['_cartshift_test_post_status_counts'][$type] ?? null;

        if (is_object($seeded)) {
            return $seeded;
        }

        if (is_array($seeded)) {
            return (object) $seeded;
        }

        return (object) ['publish' => 0, 'draft' => 0, 'private' => 0];
    }
}

if (!function_exists('wc_get_products')) {
    /**
     * WooCommerce product query.
     *
     * Stateful by design: tests seed a queue of batches in
     * $GLOBALS['_cartshift_test_wc_product_batches'] and each call shifts one
     * off, so pagination and cursor-advance tests can watch successive pages
     * and then a natural end. An unseeded queue yields [], which is what the
     * tests that only need "no products" rely on.
     *
     * That statefulness is why this must stay the single definition — see the
     * file header.
     *
     * @param array<string, mixed> $args
     * @return array<int, mixed>
     */
    function wc_get_products(array $args = []): array
    {
        if (
            !isset($GLOBALS['_cartshift_test_wc_product_batches'])
            || !is_array($GLOBALS['_cartshift_test_wc_product_batches'])
            || $GLOBALS['_cartshift_test_wc_product_batches'] === []
        ) {
            return [];
        }

        return (array) array_shift($GLOBALS['_cartshift_test_wc_product_batches']);
    }
}
