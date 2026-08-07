<?php

declare(strict_types=1);

/**
 * Extra WordPress stubs for the migration domain and storage tests.
 *
 * Everything here is function_exists-guarded so this file can be required from
 * several test files, and so it never fights the shared test-bootstrap.php.
 */

if (!function_exists('wp_cache_flush_runtime')) {
    /**
     * Records calls so tests can prove the runtime flush is preferred over the
     * site-wide one. WordPress 6.0+; drop-ins override it to clear only the
     * in-process array.
     */
    function wp_cache_flush_runtime(): bool
    {
        $GLOBALS['_cartshift_test_cache_flush_runtime_calls'] =
            ($GLOBALS['_cartshift_test_cache_flush_runtime_calls'] ?? 0) + 1;

        return true;
    }
}
