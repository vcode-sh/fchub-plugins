<?php

declare(strict_types=1);

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Reports\DashboardQueryService;

final class DashboardController
{
    public static function registerRoutes(): void
    {
        register_rest_route('fchub-memberships/v1', '/admin/dashboard', [
            'methods' => 'GET',
            'callback' => [self::class, 'dashboard'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function dashboard(\WP_REST_Request $request, ?DashboardQueryService $dashboard = null): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'data' => ($dashboard ?? new DashboardQueryService())->get(),
        ]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
