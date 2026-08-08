<?php

declare(strict_types=1);

/**
 * get_post_status() / get_post_type() stand-ins.
 *
 * WordPress core functions, always present on a real site — nothing before
 * MigrationModule::fcProductStillExists() ever needed to read either one from
 * a unit test, so the shared bootstrap does not stub them.
 *
 * Backed by _cartshift_test_posts, keyed by post id, each entry shaped
 * ['status' => string, 'type' => string]. A post id absent from that global
 * answers false for both — the same thing real WordPress returns for a post
 * id nothing was ever inserted under, which is what an id that was hard
 * deleted (not merely trashed) looks like.
 */

if (!function_exists('get_post_status')) {
    function get_post_status(int $postId): string|false
    {
        return $GLOBALS['_cartshift_test_posts'][$postId]['status'] ?? false;
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type(int $postId): string|false
    {
        return $GLOBALS['_cartshift_test_posts'][$postId]['type'] ?? false;
    }
}
