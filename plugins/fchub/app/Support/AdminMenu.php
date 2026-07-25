<?php

namespace FChubHub\Support;

use FChubHub\Http\Routes;

defined('ABSPATH') || exit;

/**
 * Owns the single FCHub top-level admin page: menu registration, the
 * plugin-list action link, and the manifest-driven asset bootstrap for the
 * Vue app. Never touches another plugin's menu.
 */
final class AdminMenu
{
    public const PAGE_SLUG = 'fchub';

    private const SCRIPT_HANDLE = 'fchub-admin';
    private const ENTRY_KEY = 'resources/admin/main.js';

    /**
     * Test seam only. Production always resolves the real plugin path via
     * distPath(); tests point this at a disposable fixture directory instead
     * of mutating plugins/fchub/assets/dist/ (which Task 5 owns for real).
     */
    private static ?string $distPathOverride = null;

    public static function register(): void
    {
        add_menu_page(
            __('FCHub', 'fchub'),
            __('FCHub', 'fchub'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render'],
            self::iconDataUri(),
            58
        );

        // WordPress auto-creates a submenu entry matching the parent page.
        // Overwriting $submenu with our own hash-route entries is the
        // standard pattern for single-page admin apps across the FCHub family.
        global $submenu;

        $baseUrl = 'admin.php?page=' . self::PAGE_SLUG;

        $submenu[self::PAGE_SLUG] = [
            [__('Overview', 'fchub'), 'manage_options', $baseUrl],
            [__('Products', 'fchub'), 'manage_options', $baseUrl . '#/products'],
            [__('System', 'fchub'), 'manage_options', $baseUrl . '#/system'],
        ];
    }

    /**
     * @param array<int|string, string> $links
     * @return array<int|string, string>
     */
    public static function actionLinks(array $links): array
    {
        $overviewLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)),
            esc_html__('Overview', 'fchub')
        );

        array_unshift($links, $overviewLink);

        return $links;
    }

    public static function render(): void
    {
        self::enqueueAssets();

        echo '<div id="fchub-app"></div>';
    }

    /**
     * Test seam only — see $distPathOverride. Never called with a non-null
     * argument outside tests/Unit/PluginBootstrapTest.php.
     */
    public static function setDistPathOverrideForTests(?string $distPath): void
    {
        self::$distPathOverride = $distPath;
    }

    private static function distPath(): string
    {
        return self::$distPathOverride ?? (FCHUB_HUB_PATH . 'assets/dist/');
    }

    private static function enqueueAssets(): void
    {
        $manifest = new AssetManifest(self::distPath());
        $resolved = $manifest->resolve(self::ENTRY_KEY);

        if ($resolved === null) {
            // Task 5 ships the build; before that, the page still renders,
            // it just has nothing to mount into #fchub-app yet.
            return;
        }

        $distUrl = FCHUB_HUB_URL . 'assets/dist/';

        foreach ($resolved['styles'] as $index => $style) {
            wp_enqueue_style(
                self::SCRIPT_HANDLE . ($index > 0 ? '-' . $index : ''),
                $distUrl . $style,
                [],
                $resolved['version']
            );
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $distUrl . $resolved['script'],
            [],
            $resolved['version'],
            true
        );

        // wp_enqueue_script() has no native way to mark a handle as an ES
        // module, so the tag is patched directly.
        add_filter('script_loader_tag', static function (string $tag, string $handle): string {
            if ($handle === self::SCRIPT_HANDLE) {
                $tag = str_replace(' src=', ' type="module" src=', $tag);
            }

            return $tag;
        }, 10, 2);

        // wp_localize_script() runs before the module executes but the data
        // it injects isn't visible to module scripts in the same reliable
        // way, so the runtime config goes in as a plain inline script instead.
        $config = wp_json_encode([
            // Read from Routes rather than repeated as a literal: the screen
            // and the routes it calls have to agree, and agreeing by
            // coincidence is not agreeing.
            'rest_url'  => esc_url_raw(rest_url(Routes::REST_NAMESPACE . '/')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'admin_url' => admin_url(),
            'version'   => FCHUB_HUB_VERSION,
            'locale'    => get_user_locale(),
        ]);

        wp_add_inline_script(self::SCRIPT_HANDLE, "window.fchubAdmin = {$config};", 'before');
    }

    /**
     * admin_menu fires on every admin request, so this reads a file on every
     * admin page load. An unreadable one would emit a warning into the top of
     * whatever screen the customer happened to be on and hand back an empty
     * data URI regardless — so a missing icon falls back to a dashicon, which
     * is what WordPress does with an empty string anyway, and says nothing.
     */
    private static function iconDataUri(): string
    {
        $icon = FCHUB_HUB_PATH . 'assets/icons/fchub.svg';

        if (!is_file($icon) || !is_readable($icon)) {
            return '';
        }

        $svg = (string) file_get_contents($icon);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
