<?php

namespace FChubPortalExtender\Support;

defined('ABSPATH') || exit;

class AdminMenu
{
    public static function register(): void
    {
        add_submenu_page(
            'fluent-cart',
            __('Portal Extender', 'fchub-portal-extender'),
            __('Portal Extender', 'fchub-portal-extender'),
            'manage_options',
            'fchub-portal-extender',
            [self::class, 'render']
        );

        // Suppress third-party admin notices on our SPA page
        add_action('load-fluent-cart_page_fchub-portal-extender', [self::class, 'suppressAdminNotices']);
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
        echo '<div id="fchub-portal-extender-app"></div>';
    }

    private static function enqueueAssets(): void
    {
        $distPath = FCHUB_PORTAL_EXTENDER_PATH . 'assets/dist/';
        $distUrl = FCHUB_PORTAL_EXTENDER_URL . 'assets/dist/';

        $manifest = $distPath . '.vite/manifest.json';
        $entryKey = 'resources/admin/main.js';

        if (file_exists($manifest)) {
            $assets = json_decode(file_get_contents($manifest), true);
            $entry  = $assets[$entryKey] ?? null;
            $buildVersion = (string) filemtime($manifest);

            if ($entry) {
                if (!empty($entry['css'])) {
                    foreach ($entry['css'] as $i => $css) {
                        wp_enqueue_style(
                            'fchub-portal-extender-admin' . ($i ? "-{$i}" : ''),
                            $distUrl . $css,
                            [],
                            $buildVersion
                        );
                    }
                } elseif (!empty($assets['style.css'])) {
                    $cssFile = $assets['style.css']['file'];
                    wp_enqueue_style(
                        'fchub-portal-extender-admin',
                        $distUrl . $cssFile,
                        [],
                        $buildVersion
                    );
                }

                $jsFile = $entry['file'];
                wp_enqueue_script(
                    'fchub-portal-extender-admin',
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
                    'fchub-portal-extender-admin',
                    $distUrl . 'assets/' . basename($cssFile),
                    [],
                    FCHUB_PORTAL_EXTENDER_VERSION
                );
                break;
            }
            if (file_exists($distPath . 'fchub-portal-extender-admin.js')) {
                wp_enqueue_script(
                    'fchub-portal-extender-admin',
                    $distUrl . 'fchub-portal-extender-admin.js',
                    [],
                    FCHUB_PORTAL_EXTENDER_VERSION,
                    true
                );
            }
        }

        // Add type="module" to the script tag for ESM support
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle === 'fchub-portal-extender-admin') {
                $tag = str_replace(' src=', ' type="module" src=', $tag);
            }
            return $tag;
        }, 10, 2);

        // Inject config as inline script before the module
        $config = wp_json_encode([
            'rest_url'   => esc_url_raw(rest_url('fchub-portal-extender/v1/')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'admin_url'  => admin_url(),
            'plugin_url' => FCHUB_PORTAL_EXTENDER_URL,
            'version'    => FCHUB_PORTAL_EXTENDER_VERSION,
        ]);
        wp_add_inline_script('fchub-portal-extender-admin', "window.fchubPortalExtenderAdmin = {$config};", 'before');
    }
}
