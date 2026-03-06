<?php

/**
 * Minimal bootstrap for tests that don't need WordPress or FluentCart mocks.
 * Use when testing pure domain logic (value objects, enums).
 */

define('FCHUB_TESTING', true);
define('ABSPATH', '/tmp/wordpress/');
define('FCHUB_MC_VERSION', '1.1.2');
define('FCHUB_MC_PATH', dirname(__DIR__) . '/');
define('FCHUB_MC_URL', 'http://localhost/wp-content/plugins/fchub-multi-currency/');
define('FCHUB_MC_DB_VERSION', '1.0.0');
define('FCHUB_MC_FILE', dirname(__DIR__) . '/fchub-multi-currency.php');

if (!function_exists('current_time')) {
    function current_time($type)
    {
        if ($type === 'timestamp') {
            return time();
        }
        return date('Y-m-d H:i:s');
    }
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'FChubMultiCurrency\\';
    $baseDir = __DIR__ . '/../app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

spl_autoload_register(function ($class) {
    $prefix = 'FChubMultiCurrency\\Tests\\';
    $baseDir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
