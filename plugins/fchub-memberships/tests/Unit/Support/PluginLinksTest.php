<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Core\Container;
use FChubMemberships\Modules\Admin\AdminModule;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PluginLinksTest extends PluginTestCase
{
    public function test_plugin_metadata_uses_specific_live_product_and_author_urls(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/fchub-memberships.php');

        self::assertIsString($source);
        self::assertStringContainsString('Plugin URI: https://fchub.co/docs/fchub-memberships', $source);
        self::assertStringContainsString('Update URI: https://fchub.co/docs/fchub-memberships', $source);
        self::assertStringContainsString('Author URI: https://x.com/vcode_sh', $source);
    }

    public function test_admin_module_registers_a_standard_settings_action_link(): void
    {
        $module = new AdminModule();
        $module->register(new Container());

        $hook = 'plugin_action_links_fchub-memberships/fchub-memberships.php';

        self::assertArrayHasKey($hook, $GLOBALS['_fchub_test_filters']);

        $links = apply_filters($hook, ['deactivate' => '<a href="#">Deactivate</a>']);

        self::assertSame(
            '<a href="https://example.com/wp-admin/admin.php?page=fchub-memberships#/settings">Settings</a>',
            $links[0]
        );
        self::assertArrayHasKey('deactivate', $links);
    }
}
