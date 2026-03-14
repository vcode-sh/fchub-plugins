<?php
/**
 * Plugin Name: FCHub - Memberships
 * Plugin URI: https://fchub.co
 * Description: Complete membership system for FluentCart with plan management, content access control, content drip scheduling, and analytics
 * Version: 1.1.0
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-memberships
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to:    7.0
 * Requires PHP: 8.3
 * Update URI: https://fchub.co/fchub-memberships
 */

defined('ABSPATH') || exit;

defined('FCHUB_MEMBERSHIPS_VERSION') || define('FCHUB_MEMBERSHIPS_VERSION', '1.1.0');
defined('FCHUB_MEMBERSHIPS_FILE') || define('FCHUB_MEMBERSHIPS_FILE', __FILE__);
defined('FCHUB_MEMBERSHIPS_PATH') || define('FCHUB_MEMBERSHIPS_PATH', plugin_dir_path(__FILE__));
defined('FCHUB_MEMBERSHIPS_URL') || define('FCHUB_MEMBERSHIPS_URL', plugin_dir_url(__FILE__));
defined('FCHUB_MEMBERSHIPS_DB_VERSION') || define('FCHUB_MEMBERSHIPS_DB_VERSION', '1.2.0');

if (file_exists(__DIR__ . '/lib/GitHubUpdater.php')) {
    require_once __DIR__ . '/lib/GitHubUpdater.php';
    FCHub_GitHub_Updater::register('fchub-memberships', plugin_basename(__FILE__), FCHUB_MEMBERSHIPS_VERSION);
}

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
