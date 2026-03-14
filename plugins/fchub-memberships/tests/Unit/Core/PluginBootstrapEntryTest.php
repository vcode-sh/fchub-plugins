<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Core;

use FChubMemberships\Tests\Unit\PluginTestCase;

final class PluginBootstrapEntryTest extends PluginTestCase
{
    public function test_main_plugin_file_registers_expected_hooks_through_modules(): void
    {
        require_once dirname(__DIR__, 3) . '/fchub-memberships.php';

        self::assertArrayHasKey(FCHUB_MEMBERSHIPS_FILE, $GLOBALS['_fchub_test_activation_hooks']);
        self::assertArrayHasKey(FCHUB_MEMBERSHIPS_FILE, $GLOBALS['_fchub_test_deactivation_hooks']);
        self::assertArrayHasKey('cron_schedules', $GLOBALS['_fchub_test_filters']);
        self::assertArrayHasKey('init', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('admin_menu', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('admin_notices', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('fchub_memberships_validity_check', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('fchub_memberships_dispatch_webhook', $GLOBALS['_fchub_test_actions']);
    }
}
