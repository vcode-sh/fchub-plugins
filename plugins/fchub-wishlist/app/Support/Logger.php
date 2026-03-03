<?php

declare(strict_types=1);

namespace FChubWishlist\Support;

defined('ABSPATH') || exit;

final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        self::log('debug', $message, $context);
    }

    private static function log(string $level, string $message, array $context = []): void
    {
        // Use FluentCart's logger if available
        if (function_exists('fluent_cart_log')) {
            fluent_cart_log("[fchub-wishlist] [{$level}] {$message}", $context);
            return;
        }

        $contextString = $context ? ' ' . wp_json_encode($context) : '';
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional fallback logger
        error_log("[fchub-wishlist] [{$level}] {$message}{$contextString}");
    }
}
