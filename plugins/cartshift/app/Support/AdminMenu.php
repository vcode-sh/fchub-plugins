<?php

declare(strict_types=1);

namespace CartShift\Support;

use CartShift\Core\FeatureFlags;

defined('ABSPATH') || exit();

final class AdminMenu
{
    public const string MENU_SLUG = 'cartshift-migrator';

    private const string ENTRY_KEY = 'src/main.js';

    private string $hookSuffix = '';

    /** Set when the Vite build output is missing, so renderPage() can say so. */
    private bool $buildMissing = false;

    public function __construct(
        private readonly FeatureFlags $flags,
    ) {}

    public function addMenuPage(): void
    {
        $this->hookSuffix = add_management_page(
            __('WC to FluentCart Migrator', 'cartshift'),
            __('CartShift', 'cartshift'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== $this->hookSuffix) {
            return;
        }

        $distUrl      = CARTSHIFT_PLUGIN_URL . 'resources/admin/dist/';
        $manifestPath = CARTSHIFT_PLUGIN_PATH . 'resources/admin/dist/.vite/manifest.json';

        $entry = $this->readManifestEntry($manifestPath);

        if ($entry === null) {
            // No build output means no admin app. Flag it so renderPage() explains
            // itself, rather than serving an empty div and letting the admin wonder
            // whether their browser is broken.
            $this->buildMissing = true;

            return;
        }

        $buildVersion = (string) (filemtime($manifestPath) ?: CARTSHIFT_VERSION);

        foreach ($entry['css'] as $i => $css) {
            wp_enqueue_style(
                'cartshift-admin' . ($i ? "-{$i}" : ''),
                $distUrl . $css,
                [],
                $buildVersion,
            );
        }

        wp_enqueue_script(
            'cartshift-admin',
            $distUrl . $entry['file'],
            [],
            $buildVersion,
            true,
        );

        // Add type="module" for Vite ESM output.
        add_filter('script_loader_tag', function (string $tag, string $handle): string {
            if ($handle === 'cartshift-admin') {
                $tag = str_replace(' src=', ' type="module" src=', $tag);
            }
            return $tag;
        }, 10, 2);

        // wp_localize_script doesn't work with type="module" — use inline script.
        $config = wp_json_encode([
            'restUrl'  => esc_url_raw(rest_url('cartshift/v1/')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'version'  => CARTSHIFT_VERSION,
            // Read-only routing hint for the safety screen. The CLI remains
            // authoritative and rejects the retired `local` namespace.
            'sourceKey' => sanitize_key((string) apply_filters('cartshift/transfer/source_key', 'local')),
            'features' => $this->flags->all(),
        ], JSON_HEX_TAG | JSON_HEX_AMP);
        wp_add_inline_script('cartshift-admin', "window.cartshift = {$config};", 'before');
    }

    /**
     * Read the Vite entry from the build manifest.
     *
     * Returns null for every flavour of "there is no usable build here": no manifest,
     * unreadable manifest, manifest that is not JSON, manifest without our entry, entry
     * without a file. Callers get one thing to check instead of five.
     *
     * @return array{file: string, css: list<string>}|null
     */
    private function readManifestEntry(string $manifestPath): ?array
    {
        if (! is_readable($manifestPath)) {
            return null;
        }

        $raw = file_get_contents($manifestPath);

        if ($raw === false) {
            return null;
        }

        $manifest = json_decode($raw, true);

        if (! is_array($manifest)) {
            return null;
        }

        $entry = $manifest[self::ENTRY_KEY] ?? null;

        if (! is_array($entry) || ! is_string($entry['file'] ?? null) || $entry['file'] === '') {
            return null;
        }

        $css = [];
        foreach ((array) ($entry['css'] ?? []) as $path) {
            if (is_string($path) && $path !== '') {
                $css[] = $path;
            }
        }

        return ['file' => $entry['file'], 'css' => $css];
    }

    public function renderPage(): void
    {
        if ($this->buildMissing) {
            $this->renderBuildMissingNotice();

            return;
        }

        echo '<style>#wpbody-content { padding-bottom: 0; } #wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error, #wpbody-content > .update-nag { display: none !important; }</style>';
        echo '<script>'
            . '(function(){'
            . 'var t=localStorage.getItem("fcart_admin_theme");'
            . 'if(t&&t.split(":").pop()==="dark"){'
            . '["body",".wp-toolbar","#wpbody-content","#wpfooter"].forEach(function(s){'
            . 'var e=s==="body"?document.body:document.querySelector(s);'
            . 'if(e)e.classList.add("dark");'
            . '});}'
            . '})();'
            . '</script>';
        echo '<div id="cartshift-app"></div>';
    }

    /**
     * Explain a missing build instead of rendering a blank page.
     *
     * Printed inline rather than through admin_notices, because renderPage() hides
     * notices inside #wpbody-content and this is the one message that must survive that.
     */
    private function renderBuildMissingNotice(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('CartShift', 'cartshift') . '</h1>';
        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('The admin interface has not been built.', 'cartshift')
            . '</strong></p><p>'
            . esc_html__(
                'CartShift could not read resources/admin/dist/.vite/manifest.json, so there is no interface to '
                . 'show you. Installed from a release ZIP? That build is broken — fetch a fresh one. Working from '
                . 'a checkout? Run "npm install && npm run build".',
                'cartshift',
            )
            . '</p><p>'
            . esc_html__('The REST API and the "wp cartshift" CLI commands are unaffected.', 'cartshift')
            . '</p></div>';
        echo '</div>';
    }
}
