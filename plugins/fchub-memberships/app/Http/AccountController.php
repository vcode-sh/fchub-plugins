<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\MemberPortal\MembershipAccountQuery;

class AccountController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/my-access', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'myAccess'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public static function myAccess(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = get_current_user_id();
        return new \WP_REST_Response((new MembershipAccountQuery())->get($userId));
    }
}
