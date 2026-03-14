<?php

declare(strict_types=1);

/**
 * Additional WP function stubs needed by controller tests.
 *
 * PlanService -> PlanRepository -> wpdb (already stubbed)
 * AuditLogger -> wp_get_current_user (need stub)
 * ResourceTypeRegistry -> get_post_types, get_taxonomies (need stubs)
 * PlanService -> delete_transient (need stub)
 */

if (!function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array
    {
        return [];
    }
}

if (!function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = [], string $output = 'names'): array
    {
        return [];
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        return (object) ['ID' => 1];
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        return true;
    }
}
