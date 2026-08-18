<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class AdminMenu
{
    public static function register(): void
    {
        global $submenu;

        $submenu['fluent-cart']['multi_currency'] = [
            __('Multi-Currency', 'fchub-multi-currency'),
            'manage_options',
            'admin.php?page=fluent-cart#/settings/multi-currency',
            '',
            'fchub_multi_currency',
        ];
    }

    /**
     * Per-tab view components; each attaches to window.FchubMcAdmin.components
     * and must load after the preview script (which carries the localized
     * config) and before the entry file that consumes the namespace.
     */
    private const ADMIN_COMPONENTS = [
        'general-settings',
        'currency-settings',
        'switcher-settings',
        'rate-settings',
        'checkout-settings',
        'crm-settings',
        'diagnostics-view',
    ];

    public static function enqueueAssets(): void
    {
        $bundlePath = FCHUB_MC_PATH . 'admin/multi-currency-admin.js';

        if (!file_exists($bundlePath)) {
            return;
        }

        wp_register_script(
            'fchub-mc-sortablejs',
            FCHUB_MC_URL . 'admin/lib/Sortable.min.js',
            [],
            '1.15.6',
            true,
        );

        $previewJsPath = FCHUB_MC_PATH . 'admin/switcher-preview.js';
        wp_register_script(
            'fchub-mc-switcher-preview',
            FCHUB_MC_URL . 'admin/switcher-preview.js',
            ['wp-i18n'],
            (string) (@filemtime($previewJsPath) ?: '1.0.0'),
            true,
        );

        $componentHandles = [];

        foreach (self::ADMIN_COMPONENTS as $component) {
            $handle = 'fchub-mc-admin-' . $component;
            $componentPath = FCHUB_MC_PATH . 'admin/components/' . $component . '.js';
            $deps = ['wp-i18n', 'fchub-mc-switcher-preview'];

            if ($component === 'currency-settings') {
                $deps[] = 'fchub-mc-sortablejs';
            }

            wp_register_script(
                $handle,
                FCHUB_MC_URL . 'admin/components/' . $component . '.js',
                $deps,
                (string) (@filemtime($componentPath) ?: '1.0.0'),
                true,
            );

            $componentHandles[] = $handle;
        }

        wp_enqueue_script(
            'fchub-mc-admin',
            FCHUB_MC_URL . 'admin/multi-currency-admin.js',
            array_merge(['wp-i18n', 'fluent-cart_global_admin_hooks'], $componentHandles),
            (string) filemtime($bundlePath),
            true,
        );

        // Every admin script reads its strings through wp.i18n; point each
        // handle at the plugin-shipped JED catalogues in languages/.
        foreach (array_merge(['fchub-mc-switcher-preview'], $componentHandles, ['fchub-mc-admin']) as $handle) {
            wp_set_script_translations($handle, 'fchub-multi-currency', FCHUB_MC_PATH . 'languages');
        }

        // Settings page CSS (tab strip, currency grid, pills)
        $adminCssPath = FCHUB_MC_PATH . 'admin/multi-currency-admin.css';
        wp_enqueue_style(
            'fchub-mc-admin-page',
            FCHUB_MC_URL . 'admin/multi-currency-admin.css',
            [],
            (string) (@filemtime($adminCssPath) ?: '1.0.0'),
        );

        // Frontend switcher CSS for admin preview
        $switcherCssPath = FCHUB_MC_PATH . 'assets/css/currency-switcher.css';
        wp_enqueue_style(
            'fchub-mc-switcher',
            FCHUB_MC_URL . 'assets/css/currency-switcher.css',
            [],
            (string) (@filemtime($switcherCssPath) ?: '1.0.0'),
        );

        // Admin preview scoping CSS
        $previewCssPath = FCHUB_MC_PATH . 'admin/admin-preview.css';
        wp_enqueue_style(
            'fchub-mc-admin-preview',
            FCHUB_MC_URL . 'admin/admin-preview.css',
            ['fchub-mc-switcher'],
            (string) (@filemtime($previewCssPath) ?: '1.0.0'),
        );

        $optionStore = new OptionStore();

        // Localize on the preview script — the first handle in the chain — so the
        // config exists before the component and entry scripts run.
        wp_localize_script('fchub-mc-switcher-preview', 'fchubMcAdmin', [
            'rest_url'            => esc_url_raw(rest_url(Constants::REST_NAMESPACE . '/')),
            'nonce'               => wp_create_nonce('wp_rest'),
            'currency_catalogue'  => CurrencyCatalogueController::getCatalogue(),
            'flag_base_url'       => FCHUB_MC_URL . 'assets/flags/4x3/',
            'flag_map'            => CurrencyCatalogueController::getSvgFlagMap(),
            'display_currencies'  => $optionStore->get('display_currencies', []),
        ]);
    }

    public static function ensureLoadOrder(): void
    {
        global $wp_scripts;

        if (isset($wp_scripts->registered['fluent-cart_admin_app_start'])) {
            $wp_scripts->registered['fluent-cart_admin_app_start']->deps[] = 'fchub-mc-admin';
        }
    }
}
