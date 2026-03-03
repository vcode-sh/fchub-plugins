<?php

/**
 * PHPStan bootstrap file.
 *
 * Defines constants and stubs that PHPStan needs to analyse
 * the plugin without loading the full WordPress environment.
 */

// Plugin constants (defined in fchub-wishlist.php at runtime).
define('FCHUB_WISHLIST_VERSION', '1.0.0');
define('FCHUB_WISHLIST_FILE', __DIR__ . '/fchub-wishlist.php');
define('FCHUB_WISHLIST_PATH', __DIR__ . '/');
define('FCHUB_WISHLIST_URL', 'https://example.com/wp-content/plugins/fchub-wishlist/');
define('FCHUB_WISHLIST_DB_VERSION', '1.0.0');

// WordPress constants used in the plugin.
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
