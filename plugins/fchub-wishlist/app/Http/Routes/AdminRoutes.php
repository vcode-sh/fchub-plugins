<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Routes;

use FChubWishlist\Http\Controllers\Admin\SettingsController;
use FChubWishlist\Http\Controllers\Admin\StatsController;
use FChubWishlist\Support\Constants;

defined('ABSPATH') || exit;

final class AdminRoutes
{
    public static function register(): void
    {
        $ns = Constants::REST_NAMESPACE;

        register_rest_route($ns, '/admin/stats', [
            'methods'             => 'GET',
            'callback'            => [StatsController::class, 'get'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [SettingsController::class, 'get'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [SettingsController::class, 'update'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
        ]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
