<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use FChubHub\Catalogue\CatalogueRepository;
use FChubHub\Core\Plugin;
use FChubHub\Http\Routes;
use FChubHub\Support\AdminMenu;
use PHPUnit\Framework\TestCase;

final class PluginBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_hub_test_action_registrations'] = [];
        $GLOBALS['_fchub_hub_test_filters'] = [];
        $GLOBALS['_fchub_hub_test_menu_pages'] = [];
        $GLOBALS['_fchub_hub_test_enqueued_scripts'] = [];
        $GLOBALS['_fchub_hub_test_enqueued_styles'] = [];
        $GLOBALS['_fchub_hub_test_inline_scripts'] = [];
        $GLOBALS['submenu'] = [];
    }

    protected function tearDown(): void
    {
        // Always clear the test seams, even if a test fails mid-assertion —
        // a stale override, or the shared repository Plugin::boot() builds,
        // would silently leak into every later test.
        AdminMenu::setDistPathOverrideForTests(null);
        CatalogueRepository::resetSharedInstanceForTests();

        parent::tearDown();
    }

    public function testPluginHeaderDeclaresTheApprovedCompatibilityContract(): void
    {
        $source = (string) file_get_contents(FCHUB_HUB_PATH . 'fchub.php');

        self::assertStringContainsString('Plugin Name: FCHub', $source);
        self::assertStringContainsString('Version: 1.0.0', $source);
        self::assertStringContainsString('Requires at least: 6.4', $source);
        self::assertStringContainsString('Requires PHP: 8.1', $source);
        self::assertStringContainsString('Update URI: https://fchub.co/fchub', $source);
    }

    public function testPluginHeaderNeverPullsInFluentCartOrTheSharedUpdater(): void
    {
        $source = (string) file_get_contents(FCHUB_HUB_PATH . 'fchub.php');

        self::assertStringNotContainsString('FluentCart', $source);
        self::assertStringNotContainsString('GitHubUpdater', $source);
        self::assertStringNotContainsString('FCHub_GitHub_Updater', $source);
    }

    public function testBootRegistersTheAdminMenuHookAtPriority28(): void
    {
        Plugin::boot();

        $registrations = $GLOBALS['_fchub_hub_test_action_registrations']['admin_menu'] ?? [];
        self::assertNotEmpty($registrations, 'Plugin::boot() must hook admin_menu.');

        self::assertSame(28, $registrations[0]['priority']);
        self::assertSame([AdminMenu::class, 'register'], $registrations[0]['callback']);
    }

    public function testBootRegistersThePluginActionLinksFilterForItsOwnFile(): void
    {
        Plugin::boot();

        $expectedHook = 'plugin_action_links_' . plugin_basename(FCHUB_HUB_FILE);
        $filters = $GLOBALS['_fchub_hub_test_filters'][$expectedHook] ?? [];

        self::assertNotEmpty($filters, 'Plugin::boot() must add a plugin action links filter for its own basename.');
        self::assertSame([AdminMenu::class, 'actionLinks'], $filters[0]);
    }

    public function testBootedAdminMenuHookRegistersTheTopLevelFchubPage(): void
    {
        Plugin::boot();

        $registration = $GLOBALS['_fchub_hub_test_action_registrations']['admin_menu'][0];
        call_user_func($registration['callback']);

        $registeredMenu = $GLOBALS['_fchub_hub_test_menu_pages'][0];

        self::assertSame('fchub', $registeredMenu['menu_slug']);
        self::assertSame('manage_options', $registeredMenu['capability']);
    }

    public function testRegisterAddsOnlyTheThreeApprovedHashRouteSubmenuEntries(): void
    {
        AdminMenu::register();

        self::assertArrayHasKey('fchub', $GLOBALS['submenu']);

        $labels = array_column($GLOBALS['submenu']['fchub'], 0);
        self::assertSame(['Overview', 'Products', 'System'], $labels);

        foreach ($GLOBALS['submenu']['fchub'] as $entry) {
            self::assertSame('manage_options', $entry[1]);
        }
    }

    public function testRegisterDoesNotTouchAnotherPluginsSubmenu(): void
    {
        $GLOBALS['submenu']['fluent-cart'] = [
            ['Existing', 'manage_options', 'admin.php?page=fluent-cart'],
        ];

        AdminMenu::register();

        self::assertSame(
            [['Existing', 'manage_options', 'admin.php?page=fluent-cart']],
            $GLOBALS['submenu']['fluent-cart']
        );
    }

    public function testRegisterUsesTheBundledSvgAsABase64DataUriIcon(): void
    {
        AdminMenu::register();

        $iconUrl = $GLOBALS['_fchub_hub_test_menu_pages'][0]['icon_url'];

        self::assertStringStartsWith('data:image/svg+xml;base64,', $iconUrl);

        $decoded = base64_decode(substr($iconUrl, strlen('data:image/svg+xml;base64,')), true);
        self::assertIsString($decoded);
        self::assertStringContainsString('<svg', $decoded);
    }

    public function testActionLinksPrependsAnOverviewLinkToTheFchubPage(): void
    {
        $links = AdminMenu::actionLinks(['existing' => '<a href="#">Existing</a>']);

        self::assertCount(2, $links);
        self::assertStringContainsString('page=fchub', (string) reset($links));
    }

    public function testRenderEchoesTheAppMountPointAndSkipsAssetsWithoutABuild(): void
    {
        // Point at an empty fixture, not the real assets/dist/ — Task 5 has
        // since shipped a real build there, so asserting "nothing enqueued"
        // against the repository's actual dist directory would depend on
        // whichever build happens to be checked in rather than proving the
        // behaviour this test exists for: no manifest, therefore no assets,
        // but the mount point still renders. Nothing is created on disk for
        // this path — is_file() on a manifest under a directory that doesn't
        // even exist yet simply returns false, same as a missing manifest.
        AdminMenu::setDistPathOverrideForTests($this->createFixtureDistDir());

        ob_start();
        AdminMenu::render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('<div id="fchub-app"></div>', $output);
        self::assertSame([], $GLOBALS['_fchub_hub_test_enqueued_scripts']);
        self::assertSame([], $GLOBALS['_fchub_hub_test_enqueued_styles']);
        self::assertSame([], $GLOBALS['_fchub_hub_test_inline_scripts']);
    }

    public function testRenderEnqueuesTheManifestBuildWhenPresent(): void
    {
        $distPath = $this->createFixtureDistDir();
        $viteDir = $distPath . '.vite/';

        mkdir($viteDir, 0777, true);
        file_put_contents($viteDir . 'manifest.json', (string) json_encode([
            'resources/admin/main.js' => [
                'file' => 'assets/fchub-admin.js',
                'css'  => ['assets/fchub-admin.css'],
            ],
        ]));

        AdminMenu::setDistPathOverrideForTests($distPath);

        try {
            ob_start();
            AdminMenu::render();
            $output = (string) ob_get_clean();

            self::assertStringContainsString('<div id="fchub-app"></div>', $output);

            $scripts = $GLOBALS['_fchub_hub_test_enqueued_scripts'];
            self::assertCount(1, $scripts);
            self::assertSame('fchub-admin', $scripts[0][0]);
            self::assertStringEndsWith('assets/dist/assets/fchub-admin.js', $scripts[0][1]);

            $styles = $GLOBALS['_fchub_hub_test_enqueued_styles'];
            self::assertCount(1, $styles);
            self::assertSame('fchub-admin', $styles[0][0]);
            self::assertStringEndsWith('assets/dist/assets/fchub-admin.css', $styles[0][1]);

            $inline = $GLOBALS['_fchub_hub_test_inline_scripts'][0];
            self::assertSame('fchub-admin', $inline[0]);
            self::assertStringContainsString('window.fchubAdmin', $inline[1]);
            self::assertStringContainsString('"rest_url"', $inline[1]);

            // Not just that the key is there: the screen has to be pointed at
            // the namespace Routes actually registers, and pinning the shared
            // constant is what stops the two drifting apart again.
            $config = json_decode(
                (string) preg_replace('/^window\.fchubAdmin = (.*);$/', '$1', $inline[1]),
                true
            );

            self::assertIsArray($config);
            self::assertStringEndsWith(Routes::REST_NAMESPACE . '/', (string) $config['rest_url']);

            self::assertStringContainsString('"nonce"', $inline[1]);
            self::assertStringContainsString('"admin_url"', $inline[1]);
            self::assertStringContainsString('"version"', $inline[1]);
            self::assertStringContainsString('"locale"', $inline[1]);

            $tagFilters = $GLOBALS['_fchub_hub_test_filters']['script_loader_tag'] ?? [];
            self::assertNotEmpty($tagFilters, 'render() must add a script_loader_tag filter for the module script.');

            $tag = $tagFilters[0]('<script src="build.js"></script>', 'fchub-admin');
            self::assertStringContainsString('type="module"', $tag);
        } finally {
            $this->removeFixtureDistDir($distPath);
        }
    }

    public function testRenderEnqueuesEachStylesheetWithASuffixedHandle(): void
    {
        $distPath = $this->createFixtureDistDir();
        $viteDir = $distPath . '.vite/';

        mkdir($viteDir, 0777, true);
        file_put_contents($viteDir . 'manifest.json', (string) json_encode([
            'resources/admin/main.js' => [
                'file' => 'assets/fchub-admin.js',
                'css'  => ['assets/fchub-admin.css', 'assets/fchub-admin-vendor.css'],
            ],
        ]));

        AdminMenu::setDistPathOverrideForTests($distPath);

        try {
            ob_start();
            AdminMenu::render();
            ob_get_clean();

            $styles = $GLOBALS['_fchub_hub_test_enqueued_styles'];
            self::assertCount(2, $styles);

            self::assertSame('fchub-admin', $styles[0][0]);
            self::assertStringEndsWith('assets/dist/assets/fchub-admin.css', $styles[0][1]);

            self::assertSame('fchub-admin-1', $styles[1][0]);
            self::assertStringEndsWith('assets/dist/assets/fchub-admin-vendor.css', $styles[1][1]);
        } finally {
            $this->removeFixtureDistDir($distPath);
        }
    }

    private function createFixtureDistDir(): string
    {
        return sys_get_temp_dir() . '/fchub-admin-menu-' . uniqid('', true) . '/';
    }

    private function removeFixtureDistDir(string $distPath): void
    {
        $manifestPath = $distPath . '.vite/manifest.json';

        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }

        if (is_dir($distPath . '.vite/')) {
            rmdir($distPath . '.vite/');
        }

        if (is_dir($distPath)) {
            rmdir($distPath);
        }
    }
}
