<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/stubs/wordpress/');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!defined('FCHUB_MEMBERSHIPS_VERSION')) {
    define('FCHUB_MEMBERSHIPS_VERSION', '1.4.4');
}

if (!defined('FCHUB_MEMBERSHIPS_FILE')) {
    define('FCHUB_MEMBERSHIPS_FILE', dirname(__DIR__) . '/fchub-memberships.php');
}

if (!defined('FCHUB_MEMBERSHIPS_PATH')) {
    define('FCHUB_MEMBERSHIPS_PATH', dirname(__DIR__) . '/');
}

if (!defined('FCHUB_MEMBERSHIPS_URL')) {
    define('FCHUB_MEMBERSHIPS_URL', 'https://example.com/wp-content/plugins/fchub-memberships/');
}

if (!defined('FCHUB_MEMBERSHIPS_DB_VERSION')) {
    define('FCHUB_MEMBERSHIPS_DB_VERSION', '1.9.0');
}

require_once __DIR__ . '/stubs/test-bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'FChubMemberships\\';
    $baseDir = dirname(__DIR__) . '/app/';

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
