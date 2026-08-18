<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Support;

use FChubMultiCurrency\Support\AdminMenu;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AdminMenuTest extends TestCase
{
    /**
     * Every admin surface script carries strings through wp.i18n, so each
     * handle needs the wp-i18n dependency and a translations registration
     * pointing at the plugin's languages directory.
     */
    #[Test]
    public function testEveryAdminScriptLoadsTranslationsFromTheLanguagesDirectory(): void
    {
        AdminMenu::enqueueAssets();

        $handles = [
            'fchub-mc-switcher-preview',
            'fchub-mc-admin-general-settings',
            'fchub-mc-admin-currency-settings',
            'fchub-mc-admin-switcher-settings',
            'fchub-mc-admin-rate-settings',
            'fchub-mc-admin-checkout-settings',
            'fchub-mc-admin-crm-settings',
            'fchub-mc-admin-diagnostics-view',
            'fchub-mc-admin',
        ];

        foreach ($handles as $handle) {
            $registered = $GLOBALS['wp_registered_scripts'][$handle] ?? null;
            self::assertIsArray($registered, "$handle must be registered");
            self::assertContains('wp-i18n', $registered['deps'], "$handle must depend on wp-i18n");

            $translations = $GLOBALS['wp_script_translations'][$handle] ?? null;
            self::assertIsArray($translations, "$handle must register script translations");
            self::assertSame('fchub-multi-currency', $translations['domain']);
            self::assertStringEndsWith('languages', $translations['path']);
        }
    }
}
