<?php

namespace FChubMemberships\Frontend;

defined('ABSPATH') || exit;

class FrontendAssets
{
    private static bool $enqueued = false;

    public static function enqueue(): void
    {
        if (self::$enqueued) {
            return;
        }
        self::$enqueued = true;

        $distPath = FCHUB_MEMBERSHIPS_PATH . 'assets/dist/';
        $distUrl  = FCHUB_MEMBERSHIPS_URL . 'assets/dist/';
        $manifest = $distPath . '.vite/manifest.json';
        $entryKey = 'resources/portal/main.js';

        if (file_exists($manifest)) {
            $assets = json_decode(file_get_contents($manifest), true);
            $entry  = $assets[$entryKey] ?? null;
            $buildVersion = (string) filemtime($manifest);

            if ($entry) {
                // Enqueue CSS
                if (!empty($entry['css'])) {
                    foreach ($entry['css'] as $i => $css) {
                        wp_enqueue_style(
                            'fchub-memberships-portal' . ($i ? "-{$i}" : ''),
                            $distUrl . $css,
                            [],
                            $buildVersion
                        );
                    }
                }

                // Enqueue JS
                wp_enqueue_script(
                    'fchub-memberships-portal',
                    $distUrl . $entry['file'],
                    [],
                    $buildVersion,
                    true
                );
            }
        } else {
            // Fallback for dev/missing manifest
            foreach (glob($distPath . 'assets/portal-*.css') as $cssFile) {
                wp_enqueue_style(
                    'fchub-memberships-portal',
                    $distUrl . 'assets/' . basename($cssFile),
                    [],
                    FCHUB_MEMBERSHIPS_VERSION
                );
                break;
            }
        }

        // Add type="module" for ESM
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle === 'fchub-memberships-portal') {
                $tag = str_replace(' src=', ' type="module" src=', $tag);
            }
            return $tag;
        }, 10, 2);

        // Inject portal config
        $config = wp_json_encode([
            'rest_url'    => esc_url_raw(rest_url('fchub-memberships/v1/')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'plugin_url'  => FCHUB_MEMBERSHIPS_URL,
            'version'     => FCHUB_MEMBERSHIPS_VERSION,
            'locale'      => get_locale(),
            'date_format' => get_option('date_format'),
            'time_format' => get_option('time_format'),
            'user_name'   => wp_get_current_user()->display_name,
        ]);
        wp_add_inline_script('fchub-memberships-portal', "window.fchubMembershipsPortal = {$config};", 'before');
    }
}
