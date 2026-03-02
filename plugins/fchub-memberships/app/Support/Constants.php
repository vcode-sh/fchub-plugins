<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

class Constants
{
    // Providers
    const PROVIDER_WORDPRESS_CORE = 'wordpress_core';
    const PROVIDER_LEARNDASH = 'learndash';
    const PROVIDER_FLUENT_COMMUNITY = 'fluent_community';

    // Grant statuses
    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';
    const STATUS_REVOKED = 'revoked';
    const STATUS_EXPIRED = 'expired';

    // Protection modes
    const PROTECTION_MODE_EXPLICIT = 'explicit';
    const PROTECTION_MODE_REDIRECT = 'redirect';

    const ALLOWED_PROTECTION_MODES = [
        self::PROTECTION_MODE_EXPLICIT,
        self::PROTECTION_MODE_REDIRECT,
    ];

    // Drip types
    const DRIP_TYPE_IMMEDIATE = 'immediate';
    const DRIP_TYPE_DELAYED = 'delayed';
    const DRIP_TYPE_FIXED_DATE = 'fixed_date';

    // Access evaluation reasons
    const REASON_ADMIN_BYPASS = 'admin_bypass';
    const REASON_DIRECT_GRANT = 'direct_grant';
    const REASON_PLAN_GRANT = 'plan_grant';
    const REASON_WILDCARD_GRANT = 'wildcard_grant';
    const REASON_DRIP_LOCKED = 'drip_locked';
    const REASON_NO_GRANT = 'no_grant';
    const REASON_MEMBERSHIP_PAUSED = 'membership_paused';

    // Restriction contexts
    const CONTEXT_LOGGED_OUT = 'logged_out';
    const CONTEXT_NO_ACCESS = 'no_access';
    const CONTEXT_EXPIRED = 'expired';
    const CONTEXT_DRIP_LOCKED = 'drip_locked';
    const CONTEXT_MEMBERSHIP_PAUSED = 'membership_paused';
}
