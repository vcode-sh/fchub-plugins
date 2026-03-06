<?php
/**
 * Plugin Name: FCHub - Memberships
 * Plugin URI: https://fchub.co
 * Description: Complete membership system for FluentCart with plan management, content access control, content drip scheduling, and analytics
 * Version: 1.0.3
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-memberships
 * Domain Path: /languages
 * Requires at least: 6.4
 * Tested up to:    6.7
 * Requires PHP: 8.1
 * Update URI: https://fchub.co/fchub-memberships
 */

defined('ABSPATH') || exit;

define('FCHUB_MEMBERSHIPS_VERSION', '1.0.3');
define('FCHUB_MEMBERSHIPS_FILE', __FILE__);
define('FCHUB_MEMBERSHIPS_PATH', plugin_dir_path(__FILE__));
define('FCHUB_MEMBERSHIPS_URL', plugin_dir_url(__FILE__));
define('FCHUB_MEMBERSHIPS_DB_VERSION', '1.2.0');

require_once __DIR__ . '/lib/GitHubUpdater.php';
FCHub_GitHub_Updater::register('fchub-memberships', plugin_basename(__FILE__), FCHUB_MEMBERSHIPS_VERSION);

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

    // Register recurring cron jobs
    if (!wp_next_scheduled('fchub_memberships_validity_check')) {
        wp_schedule_event(time(), 'five_minutes', 'fchub_memberships_validity_check');
    }
    if (!wp_next_scheduled('fchub_memberships_drip_process')) {
        wp_schedule_event(time(), 'hourly', 'fchub_memberships_drip_process');
    }
    if (!wp_next_scheduled('fchub_memberships_expiry_notify')) {
        wp_schedule_event(time(), 'daily', 'fchub_memberships_expiry_notify');
    }
    if (!wp_next_scheduled('fchub_memberships_daily_stats')) {
        wp_schedule_event(time(), 'daily', 'fchub_memberships_daily_stats');
    }
    if (!wp_next_scheduled('fchub_memberships_audit_cleanup')) {
        wp_schedule_event(time(), 'weekly', 'fchub_memberships_audit_cleanup');
    }
    if (!wp_next_scheduled('fchub_memberships_trial_check')) {
        wp_schedule_event(time(), 'daily', 'fchub_memberships_trial_check');
    }
    if (!wp_next_scheduled('fchub_memberships_plan_schedule')) {
        wp_schedule_event(time(), 'hourly', 'fchub_memberships_plan_schedule');
    }
});

/**
 * Plugin deactivation: unregister cron jobs, preserve tables.
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('fchub_memberships_validity_check');
    wp_clear_scheduled_hook('fchub_memberships_drip_process');
    wp_clear_scheduled_hook('fchub_memberships_expiry_notify');
    wp_clear_scheduled_hook('fchub_memberships_daily_stats');
    wp_clear_scheduled_hook('fchub_memberships_audit_cleanup');
    wp_clear_scheduled_hook('fchub_memberships_trial_check');
    wp_clear_scheduled_hook('fchub_memberships_plan_schedule');
});

/**
 * Add custom cron schedule for 5-minute interval.
 */
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['five_minutes'])) {
        $schedules['five_minutes'] = [
            'interval' => 300,
            'display'  => __('Every 5 Minutes', 'fchub-memberships'),
        ];
    }
    return $schedules;
});

/**
 * Register the membership integration with FluentCart.
 * FluentCart registers its integrations on 'init' priority 2,
 * so we use priority 3 to ensure BaseIntegrationManager is available.
 */
