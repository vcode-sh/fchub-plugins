<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Routes;

use FChubMultiCurrency\Http\Controllers\Admin\DiagnosticsController;
use FChubMultiCurrency\Http\Controllers\Admin\RatesAdminController;
use FChubMultiCurrency\Http\Controllers\Admin\SettingsAdminController;
use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class AdminRoutes
{
    public static function register(): void
    {
        register_rest_route(Constants::REST_NAMESPACE, '/admin/rates', [
            'methods'             => 'GET',
            'callback'            => [new RatesAdminController(), 'index'],
            'permission_callback' => [self::class, 'canManage'],
        ]);

        register_rest_route(Constants::REST_NAMESPACE, '/admin/rates/refresh', [
            'methods'             => 'POST',
            'callback'            => [new RatesAdminController(), 'refresh'],
            'permission_callback' => [self::class, 'canManage'],
        ]);

        register_rest_route(Constants::REST_NAMESPACE, '/admin/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [new SettingsAdminController(), 'get'],
                'permission_callback' => [self::class, 'canManage'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [new SettingsAdminController(), 'save'],
                'permission_callback' => [self::class, 'canManage'],
            ],
        ]);

        register_rest_route(Constants::REST_NAMESPACE, '/admin/diagnostics', [
            'methods'             => 'GET',
            'callback'            => [new DiagnosticsController(), 'get'],
            'permission_callback' => [self::class, 'canManage'],
        ]);
    }

    public static function canManage(): bool
    {
        return current_user_can('manage_options');
    }
}
