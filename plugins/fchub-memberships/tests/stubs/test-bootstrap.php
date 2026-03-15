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

if (!class_exists('WP_Post')) {
    #[\AllowDynamicProperties]
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = '';
        public string $post_title = '';
        public string $post_excerpt = '';
    }
}

if (!class_exists('WP_User')) {
    #[\AllowDynamicProperties]
    class WP_User
    {
        public int $ID = 0;
        public string $display_name = '';
        public string $user_email = '';
        public string $user_login = '';
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params;
        /** @var array<string, mixed> */
        private array $headers = [];

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

        public function get_header(string $key): mixed
        {
            return $this->headers[$key] ?? null;
        }

        public function set_header(string $key, mixed $value): void
        {
            $this->headers[$key] = $value;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
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

        public function set_data(mixed $data): void
        {
            $this->data = $data;
        }

        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var array<string, mixed> */
        private array $vars = [];
        /** @var array<int, object> */
        public array $posts = [];

        public bool $is_archive = false;
        public bool $is_home = false;
        public bool $is_search = false;
        private bool $main = true;

        public function __construct(array $vars = [])
        {
            $this->vars = $vars;
            $this->posts = get_posts($vars);
        }

        public function is_main_query(): bool
        {
            return $this->main;
        }

        public function set_main_query(bool $value): void
        {
            $this->main = $value;
        }

        public function is_archive(): bool
        {
            return $this->is_archive;
        }

        public function is_home(): bool
        {
            return $this->is_home;
        }

        public function is_search(): bool
        {
            return $this->is_search;
        }

        public function get(string $key): mixed
        {
            return $this->vars[$key] ?? null;
        }

        public function set(string $key, mixed $value): void
        {
            $this->vars[$key] = $value;
        }
    }
}

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $users = 'wp_users';
        public string $dbname = 'wordpress';
        public int $insert_id = 0;
        public int $rows_affected = 1;

        public function get_results(string $query, string $output = OBJECT): array
        {
            $GLOBALS['_fchub_test_queries'][] = ['get_results', $query, $output];

            if (isset($GLOBALS['_fchub_test_wpdb_overrides']['get_results']) && is_callable($GLOBALS['_fchub_test_wpdb_overrides']['get_results'])) {
                return $GLOBALS['_fchub_test_wpdb_overrides']['get_results']($query, $output, $this);
            }

            return [];
        }

        public function get_row(string $query, string $output = OBJECT): array|object|null
        {
            $GLOBALS['_fchub_test_queries'][] = ['get_row', $query, $output];

            if (isset($GLOBALS['_fchub_test_wpdb_overrides']['get_row']) && is_callable($GLOBALS['_fchub_test_wpdb_overrides']['get_row'])) {
                return $GLOBALS['_fchub_test_wpdb_overrides']['get_row']($query, $output, $this);
            }

            return null;
        }

        public function get_var(string $query): string|int|float|null
        {
            $GLOBALS['_fchub_test_queries'][] = ['get_var', $query];

            if (isset($GLOBALS['_fchub_test_wpdb_overrides']['get_var']) && is_callable($GLOBALS['_fchub_test_wpdb_overrides']['get_var'])) {
                return $GLOBALS['_fchub_test_wpdb_overrides']['get_var']($query, $this);
            }

            return 0;
        }

        public function get_col(string $query, int $columnOffset = 0): array
        {
            $GLOBALS['_fchub_test_queries'][] = ['get_col', $query, $columnOffset];

            if (isset($GLOBALS['_fchub_test_wpdb_overrides']['get_col']) && is_callable($GLOBALS['_fchub_test_wpdb_overrides']['get_col'])) {
                return $GLOBALS['_fchub_test_wpdb_overrides']['get_col']($query, $columnOffset, $this);
            }

            return [];
        }

        public function query(string $query): int|false
        {
            $GLOBALS['_fchub_test_queries'][] = ['query', $query];

            if (isset($GLOBALS['_fchub_test_wpdb_overrides']['query']) && is_callable($GLOBALS['_fchub_test_wpdb_overrides']['query'])) {
                return $GLOBALS['_fchub_test_wpdb_overrides']['query']($query, $this);
            }

            return 0;
        }

        public function update(string $table, array $data, array $where, ?array $format = null, ?array $where_format = null): int|false
        {
            $GLOBALS['_fchub_test_queries'][] = ['update', $table, $data, $where];

            if (isset($GLOBALS['_fchub_test_wpdb_overrides']['update']) && is_callable($GLOBALS['_fchub_test_wpdb_overrides']['update'])) {
                return $GLOBALS['_fchub_test_wpdb_overrides']['update']($table, $data, $where, $this);
            }

            return 1;
        }

        public function insert(string $table, array $data, ?array $format = null): int|false
        {
            $this->insert_id++;
            $GLOBALS['_fchub_test_queries'][] = ['insert', $table, $data];

            if (isset($GLOBALS['_fchub_test_wpdb_overrides']['insert']) && is_callable($GLOBALS['_fchub_test_wpdb_overrides']['insert'])) {
                return $GLOBALS['_fchub_test_wpdb_overrides']['insert']($table, $data, $this);
            }

            return 1;
        }

        public function delete(string $table, array $where, ?array $where_format = null): int|false
        {
            $GLOBALS['_fchub_test_queries'][] = ['delete', $table, $where];

            if (isset($GLOBALS['_fchub_test_wpdb_overrides']['delete']) && is_callable($GLOBALS['_fchub_test_wpdb_overrides']['delete'])) {
                return $GLOBALS['_fchub_test_wpdb_overrides']['delete']($table, $where, $this);
            }

            return 1;
        }

        public function prepare(string $query, mixed ...$args): string
        {
            if (!$args) {
                return $query;
            }

            $normalized = str_replace(['%d', '%f', '%s'], ['%u', '%F', "'%s'"], $query);
            return vsprintf($normalized, array_map(static function (mixed $value): mixed {
                if (is_bool($value)) {
                    return $value ? 1 : 0;
                }

                return $value;
            }, $args));
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function esc_like(string $value): string
        {
            return addslashes($value);
        }

        public function suppress_errors(bool $suppress = true): bool
        {
            $previous = (bool) ($GLOBALS['_fchub_test_wpdb_suppress_errors'] ?? false);
            $GLOBALS['_fchub_test_wpdb_suppress_errors'] = $suppress;
            return $previous;
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

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
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
            'Y-m-d' => '2026-03-13',
            'c' => '2026-03-13T22:00:00+00:00',
            default => '2026-03-13 22:00:00',
        };
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return $GLOBALS['_fchub_test_current_user_can'] ?? true;
    }
}

