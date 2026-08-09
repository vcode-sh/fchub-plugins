<?php

/**
 * Just enough WordPress for the units under test.
 *
 * The plugin runs inside WordPress and has no framework of its own, so the
 * handful of core functions it touches are stubbed here. Anything that would
 * need a database or a real request belongs in the playground, not in here.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['fchub_pe_filters'] = [];
$GLOBALS['fchub_pe_enqueued_styles'] = [];

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key) ?? '');
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['fchub_pe_filters'][$tag][$priority][] = $callback;
        ksort($GLOBALS['fchub_pe_filters'][$tag]);

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args)
    {
        foreach ($GLOBALS['fchub_pe_filters'][$tag] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return add_filter($tag, $callback, $priority, $acceptedArgs);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return $GLOBALS['fchub_pe_is_admin'] ?? false;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle): void
    {
        $GLOBALS['fchub_pe_enqueued_styles'][] = $handle;
    }
}

/**
 * FluentCart's own icon whitelist, copied verbatim from
 * CustomerProfileHandler::renderCustomerMenu() in FluentCart 1.6.0.
 *
 * Tests merge our filter on top of this, so if FluentCart ever widens or
 * narrows it upstream, the merge behaviour here still describes what we do.
 */
function fchub_pe_fluentcart_allowed_svg_tags(): array
{
    return [
        'svg' => [
            'xmlns' => true,
            'width' => true,
            'height' => true,
            'viewBox' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'class' => true,
        ],
        'path' => [
            'd' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
        ],
        'g' => [
            'fill' => true,
            'stroke' => true,
        ],
    ];
}

spl_autoload_register(function (string $class): void {
    foreach ([
        'FChubPortalExtender\\Tests\\' => __DIR__ . '/',
        'FChubPortalExtender\\' => dirname(__DIR__) . '/app/',
    ] as $prefix => $baseDir) {
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }

        return;
    }
});
