<?php

defined('ABSPATH') || exit;

add_filter(
    'pre_http_request',
    static function (mixed $preempt, array $arguments, string $url): mixed {
        $slug = (string) get_option('wporg_lifecycle_observed_slug', '');
        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            return $preempt;
        }

        $pluginPath = '/wp-content/plugins/' . $slug . '/';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file === '' || !str_contains($file, $pluginPath)) {
                continue;
            }

            $attempts = (int) get_option('wporg_lifecycle_http_attempts', 0);
            update_option('wporg_lifecycle_http_attempts', $attempts + 1, false);

            return new WP_Error(
                'wporg_lifecycle_unexpected_http',
                sprintf(
                    'Blocked an unexpected HTTP request originating from %s.',
                    $slug
                )
            );
        }

        return $preempt;
    },
    PHP_INT_MAX,
    3
);
