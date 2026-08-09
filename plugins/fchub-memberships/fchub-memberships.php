<?php
/**
 * Plugin Name: FCHub Memberships
 * Plugin URI: https://fchub.co/docs/fchub-memberships
 * Description: Membership workspace for FluentCart with guided plans, protected content, member care, automation, integrations, and reporting
 * Version: 1.4.4
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-memberships
 * Domain Path: /languages
 * Requires at least: 7.0
 * Tested up to:    7.0
 * Requires PHP: 8.3
 * Requires Plugins: fluent-cart
 */

defined('ABSPATH') || exit;

defined('FCHUB_MEMBERSHIPS_VERSION') || define('FCHUB_MEMBERSHIPS_VERSION', '1.4.4');
defined('FCHUB_MEMBERSHIPS_FILE') || define('FCHUB_MEMBERSHIPS_FILE', __FILE__);
defined('FCHUB_MEMBERSHIPS_PATH') || define('FCHUB_MEMBERSHIPS_PATH', plugin_dir_path(__FILE__));
defined('FCHUB_MEMBERSHIPS_URL') || define('FCHUB_MEMBERSHIPS_URL', plugin_dir_url(__FILE__));
defined('FCHUB_MEMBERSHIPS_DB_VERSION') || define('FCHUB_MEMBERSHIPS_DB_VERSION', '1.9.0');

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'FChubMemberships\\';
    $baseDir = FCHUB_MEMBERSHIPS_PATH . 'app/';

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
 * Plugin activation: create database tables and register cron jobs.
 */
register_activation_hook(__FILE__, function () {
    FChubMemberships\Support\Migrations::run();
    FChubMemberships\Modules\Infrastructure\InfrastructureModule::scheduleRecurringEvents();
});

/**
 * Plugin deactivation: unregister cron jobs, preserve tables.
 */
register_deactivation_hook(__FILE__, function () {
    FChubMemberships\Modules\Infrastructure\InfrastructureModule::clearRecurringEvents();
});
FChubMemberships\Core\PluginBootstrap::boot();