add_action('init', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    // Run migrations if DB version changed
    $currentDbVersion = get_option('fchub_memberships_db_version', '0');
    if (version_compare($currentDbVersion, FCHUB_MEMBERSHIPS_DB_VERSION, '<')) {
        FChubMemberships\Support\Migrations::run();
        update_option('fchub_memberships_db_version', FCHUB_MEMBERSHIPS_DB_VERSION);
    }

    // Register global settings hooks
    FChubMemberships\Integration\MembershipSettings::register();

    // Register the integration module
    $integration = new FChubMemberships\Integration\MembershipAccessIntegration();
    $integration->register();

    // Register subscription lifecycle hooks for real-time status updates
    $watcher = new FChubMemberships\Domain\SubscriptionValidityWatcher();
    $watcher->registerHooks();

    // Register outgoing webhook dispatcher
    $webhookDispatcher = new FChubMemberships\Integration\WebhookDispatcher();
    $webhookDispatcher->register();

    // Provide plan options for the rest_selector in feed editor
    add_filter('fluent_cart/integration/integration_options_plan_id', function ($options, $args) {
        $planRepo = new FChubMemberships\Storage\PlanRepository();
        $plans = $planRepo->getActivePlans();

        return array_map(function ($plan) {
            return [
                'id'    => (string) $plan['id'],
                'title' => $plan['title'],
            ];
        }, $plans);
    }, 10, 2);

    // Register in the "Integration Modules" UI list
    add_filter('fluent_cart/integration/addons', function ($addons) {
        $addons['memberships'] = [
            'title'       => __('Memberships', 'fchub-memberships'),
            'description' => __('Manage membership plans, content access control, and drip schedules for FluentCart.', 'fchub-memberships'),
            'logo'        => FCHUB_MEMBERSHIPS_URL . 'assets/icons/memberships.svg',
            'enabled'     => true,
            'config_url'  => admin_url('admin.php?page=fchub-memberships'),
            'categories'  => ['core'],
        ];
        return $addons;
    });

    // Register FluentCRM sync hooks
    $fluentCrmSync = new FChubMemberships\Integration\FluentCrmSync();
    $fluentCrmSync->register();

    // Register FluentCommunity sync hooks
    $fluentCommunitySync = new FChubMemberships\Integration\FluentCommunitySync();
    $fluentCommunitySync->register();

    // Register URL pattern protection hooks (priority 5 on template_redirect, before content)
    $urlProtection = new FChubMemberships\Domain\UrlProtection();
    $urlProtection->register();

    // Register content protection hooks
    $contentProtection = new FChubMemberships\Domain\ContentProtection();
    $contentProtection->register();

    // Register comment protection hooks
    $commentProtection = new FChubMemberships\Domain\CommentProtection();
    $commentProtection->register();

    // Register special page protection hooks
    $specialPageProtection = new FChubMemberships\Domain\SpecialPageProtection();
    $specialPageProtection->register();

    // Register menu item protection hooks
    $menuProtection = new FChubMemberships\Domain\MenuProtection();
    $menuProtection->register();

    // Register taxonomy term protection (admin only)
    if (is_admin()) {
        $taxonomyProtection = new FChubMemberships\Domain\TaxonomyProtection();
        $taxonomyProtection->register();
    }

    // Register shortcodes
    FChubMemberships\Frontend\Shortcodes::register();

    // Register Gutenberg blocks
    FChubMemberships\Frontend\GutenbergBlocks::register();

    // Register frontend account page
    FChubMemberships\Frontend\AccountPage::register();

    // Register REST API routes
    add_action('rest_api_init', function () {
        FChubMemberships\Http\Controllers\PlanController::registerRoutes();
        FChubMemberships\Http\Controllers\MemberController::registerRoutes();
        FChubMemberships\Http\Controllers\ContentController::registerRoutes();
        FChubMemberships\Http\Controllers\DripController::registerRoutes();
        FChubMemberships\Http\Controllers\ReportController::registerRoutes();
        FChubMemberships\Http\Controllers\SettingsController::registerRoutes();
        FChubMemberships\Http\DynamicOptionsController::registerRoutes();
        FChubMemberships\Http\AccessCheckController::registerRoutes();
        FChubMemberships\Http\AccountController::registerRoutes();
        FChubMemberships\Http\Controllers\ImportController::registerRoutes();
    });

    // Register WP-CLI commands
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('fchub-membership', FChubMemberships\CLI\GrantCommand::class);
    }
}, 3);

/**
 * Register FluentCRM automation triggers, actions, benchmarks, smart codes, and filters.
 */
add_action('init', function () {
    if (defined('FLUENTCRM')) {
        \FChubMemberships\FluentCRM\FluentCrmAutomation::boot();
    }
}, 30);

/**
 * Register admin menu page.
 */
add_action('admin_menu', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    FChubMemberships\Support\AdminMenu::register();
});

/**
 * Cron: check subscription validity and fire expiration events.
 */
add_action('fchub_memberships_validity_check', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubMemberships\Domain\SubscriptionValidityWatcher())->check();
});

/**
 * Cron: process drip notifications.
 */
add_action('fchub_memberships_drip_process', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubMemberships\Domain\Drip\DripScheduleService())->processNotifications();
});

/**
 * Cron: send access expiring soon notifications.
 */
add_action('fchub_memberships_expiry_notify', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubMemberships\Email\AccessExpiringEmail())->sendPendingNotifications();
});

/**
 * Cron: aggregate daily stats for reports.
 */
add_action('fchub_memberships_daily_stats', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubMemberships\Reports\MemberStatsReport())->aggregateDaily();

    // Check grant anniversaries (piggyback on daily stats cron)
    FChubMemberships\FluentCRM\Triggers\MembershipAnniversaryTrigger::checkAnniversaries();
});

/**
 * Cron: clean up old audit log entries (weekly).
 */
add_action('fchub_memberships_audit_cleanup', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubMemberships\Storage\AuditLogRepository())->cleanup(90);
});

/**
 * Cron: check trial expirations and send trial expiring notifications.
 */
add_action('fchub_memberships_trial_check', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    $service = new FChubMemberships\Domain\TrialLifecycleService();
    $service->sendTrialExpiringNotifications();
    $service->checkTrialExpirations();
});

/**
 * Cron: process scheduled plan status changes (hourly).
 */
add_action('fchub_memberships_plan_schedule', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubMemberships\Domain\Plan\PlanService())->processScheduledStatuses();
});

/**
 * Action Scheduler: process async email delivery.
 */
add_action('fchub_memberships_send_email', function (string $to, string $subject, string $body, array $headers) {
    wp_mail($to, $subject, $body, $headers);
}, 10, 4);

/**
 * Action Scheduler: process async webhook dispatch.
 */
add_action('fchub_memberships_dispatch_webhook', function (string $url, string $body, array $headers) {
    $response = wp_remote_post($url, [
        'timeout' => 15,
        'headers' => $headers,
        'body'    => $body,
    ]);

    if (is_wp_error($response)) {
        FChubMemberships\Support\Logger::error(
            'Webhook dispatch failed',
            sprintf('%s: %s', $url, $response->get_error_message())
        );
    }
}, 10, 3);

/**
 * Admin notice if FluentCart is not active.
 */
add_action('admin_notices', function () {
    if (!defined('FLUENTCART_VERSION')) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('FCHub - Memberships requires FluentCart to be installed and activated.', 'fchub-memberships');
        echo '</p></div>';
    }
});
