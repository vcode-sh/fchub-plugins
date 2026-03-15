<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Modules;

use FChubMemberships\Core\Container;
use FChubMemberships\Modules\Runtime\FluentCartRuntimeModule;
use FChubMemberships\Tests\Unit\PluginTestCase;

if (!defined('FLUENTCART_VERSION')) {
    define('FLUENTCART_VERSION', '1.0.0');
}

final class RuntimeModuleFeatureTest extends PluginTestCase
{
    public function test_runtime_module_boots_when_fluentcart_is_present_and_registers_public_hooks(): void
    {
        $module = new FluentCartRuntimeModule();
        $module->register(new Container());

        self::assertArrayHasKey('init', $GLOBALS['_fchub_test_actions']);

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => str_contains($query, "SELECT * FROM wp_fchub_membership_plans WHERE 1=1 AND status = 'active'")
            ? [[
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => '',
                'status' => 'active',
                'level' => 1,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => null,
                'redirect_url' => null,
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]]
            : [];

        $options = $module->providePlanOptions([], []);
        $addon = $module->registerAddonCard([]);
        $module->registerRestRoutes();

        self::assertSame([['id' => '5', 'title' => 'Gold Plan']], $options);
        self::assertStringContainsString('admin.php?page=fchub-memberships', $addon['memberships']['config_url']);
        self::assertArrayHasKey('fchub-memberships/v1/admin/plans', $GLOBALS['_fchub_test_routes']);
        self::assertArrayHasKey('fchub-memberships/v1/check-access', $GLOBALS['_fchub_test_routes']);
    }
}
