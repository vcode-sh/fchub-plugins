<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/stubs/wordpress/');
}

// WordPress defines this on every request; the operation service reads it when
// it tells core where to put an update's temp backup.
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', __DIR__ . '/stubs/wordpress/wp-content/plugins');
}

if (!defined('FCHUB_HUB_VERSION')) {
    define('FCHUB_HUB_VERSION', '1.0.0');
}

if (!defined('FCHUB_HUB_FILE')) {
    define('FCHUB_HUB_FILE', dirname(__DIR__) . '/fchub.php');
}

if (!defined('FCHUB_HUB_PATH')) {
    define('FCHUB_HUB_PATH', dirname(__DIR__) . '/');
}

if (!defined('FCHUB_HUB_URL')) {
    define('FCHUB_HUB_URL', 'https://example.com/wp-content/plugins/fchub/');
}

require_once __DIR__ . '/stubs/wordpress-functions.php';

// FCHub's own isolated autoloader for tests. This mirrors the contract shipped
// in fchub.php exactly, but the tests never require fchub.php itself — that
// file also calls Plugin::boot() on load, which we want to trigger explicitly
// per test instead of once, globally, as a side effect of bootstrapping.
spl_autoload_register(static function (string $class): void {
    $prefix = 'FChubHub\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = FCHUB_HUB_PATH . 'app/' . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// Shared catalogue payloads for the domain suites. Loaded explicitly because
// the autoloader above only ever resolves production classes under app/.
require_once __DIR__ . '/Support/CatalogueFixtures.php';
