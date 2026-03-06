<?php

declare(strict_types=1);

namespace FchubThankYou\Support;

final class Constants
{
    // REST
    public const REST_NAMESPACE = 'fchub-thank-you/v1';

    // Script handles — ours
    public const ADMIN_SCRIPT_HANDLE = 'fchub-thank-you-admin';

    // Script handles — FluentCart (MenuHandler.php:358-361, slug = 'fluent-cart')
    public const FC_HOOKS_HANDLE = 'fluent-cart_global_admin_hooks';

    // Post meta keys on the fluent-products CPT
    public const META_ENABLED   = '_fchub_ty_enabled';    // 'yes' | 'no'
    public const META_URL       = '_fchub_ty_url';         // esc_url_raw string
    public const META_TYPE      = '_fchub_ty_type';        // 'page'|'post'|'cpt'|'url'
    public const META_TARGET_ID = '_fchub_ty_target_id';   // int post ID
    public const META_POST_TYPE = '_fchub_ty_post_type';   // string CPT slug
}
