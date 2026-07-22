<?php

declare(strict_types=1);

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

final class MembershipMutationPermission
{
    public const CAPABILITY = 'manage_fchub_memberships';

    public static function check(\WP_REST_Request $request): bool
    {
        return current_user_can(self::CAPABILITY) || current_user_can('manage_options');
    }
}
