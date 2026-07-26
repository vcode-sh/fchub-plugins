<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

class Logger
{
    /**
     * Log a membership event to FluentCart's order log.
     */
    public static function orderLog($order, string $title, string $description = '', string $type = 'info'): void
    {
        if (!$order || !method_exists($order, 'addLog')) {
            return;
        }

        $order->addLog($title, $description, $type, 'Membership');
    }

    /**
     * Log a general membership event via FluentCart's logging system.
     */
    public static function log(string $title, string $description = '', array $context = []): void
    {
        if (!function_exists('fluent_cart_add_log')) {
            wp_trigger_error(__METHOD__, "[FCHub Memberships] {$title}: {$description}", E_USER_NOTICE);
            return;
        }

        fluent_cart_add_log($title, $description, array_merge([
            'module_name' => 'Membership',
        ], $context));
    }

    /**
     * Log an error.
     */
    public static function error(string $title, string $description = '', array $context = []): void
    {
        if (function_exists('fluent_cart_error_log')) {
            fluent_cart_error_log($title, $description, array_merge([
                'module_name' => 'Membership',
            ], $context));
            return;
        }

        wp_trigger_error(__METHOD__, "[FCHub Memberships ERROR] {$title}: {$description}", E_USER_WARNING);
    }

    /**
     * Log debug info (only when debug mode is enabled).
     */
    public static function debug(string $title, string $description = '', array $context = []): void
    {
        $settings = get_option('fchub_memberships_settings', []);
        if (empty($settings['debug_mode']) || $settings['debug_mode'] !== 'yes') {
            return;
        }

        self::log('[DEBUG] ' . $title, $description, $context);
    }
}
