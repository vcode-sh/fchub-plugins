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
            [],
            (string) (@filemtime($previewJsPath) ?: '1.0.0'),
            true,
        );

        wp_enqueue_script(
            'fchub-mc-admin',
            FCHUB_MC_URL . 'admin/multi-currency-admin.js',
            ['fluent-cart_global_admin_hooks', 'fchub-mc-sortablejs', 'fchub-mc-switcher-preview'],
            (string) filemtime($bundlePath),
            true,
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

        // Localize on the preview script so data is available before both scripts run.
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
