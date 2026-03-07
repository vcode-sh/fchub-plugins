<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;

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
            FCHUB_MC_URL . 'admin/vendor/Sortable.min.js',
            [],
            '1.15.6',
            true,
        );

        wp_enqueue_script(
            'fchub-mc-admin',
            FCHUB_MC_URL . 'admin/multi-currency-admin.js',
            ['fluent-cart_global_admin_hooks', 'fchub-mc-sortablejs'],
            (string) filemtime($bundlePath),
            true,
        );

        wp_localize_script('fchub-mc-admin', 'fchubMcAdmin', [
            'rest_url'           => esc_url_raw(rest_url(Constants::REST_NAMESPACE . '/')),
            'nonce'              => wp_create_nonce('wp_rest'),
            'currency_catalogue' => CurrencyCatalogueController::getCatalogue(),
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