if (!function_exists('user_can')) {
    function user_can(int|object $user, string $capability, mixed ...$args): bool
    {
        $userId = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;

        if (isset($GLOBALS['_fchub_test_user_can'][$userId][$capability])) {
            return (bool) $GLOBALS['_fchub_test_user_can'][$userId][$capability];
        }

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

if (!function_exists('wp_parse_args')) {
    function wp_parse_args(array $args, array $defaults = []): array
    {
        return array_merge($defaults, $args);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return $GLOBALS['_fchub_test_is_admin'] ?? false;
    }
}

if (!function_exists('is_singular')) {
    function is_singular(): bool
    {
        return $GLOBALS['_fchub_test_is_singular'] ?? true;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return (int) ($GLOBALS['_fchub_test_current_user_id'] ?? 0) > 0;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['_fchub_test_current_user_id'] ?? 0);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url = ''): string
    {
        $query = http_build_query($args);
        if ($url === '') {
            return '?' . $query;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . $query;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.com/' . ltrim($path, '/');
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url(?int $blogId = null, string $path = '', ?string $scheme = null): string
    {
        return 'https://example.com/' . ltrim($path, '/');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = '', string $filter = 'raw'): string
    {
        return match ($show) {
            'name' => 'Example Site',
            default => 'Example Site',
        };
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

if (!function_exists('fluent_cart_add_log')) {
    function fluent_cart_add_log(string $title, string $description = '', array $context = []): void
    {
        $GLOBALS['_fchub_test_fc_logs'][] = [$title, $description, $context];
    }
}

if (!function_exists('fluent_cart_error_log')) {
    function fluent_cart_error_log(string $title, string $description = '', array $context = []): void
    {
        $GLOBALS['_fchub_test_fc_error_logs'][] = [$title, $description, $context];
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $specialChars = true, bool $extraSpecialChars = false): string
    {
        return str_repeat('a', $length);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return '00000000-0000-4000-8000-000000000000';
    }
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int
    {
        $GLOBALS['_fchub_test_scheduled_events'][] = [$timestamp, $hook, $args, $group, $unique, $priority];
        return 1;
    }
}

if (!function_exists('collect')) {
    function collect(array $items): object
    {
        return new class($items) implements \IteratorAggregate {
            public function __construct(private array $items)
            {
            }

            public function isEmpty(): bool
            {
                return empty($this->items);
            }

            public function getIterator(): \Traversable
            {
                yield from $this->items;
            }
        };
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): array|WP_Error
    {
        $GLOBALS['_fchub_test_remote_posts'][] = [$url, $args];
        return $GLOBALS['_fchub_test_remote_post_result'] ?? ['response' => ['code' => 200]];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array $response): int
    {
        return (int) ($response['response']['code'] ?? 0);
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

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo $text;
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        return $GLOBALS['_fchub_test_current_user'] ?? (object) ['ID' => 0, 'user_email' => ''];
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = ''): mixed
    {
        return $GLOBALS['_fchub_test_cache'][$group . ':' . $key] ?? false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $value, string $group = '', int $expiration = 0): bool
    {
        $GLOBALS['_fchub_test_cache'][$group . ':' . $key] = $value;
        return true;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by(string $field, string|int $value): object|false
    {
        if ($field === 'email') {
            return $GLOBALS['_fchub_test_users_by_email'][$value] ?? false;
        }

        if ($field === 'login') {
            foreach (($GLOBALS['_fchub_test_users'] ?? []) as $user) {
                if ((string) $value === (string) ($user->user_login ?? '')) {
                    return $user;
                }
            }
        }

        foreach (($GLOBALS['_fchub_test_users'] ?? []) as $user) {
            if (($field === 'id' || $field === 'ID') && (int) $value === (int) ($user->ID ?? 0)) {
                return $user;
            }
        }

        return false;
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata(int $userId): object|false
    {
        return $GLOBALS['_fchub_test_users'][$userId] ?? false;
    }
}

if (!function_exists('get_users')) {
    function get_users(array $args = []): array
    {
        $users = array_values($GLOBALS['_fchub_test_users'] ?? []);
        $search = trim((string) ($args['search'] ?? ''));
        $number = (int) ($args['number'] ?? count($users));

        if ($search !== '') {
            $needle = trim($search, '*');
            $users = array_values(array_filter($users, static function (object $user) use ($needle): bool {
                return stripos((string) ($user->user_email ?? ''), $needle) !== false
                    || stripos((string) ($user->display_name ?? ''), $needle) !== false
                    || stripos((string) ($user->user_login ?? ''), $needle) !== false;
            }));
        }

        return array_slice($users, 0, $number);
    }
}

if (!function_exists('get_avatar_url')) {
    function get_avatar_url(int $userId, array $args = []): string
    {
        return 'https://example.com/avatar/' . $userId;
    }
}

if (!function_exists('get_post')) {
    function get_post(?int $postId = null): object|false
    {
        if ($postId === null) {
            return $GLOBALS['_fchub_test_current_post'] ?? false;
        }

        return $GLOBALS['_fchub_test_posts'][$postId] ?? false;
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type(int $postId): string|false
    {
        $post = $GLOBALS['_fchub_test_posts'][$postId] ?? null;
        return $post ? (string) ($post->post_type ?? '') : false;
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        $postType = (string) ($args['post_type'] ?? '');
        $posts = $GLOBALS['_fchub_test_posts_by_type'][$postType] ?? array_values($GLOBALS['_fchub_test_posts'] ?? []);
        $search = (string) ($args['s'] ?? '');

        if ($search !== '') {
            $posts = array_values(array_filter($posts, static function (object $post) use ($search): bool {
                return stripos((string) ($post->post_title ?? ''), $search) !== false;
            }));
        }

        if (!empty($args['tax_query'][0]['taxonomy']) && array_key_exists('terms', $args['tax_query'][0])) {
            $taxonomy = (string) $args['tax_query'][0]['taxonomy'];
            $termId = (int) $args['tax_query'][0]['terms'];

            $posts = array_values(array_filter($posts, static function (object $post) use ($taxonomy, $termId): bool {
                $terms = $GLOBALS['_fchub_test_post_terms'][(int) ($post->ID ?? 0)][$taxonomy] ?? [];
                foreach ($terms as $term) {
                    if ((int) ($term->term_id ?? 0) === $termId) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $limit = (int) ($args['posts_per_page'] ?? count($posts));
        if ($limit >= 0) {
            $posts = array_slice($posts, 0, $limit);
        }

        if (($args['fields'] ?? '') === 'ids') {
            return array_map(static fn(object $post): int => (int) ($post->ID ?? 0), $posts);
        }

        return $posts;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(int $postId = 0): string
    {
        $post = $GLOBALS['_fchub_test_posts'][$postId] ?? null;
        return $post ? (string) ($post->post_title ?? '') : '';
    }
}

if (!function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array
    {
        if ($output === 'objects') {
            return $GLOBALS['_fchub_test_post_type_objects'] ?? [];
        }

        return $GLOBALS['_fchub_test_post_types'] ?? [];
    }
}

if (!function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = [], string $output = 'names'): array
    {
        if ($output === 'objects') {
            return $GLOBALS['_fchub_test_taxonomy_objects'] ?? [];
        }

        return $GLOBALS['_fchub_test_taxonomies'] ?? [];
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $postType): object|false
    {
        return $GLOBALS['_fchub_test_post_type_objects'][$postType] ?? false;
    }
}

if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): object|false
    {
        return $GLOBALS['_fchub_test_taxonomy_objects'][$taxonomy] ?? false;
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists(string $postType): bool
    {
        return in_array($postType, $GLOBALS['_fchub_test_post_types'] ?? [], true);
    }
}

if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists(string $taxonomy): bool
    {
        return in_array($taxonomy, $GLOBALS['_fchub_test_taxonomies'] ?? [], true);
    }
}

if (!function_exists('get_term')) {
    function get_term(int $termId, string $taxonomy = ''): object|false
    {
        if ($taxonomy !== '' && isset($GLOBALS['_fchub_test_terms_by_taxonomy'][$taxonomy][$termId])) {
            return $GLOBALS['_fchub_test_terms_by_taxonomy'][$taxonomy][$termId];
        }

        return $GLOBALS['_fchub_test_terms'][$termId] ?? false;
    }
}

if (!function_exists('get_terms')) {
    function get_terms(array $args = []): array|WP_Error
    {
        $taxonomy = (string) ($args['taxonomy'] ?? '');
        $terms = array_values($GLOBALS['_fchub_test_terms_by_taxonomy'][$taxonomy] ?? []);
        $search = trim((string) ($args['search'] ?? ''));

        if ($search !== '') {
            $terms = array_values(array_filter($terms, static function (object $term) use ($search): bool {
                return stripos((string) ($term->name ?? ''), $search) !== false;
            }));
        }

        $number = (int) ($args['number'] ?? count($terms));
        return array_slice($terms, 0, $number);
    }
}

if (!function_exists('get_the_terms')) {
    function get_the_terms(int $postId, string $taxonomy): array|false
    {
        return $GLOBALS['_fchub_test_post_terms'][$postId][$taxonomy] ?? false;
    }
}

if (!function_exists('get_object_taxonomies')) {
    function get_object_taxonomies(string $postType, string $output = 'names'): array
    {
        return $GLOBALS['_fchub_test_get_object_taxonomies'][$postType] ?? [];
    }
}

if (!function_exists('wp_setup_nav_menu_item')) {
    function wp_setup_nav_menu_item(object $post): object
    {
        return $post;
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int $postId, string $context = 'display'): string
    {
        return 'https://example.com/wp-admin/post.php?post=' . $postId . '&action=edit';
    }
}

if (!function_exists('get_edit_term_link')) {
    function get_edit_term_link(int $termId, string $taxonomy = ''): string
    {
        return 'https://example.com/wp-admin/term.php?tag_ID=' . $termId . '&taxonomy=' . $taxonomy;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(int $postId = 0): string
    {
        return 'https://example.com/?p=' . $postId;
    }
}

if (!function_exists('get_term_link')) {
    function get_term_link(int $termId, string $taxonomy = ''): string
    {
        return 'https://example.com/' . trim($taxonomy . '/' . $termId, '/');
    }
}

if (!function_exists('wp_login_url')) {
    function wp_login_url(string $redirect = ''): string
    {
        return 'https://example.com/wp-login.php?redirect_to=' . rawurlencode($redirect);
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        $GLOBALS['_fchub_test_redirects'][] = [$location, $status];
        return true;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): bool
    {
        $GLOBALS['_fchub_test_enqueued_styles'][] = [$handle, $src, $deps, $ver, $media];
        return true;
    }
}

if (!function_exists('add_meta_box')) {
    function add_meta_box(string $id, string $title, callable $callback, string $screen, string $context = 'advanced', string $priority = 'default'): void
    {
        $GLOBALS['_fchub_test_meta_boxes'][] = [$id, $title, $screen, $context, $priority];
    }
}

if (!function_exists('wpautop')) {
    function wpautop(string $text): string
    {
        return '<p>' . $text . '</p>';
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $text): string
    {
        return $text;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" name="' . $name . '" value="nonce" />';
        if ($display) {
            echo $field;
        }

        return $field;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string|int $action = -1): bool
    {
        return $nonce === 'nonce';
    }
}

if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string
    {
        $result = $checked == $current ? 'checked="checked"' : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = $selected == $current ? 'selected="selected"' : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('absint')) {
    function absint(mixed $maybeint): int
    {
        return abs((int) $maybeint);
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null, ?\DateTimeZone $timezone = null): string
    {
        $timestamp ??= time();
        return gmdate($format, $timestamp);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return strip_tags($text);
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words(string $text, int $numWords = 55, string $more = '&hellip;'): string
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if (count($words) <= $numWords) {
            return trim($text);
        }

        return implode(' ', array_slice($words, 0, $numWords)) . $more;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        $GLOBALS['_fchub_test_deleted_transients'][] = $key;
        unset($GLOBALS['_fchub_test_transients'][$key]);
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        return $GLOBALS['_fchub_test_transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['_fchub_test_transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('remove_all_actions')) {
    function remove_all_actions(string $hook, int|false $priority = false): void
    {
        $GLOBALS['_fchub_test_removed_actions'][] = [$hook, $priority];
        $GLOBALS['_fchub_test_actions'][$hook] = [];
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        $GLOBALS['_fchub_test_shortcodes'][$tag] = $callback;
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, ?callable $callback = null, string $iconUrl = '', int|float|null $position = null): string
    {
        $GLOBALS['_fchub_test_menu_pages'][] = [$pageTitle, $menuTitle, $capability, $menuSlug, $iconUrl, $position];
        return 'toplevel_page_' . $menuSlug;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $inFooter = false): void
    {
        $GLOBALS['_fchub_test_enqueued_scripts'][] = [$handle, $src, $deps, $ver, $inFooter];
    }
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        $GLOBALS['_fchub_test_inline_scripts'][] = [$handle, $data, $position];
        return true;
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://example.com/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        return 'test-nonce';
    }
}

if (!function_exists('get_user_locale')) {
    function get_user_locale(): string
    {
        return 'en_US';
    }
}
