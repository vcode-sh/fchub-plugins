<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Core\Container;
use FChubMemberships\Modules\Admin\AdminModule;
use FChubMemberships\Support\AdminMenu;
use FChubMemberships\Tests\Unit\PluginTestCase;

if (!defined('FLUENTCART_VERSION')) {
    define('FLUENTCART_VERSION', '1.0.0');
}

final class AdminMenuFeatureTest extends PluginTestCase
{
    public function test_admin_module_registers_menu_and_renders_spa_shell_with_assets(): void
    {
        $module = new AdminModule();
        $module->register(new Container());
        $module->registerAdminMenu();

        ob_start();
        AdminMenu::render();
        $html = ob_get_clean();

        self::assertArrayHasKey('admin_menu', $GLOBALS['_fchub_test_actions']);
        self::assertSame('fchub-memberships', $GLOBALS['_fchub_test_menu_pages'][0][3]);
        self::assertCount(7, $GLOBALS['submenu']['fchub-memberships']);
        self::assertStringContainsString('fchub-memberships-app', $html);
        self::assertNotEmpty($GLOBALS['_fchub_test_enqueued_styles']);
        self::assertNotEmpty($GLOBALS['_fchub_test_enqueued_scripts']);
        self::assertNotEmpty($GLOBALS['_fchub_test_inline_scripts']);
        self::assertStringContainsString('window.fchubMembershipsAdmin', $GLOBALS['_fchub_test_inline_scripts'][0][1]);
        self::assertArrayHasKey('script_loader_tag', $GLOBALS['_fchub_test_filters']);

        AdminMenu::suppressAdminNotices();

        self::assertSame([], $GLOBALS['_fchub_test_actions']['admin_notices'] ?? []);
        self::assertSame([], $GLOBALS['_fchub_test_actions']['all_admin_notices'] ?? []);
    }
}
