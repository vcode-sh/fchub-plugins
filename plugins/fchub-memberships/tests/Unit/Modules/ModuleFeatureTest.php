<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Modules;

use FChubMemberships\Core\Container;
use FChubMemberships\Modules\Admin\AdminModule;
use FChubMemberships\Modules\Runtime\FluentCartRuntimeModule;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ModuleFeatureTest extends PluginTestCase
{
    public function test_admin_and_runtime_modules_expose_keys_register_hooks_and_static_wiring(): void
    {
        $admin = new AdminModule();
        $runtime = new FluentCartRuntimeModule();

        self::assertSame('admin', $admin->key());
        self::assertSame('fluentcart_runtime', $runtime->key());

        $admin->register(new Container());
        $runtime->register(new Container());

        self::assertArrayHasKey('admin_menu', $GLOBALS['_fchub_test_actions']);
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

        $options = $runtime->providePlanOptions([], []);
        $addon = $runtime->registerAddonCard([]);
        $runtime->registerRestRoutes();

        self::assertSame([['id' => '5', 'title' => 'Gold Plan']], $options);
        self::assertSame('Memberships', $addon['memberships']['title']);
        self::assertArrayHasKey('fchub-memberships/v1/admin/plans', $GLOBALS['_fchub_test_routes']);
        self::assertArrayHasKey('fchub-memberships/v1/check-access', $GLOBALS['_fchub_test_routes']);
    }
}
