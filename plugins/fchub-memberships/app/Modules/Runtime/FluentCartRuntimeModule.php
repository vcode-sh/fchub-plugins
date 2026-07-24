<?php

namespace FChubMemberships\Modules\Runtime;

use FChubMemberships\Core\Container;
use FChubMemberships\Core\Contracts\ModuleInterface;

defined('ABSPATH') || exit;

final class FluentCartRuntimeModule implements ModuleInterface
{
    public function key(): string
    {
        return 'fluentcart_runtime';
    }

    public function register(Container $container): void
    {
        \FChubMemberships\Http\ApplicationPasswordRequestContext::register();
        add_action('init', [$this, 'bootRuntime'], 3);
    }

    public function bootRuntime(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        \FChubMemberships\Support\Migrations::ensureAdministratorCapability();

        $currentDbVersion = get_option('fchub_memberships_db_version', '0');
        $needsMigration = version_compare($currentDbVersion, FCHUB_MEMBERSHIPS_DB_VERSION, '<');
        if (!$needsMigration) {
            $needsMigration = \FChubMemberships\Support\Migrations::verifySchema() !== [];
        }
        if ($needsMigration) {
            $migrationResult = \FChubMemberships\Support\Migrations::run();
            if ($migrationResult['success']) {
                update_option('fchub_memberships_db_version', FCHUB_MEMBERSHIPS_DB_VERSION);
            } else {
                $failureDescription = implode('; ', $migrationResult['failures']);
                \FChubMemberships\Support\Logger::error(
                    'Membership database migration failed',
                    'Required tables, columns, indexes, foreign keys, or referential integrity checks are incomplete. '
                    . 'Review the database error log and repair the schema before retrying. Failures: '
                    . $failureDescription,
                    ['postcondition_failures' => $migrationResult['failures']]
                );
                do_action(
                    'fchub_memberships/migration_failed',
                    FCHUB_MEMBERSHIPS_DB_VERSION,
                    $migrationResult['failures']
                );
            }
        }

        \FChubMemberships\Integration\MembershipSettings::register();

        $membershipLifecycle = new \FChubMemberships\Domain\Lifecycle\MembershipLifecycleCoordinator();
        (new \FChubMemberships\Integration\MembershipAccessIntegration(
            null,
            null,
            null,
            $membershipLifecycle
        ))->register();
        (new \FChubMemberships\Domain\SubscriptionValidityWatcher(
            $membershipLifecycle
        ))->registerHooks();
        (new \FChubMemberships\Integration\WebhookDispatcher())->register();
        (new \FChubMemberships\Integration\FluentCrmSync())->register();
        (new \FChubMemberships\Integration\FluentCommunitySync())->register();
        (new \FChubMemberships\Domain\UrlProtection())->register();
        (new \FChubMemberships\Domain\ContentProtection())->register();
        (new \FChubMemberships\Domain\CommentProtection())->register();
        (new \FChubMemberships\Domain\SpecialPageProtection())->register();
        (new \FChubMemberships\Domain\MenuProtection())->register();

        if (is_admin()) {
            (new \FChubMemberships\Domain\TaxonomyProtection())->register();
        }

        \FChubMemberships\Frontend\Shortcodes::register();
        \FChubMemberships\Frontend\GutenbergBlocks::register();
        \FChubMemberships\Frontend\AccountPage::register();

        add_filter('fluent_cart/integration/integration_options_plan_id', [$this, 'providePlanOptions'], 10, 2);
        add_filter('fluent_cart/integration/addons', [$this, 'registerAddonCard']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('fchub-membership', \FChubMemberships\CLI\GrantCommand::class);
            \WP_CLI::add_command(
                'fchub-membership provider-reconcile',
                \FChubMemberships\CLI\ProviderReconcileCommand::class
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, string>>
     */
    public function providePlanOptions(array $options, array $args): array
    {
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plans = $planRepo->getActivePlans();

        return array_map(function (array $plan): array {
            return [
                'id'    => (string) $plan['id'],
                'title' => $plan['title'],
            ];
        }, $plans);
    }

    /**
     * @param array<string, mixed> $addons
     * @return array<string, mixed>
     */
    public function registerAddonCard(array $addons): array
    {
        $addons['memberships'] = [
            'title'       => __('Memberships', 'fchub-memberships'),
            'description' => __('Manage membership plans, content access control, and drip schedules for FluentCart.', 'fchub-memberships'),
            'logo'        => FCHUB_MEMBERSHIPS_URL . 'assets/icons/memberships.svg',
            'enabled'     => true,
            'config_url'  => admin_url('admin.php?page=fchub-memberships'),
            'categories'  => ['core'],
        ];

        return $addons;
    }

    public function registerRestRoutes(): void
    {
        \FChubMemberships\Http\Controllers\PlanController::registerRoutes();
        \FChubMemberships\Http\Controllers\MemberController::registerRoutes();
        \FChubMemberships\Http\Controllers\ContentController::registerRoutes();
        \FChubMemberships\Http\Controllers\DripController::registerRoutes();
        \FChubMemberships\Http\Controllers\DashboardController::registerRoutes();
        \FChubMemberships\Http\Controllers\ReportController::registerRoutes();
        \FChubMemberships\Http\Controllers\SettingsController::registerRoutes();
        \FChubMemberships\Http\DynamicOptionsController::registerRoutes();
        \FChubMemberships\Http\AccessCheckController::registerRoutes();
        \FChubMemberships\Http\AccountController::registerRoutes();
        \FChubMemberships\Http\Controllers\ImportController::registerRoutes();
        \FChubMemberships\Http\Controllers\IntegrationHealthController::registerRoutes();
        \FChubMemberships\Http\Controllers\ProviderReconciliationController::registerRoutes();
        \FChubMemberships\Http\Controllers\WebhookController::registerRoutes();
        \FChubMemberships\Http\Controllers\WebhookEndpointController::registerRoutes();
    }
}
