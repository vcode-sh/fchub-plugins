<?php
/**
 * Plugin Name: FCHub - Portal Extender
 * Plugin URI: https://fchub.co
 * Description: Visual admin interface for creating custom FluentCart Customer Portal endpoints — no code required
 * Version: 1.0.1
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-portal-extender
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to:    6.7
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('FCHUB_PORTAL_EXTENDER_VERSION', '1.0.1');
define('FCHUB_PORTAL_EXTENDER_FILE', __FILE__);
define('FCHUB_PORTAL_EXTENDER_PATH', plugin_dir_path(__FILE__));
define('FCHUB_PORTAL_EXTENDER_URL', plugin_dir_url(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'FChubPortalExtender\\';
    $baseDir = FCHUB_PORTAL_EXTENDER_PATH . 'app/';

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

/**
 * Register custom portal endpoints with FluentCart.
 * FluentCart registers its integrations on 'init' priority 2,
 * so we use priority 3 to ensure the API is available.
 */
add_action('init', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    FChubPortalExtender\Portal\EndpointRegistrar::register();
}, 3);

/**
 * Register REST API routes.
 */
add_action('rest_api_init', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    FChubPortalExtender\Http\EndpointController::registerRoutes();
});

/**
 * Register admin menu page.
 */
add_action('admin_menu', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    FChubPortalExtender\Support\AdminMenu::register();
}, 99);

/**
 * Admin notice if FluentCart is not active.
 */
add_action('admin_notices', function () {
    if (!defined('FLUENTCART_VERSION')) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('FCHub - Portal Extender requires FluentCart to be installed and activated.', 'fchub-portal-extender');
        echo '</p></div>';
    }
});
