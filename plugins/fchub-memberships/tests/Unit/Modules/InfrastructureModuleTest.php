<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Modules;

use FChubMemberships\Core\Container;
use FChubMemberships\Modules\Infrastructure\InfrastructureModule;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class InfrastructureModuleTest extends PluginTestCase
{
    public function test_register_adds_expected_hooks(): void
    {
        $module = new InfrastructureModule();
        $module->register(new Container());

        self::assertArrayHasKey('cron_schedules', $GLOBALS['_fchub_test_filters']);
        self::assertArrayHasKey('fchub_memberships_validity_check', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('fchub_memberships_send_email', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('admin_notices', $GLOBALS['_fchub_test_actions']);
    }

    public function test_schedule_and_clear_recurring_events_cover_all_plugin_jobs(): void
    {
        InfrastructureModule::scheduleRecurringEvents();
        InfrastructureModule::clearRecurringEvents();

        self::assertCount(7, $GLOBALS['_fchub_test_scheduled_events']);
        self::assertCount(7, $GLOBALS['_fchub_test_cleared_events']);
    }
}
