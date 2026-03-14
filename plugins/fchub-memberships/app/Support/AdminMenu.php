<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

class AdminMenu
{
    public static function register(): void
    {
        add_menu_page(
            __('Memberships', 'fchub-memberships'),
            __('Memberships', 'fchub-memberships'),
            'manage_options',
            'fchub-memberships',
            [self::class, 'render'],
            'dashicons-groups',
            30
        );

        // WordPress auto-creates a submenu item matching the parent.
        // We add our SPA hash-route submenus by directly manipulating
        // the global $submenu array — this is the standard pattern for
        // single-page admin apps (used by WooCommerce, FluentCRM, etc.).
        global $submenu;

        $baseUrl = 'admin.php?page=fchub-memberships';

        $submenu['fchub-memberships'] = [
            [__('Dashboard', 'fchub-memberships'), 'manage_options', $baseUrl],
            [__('Plans', 'fchub-memberships'),     'manage_options', $baseUrl . '#/plans'],
            [__('Members', 'fchub-memberships'),   'manage_options', $baseUrl . '#/members'],
            [__('Content', 'fchub-memberships'),   'manage_options', $baseUrl . '#/content'],
            [__('Drip', 'fchub-memberships'),      'manage_options', $baseUrl . '#/drip'],
            [__('Reports', 'fchub-memberships'),   'manage_options', $baseUrl . '#/reports'],
            [__('Settings', 'fchub-memberships'),  'manage_options', $baseUrl . '#/settings'],
        ];

        // Suppress third-party admin notices on our SPA page.
        // The load-{page} hook fires before notices render, so removing
        // all callbacks prevents other plugins from injecting HTML that
        // overlaps our Vue app.
        add_action('load-toplevel_page_fchub-memberships', [self::class, 'suppressAdminNotices']);
    }

    public static function suppressAdminNotices(): void
    {
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }

    public static function render(): void
    {
        self::enqueueAssets();
        echo '<style>#wpbody-content { padding-bottom: 0; } #wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error, #wpbody-content > .update-nag { display: none !important; }</style>';
        // Apply dark class early to prevent flash of light theme
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
        echo '<div id="fchub-memberships-app"></div>';
    }

    private static function enqueueAssets(): void
    {
        $distPath = FCHUB_MEMBERSHIPS_PATH . 'assets/dist/';
        $distUrl = FCHUB_MEMBERSHIPS_URL . 'assets/dist/';

        $manifest = $distPath . '.vite/manifest.json';
        $entryKey = 'resources/admin/main.js';

        if (file_exists($manifest)) {
            $assets = json_decode(file_get_contents($manifest), true);
            $entry  = $assets[$entryKey] ?? null;
            // Use manifest mtime as cache-buster (works reliably in Docker)
            $buildVersion = (string) filemtime($manifest);

            if ($entry) {
                // Load CSS from entry's css array or standalone style.css entry
                if (!empty($entry['css'])) {
                    foreach ($entry['css'] as $i => $css) {
                        wp_enqueue_style(
                            'fchub-memberships-admin' . ($i ? "-{$i}" : ''),
                            $distUrl . $css,
                            [],
                            $buildVersion
                        );
                    }
                } elseif (!empty($assets['style.css'])) {
                    $cssFile = $assets['style.css']['file'];
                    wp_enqueue_style(
                        'fchub-memberships-admin',
                        $distUrl . $cssFile,
                        [],
                        $buildVersion
                    );
                }

                $jsFile = $entry['file'];
                wp_enqueue_script(
                    'fchub-memberships-admin',
                    $distUrl . $jsFile,
                    [],
                    $buildVersion,
                    true
                );
            }
        } else {
            // Fallback: load known filenames
            foreach (glob($distPath . 'assets/style-*.css') as $cssFile) {
                wp_enqueue_style(
                    'fchub-memberships-admin',
                    $distUrl . 'assets/' . basename($cssFile),
                    [],
                    FCHUB_MEMBERSHIPS_VERSION
                );
                break;
            }
            if (file_exists($distPath . 'fchub-memberships-admin.js')) {
                wp_enqueue_script(
                    'fchub-memberships-admin',
                    $distUrl . 'fchub-memberships-admin.js',
                    [],
                    FCHUB_MEMBERSHIPS_VERSION,
                    true
                );
            }
        }

        // Add type="module" to the script tag for ESM support
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle === 'fchub-memberships-admin') {
                $tag = str_replace(' src=', ' type="module" src=', $tag);
            }
            return $tag;
        }, 10, 2);

        // Inject config as inline script before the module (wp_localize_script doesn't work with modules)
        // Pull currency settings from FluentCart if available
        $currency = self::getCurrencyConfig();

        $config = wp_json_encode([
            'rest_url'   => esc_url_raw(rest_url('fchub-memberships/v1/')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'admin_url'  => admin_url(),
            'plugin_url' => FCHUB_MEMBERSHIPS_URL,
            'version'    => FCHUB_MEMBERSHIPS_VERSION,
            'locale'     => get_user_locale(),
            'date_format' => get_option('date_format'),
            'time_format' => get_option('time_format'),
            'currency'   => $currency,
        ]);
        wp_add_inline_script('fchub-memberships-admin', "window.fchubMembershipsAdmin = {$config};", 'before');
    }

    private static function getCurrencyConfig(): array
    {
        $storeSettings = get_option('fluent_cart_store_settings', []);
        $code = $storeSettings['currency'] ?? 'USD';
        $position = $storeSettings['currency_position'] ?? 'before';
        $decimalSep = ($storeSettings['decimal_separator'] ?? 'dot') === 'comma' ? ',' : '.';
        $thousandSep = $decimalSep === ',' ? '.' : ',';

        // Get symbol from FluentCart's CurrenciesHelper if available
        $symbol = '$';
        if (class_exists('\\FluentCart\\App\\Helpers\\CurrenciesHelper')) {
            $symbol = html_entity_decode(\FluentCart\App\Helpers\CurrenciesHelper::getCurrencySign($code));
        }

        return [
            'code'          => $code,
            'symbol'        => $symbol,
            'position'      => $position,
            'decimal_sep'   => $decimalSep,
            'thousand_sep'  => $thousandSep,
        ];
    }
}
