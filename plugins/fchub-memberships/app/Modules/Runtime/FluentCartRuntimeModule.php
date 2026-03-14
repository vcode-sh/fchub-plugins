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
        add_action('init', [$this, 'bootRuntime'], 3);
    }

    public function bootRuntime(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        $currentDbVersion = get_option('fchub_memberships_db_version', '0');
        if (version_compare($currentDbVersion, FCHUB_MEMBERSHIPS_DB_VERSION, '<')) {
            \FChubMemberships\Support\Migrations::run();
            update_option('fchub_memberships_db_version', FCHUB_MEMBERSHIPS_DB_VERSION);
        }

        \FChubMemberships\Integration\MembershipSettings::register();

        (new \FChubMemberships\Integration\MembershipAccessIntegration())->register();
        (new \FChubMemberships\Domain\SubscriptionValidityWatcher())->registerHooks();
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
        \FChubMemberships\Http\Controllers\ReportController::registerRoutes();
        \FChubMemberships\Http\Controllers\SettingsController::registerRoutes();
        \FChubMemberships\Http\DynamicOptionsController::registerRoutes();
        \FChubMemberships\Http\AccessCheckController::registerRoutes();
        \FChubMemberships\Http\AccountController::registerRoutes();
        \FChubMemberships\Http\Controllers\ImportController::registerRoutes();
    }
}
