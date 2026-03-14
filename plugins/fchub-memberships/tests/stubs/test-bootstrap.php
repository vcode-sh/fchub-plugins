<?php

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = null
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params;

        /**
         * @param array<string, mixed> $params
         */
        public function __construct(private string $method = 'GET', private string $route = '', array $params = [])
        {
            $this->params = $params;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_json_params(): array
        {
            return $this->params;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(
            private mixed $data = null,
            private int $status = 200,
            private array $headers = []
        ) {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $users = 'wp_users';
        public int $insert_id = 0;

        public function get_results(string $query, string $output = OBJECT): array
        {
            $GLOBALS['_fchub_test_queries'][] = ['get_results', $query, $output];
            return [];
        }

        public function get_row(string $query, string $output = OBJECT): array|object|null
        {
            $GLOBALS['_fchub_test_queries'][] = ['get_row', $query, $output];
            return null;
        }

        public function get_var(string $query): string|int|float|null
        {
            $GLOBALS['_fchub_test_queries'][] = ['get_var', $query];
            return 0;
        }

        public function query(string $query): int|false
        {
            $GLOBALS['_fchub_test_queries'][] = ['query', $query];
            return 0;
        }

        public function update(string $table, array $data, array $where, ?array $format = null, ?array $where_format = null): int|false
        {
            $GLOBALS['_fchub_test_queries'][] = ['update', $table, $data, $where];
            return 1;
        }

        public function insert(string $table, array $data, ?array $format = null): int|false
        {
            $this->insert_id++;
            $GLOBALS['_fchub_test_queries'][] = ['insert', $table, $data];
            return 1;
        }

        public function delete(string $table, array $where, ?array $where_format = null): int|false
        {
            $GLOBALS['_fchub_test_queries'][] = ['delete', $table, $where];
            return 1;
        }

        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function esc_like(string $value): string
        {
            return addslashes($value);
        }
    }
}

if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
    $GLOBALS['wpdb'] = new wpdb();
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $callbacks = $GLOBALS['_fchub_test_filters'][$hook] ?? [];
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['_fchub_test_filters'][$hook][] = $callback;
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['_fchub_test_actions'][$hook][] = $callback;
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        $callbacks = $GLOBALS['_fchub_test_actions'][$hook] ?? [];
        foreach ($callbacks as $callback) {
            $callback(...$args);
        }
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args, bool $override = false): void
    {
        $GLOBALS['_fchub_test_routes'][$namespace . $route] = $args;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void
    {
        $GLOBALS['_fchub_test_activation_hooks'][$file] = $callback;
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $callback): void
    {
        $GLOBALS['_fchub_test_deactivation_hooks'][$file] = $callback;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $GLOBALS['_fchub_test_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, ?bool $autoload = null): bool
    {
        $GLOBALS['_fchub_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql', bool $gmt = false): string|int
    {
        return match ($type) {
            'mysql' => '2026-03-13 22:00:00',
            'timestamp' => 1773439200,
            default => '2026-03-13 22:00:00',
        };
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return $GLOBALS['_fchub_test_current_user_can'] ?? true;
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $value = strtolower(trim($title));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        return trim($value, '-');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value): string|false
    {
        return json_encode($value);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return $GLOBALS['_fchub_test_is_admin'] ?? false;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'https://example.com/wp-content/plugins/fchub-memberships/';
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        return false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        $GLOBALS['_fchub_test_scheduled_events'][] = [$timestamp, $recurrence, $hook, $args];
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): int|false
    {
        $GLOBALS['_fchub_test_cleared_events'][] = [$hook, $args];
        return 1;
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail(string $to, string $subject, string $message, array|string $headers = '', array|string $attachments = []): bool
    {
        $GLOBALS['_fchub_test_mails'][] = [$to, $subject, $message, $headers, $attachments];
        return true;
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): array|WP_Error
    {
        $GLOBALS['_fchub_test_remote_posts'][] = [$url, $args];
        return $GLOBALS['_fchub_test_remote_post_result'] ?? ['response' => ['code' => 200]];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
