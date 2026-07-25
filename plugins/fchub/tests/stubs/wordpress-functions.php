<?php

declare(strict_types=1);

// Minimal WordPress function stubs for FCHub's own PHPUnit suite. Every
// function below exists because something in app/ or uninstall.php actually
// calls it — this is not a general-purpose WordPress test harness.

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['_fchub_hub_test_action_registrations'][$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        ];

        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['_fchub_hub_test_filters'][$hook][] = $callback;

        return true;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Runs registered callbacks in registration order. Real WordPress orders
     * by priority; nothing in FCHub registers competing priorities on the same
     * hook, and the suite is easier to read without the extra bookkeeping.
     */
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($GLOBALS['_fchub_hub_test_filters'][$hook] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        ?callable $callback = null,
        string $iconUrl = '',
        int|float|null $position = null
    ): string {
        $GLOBALS['_fchub_hub_test_menu_pages'][] = [
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'menu_slug'  => $menuSlug,
            'callback'   => $callback,
            'icon_url'   => $iconUrl,
            'position'   => $position,
        ];

        return 'toplevel_page_' . $menuSlug;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return basename(dirname($file)) . '/' . basename($file);
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
        return 'https://example.com/wp-content/plugins/fchub/';
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return $text;
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

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
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

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value): string|false
    {
        return json_encode($value);
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $inFooter = false): void
    {
        $GLOBALS['_fchub_hub_test_enqueued_scripts'][] = [$handle, $src, $deps, $ver, $inFooter];
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void
    {
        $GLOBALS['_fchub_hub_test_enqueued_styles'][] = [$handle, $src, $deps, $ver, $media];
    }
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        $GLOBALS['_fchub_hub_test_inline_scripts'][] = [$handle, $data, $position];

        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        $blogId = $GLOBALS['_fchub_hub_test_current_blog_id'] ?? 1;

        return $GLOBALS['_fchub_hub_test_options'][$blogId][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    /**
     * @param bool|string|null $autoload Recorded rather than acted on: what the
     *        tests need to prove is that FCHub asks for `false`, because the
     *        default puts the catalogue into alloptions on every request.
     */
    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        $blogId = $GLOBALS['_fchub_hub_test_current_blog_id'] ?? 1;
        $GLOBALS['_fchub_hub_test_options'][$blogId][$option] = $value;
        $GLOBALS['_fchub_hub_test_option_autoload'][$blogId][$option] = $autoload;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        $blogId = $GLOBALS['_fchub_hub_test_current_blog_id'] ?? 1;
        unset($GLOBALS['_fchub_hub_test_options'][$blogId][$option]);

        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        $blogId = $GLOBALS['_fchub_hub_test_current_blog_id'] ?? 1;

        return $GLOBALS['_fchub_hub_test_transients'][$blogId][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $blogId = $GLOBALS['_fchub_hub_test_current_blog_id'] ?? 1;
        $GLOBALS['_fchub_hub_test_transients'][$blogId][$key] = $value;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        $blogId = $GLOBALS['_fchub_hub_test_current_blog_id'] ?? 1;
        unset($GLOBALS['_fchub_hub_test_transients'][$blogId][$key]);

        return true;
    }
}

if (!function_exists('wp_pre_kses_less_than')) {
    function wp_pre_kses_less_than(string $content): string
    {
        return (string) preg_replace_callback(
            '%<[^>]*?((?=<)|>|$)%',
            static fn (array $matches): string => str_contains($matches[0], '>')
                ? $matches[0]
                : htmlspecialchars($matches[0], ENT_QUOTES),
            $content
        );
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        // Follows WordPress's _sanitize_text_fields() step for step: a bare `<`
        // becomes &lt; (which can make the string *longer*), script and style
        // bodies go outright, remaining markup is stripped, whitespace runs
        // collapse, percent-encoded octets are removed to convergence.
        //
        // Tests still assert the properties sanitisation guarantees rather than
        // this function's exact output, so a stand-in that drifts from core
        // cannot quietly become the thing under test.
        $filtered = $str;

        if (str_contains($filtered, '<')) {
            $filtered = wp_pre_kses_less_than($filtered);
            $filtered = (string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $filtered);
            $filtered = strip_tags($filtered);
            $filtered = str_replace("<\n", "&lt;\n", $filtered);
        }

        $filtered = (string) preg_replace('/[\r\n\t ]+/', ' ', $filtered);
        $filtered = trim($filtered);

        $found = false;

        while (preg_match('/%[a-f0-9]{2}/i', $filtered, $match)) {
            $filtered = str_replace($match[0], '', $filtered);
            $found = true;
        }

        if ($found) {
            $filtered = trim((string) preg_replace('/ +/', ' ', $filtered));
        }

        return $filtered;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return in_array($capability, $GLOBALS['_fchub_hub_test_capabilities'] ?? [], true);
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        return (string) ($GLOBALS['_fchub_hub_test_bloginfo'][$show] ?? '6.7');
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public readonly string $code = '', public readonly string $message = '')
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_safe_remote_get')) {
    /**
     * Serves queued fixtures from $_fchub_hub_test_http_responses and records
     * every call. Nothing here can reach the network, which is the point.
     *
     * @param array<string, mixed> $args
     */
    function wp_safe_remote_get(string $url, array $args = []): array|WP_Error
    {
        $GLOBALS['_fchub_hub_test_http_requests'][] = ['url' => $url, 'args' => $args];

        if (!isset($GLOBALS['_fchub_hub_test_http_responses']) || !is_array($GLOBALS['_fchub_hub_test_http_responses'])) {
            $GLOBALS['_fchub_hub_test_http_responses'] = [];
        }

        $queued = array_shift($GLOBALS['_fchub_hub_test_http_responses']);

        if (!is_array($queued) || isset($queued['wp_error'])) {
            return new WP_Error(
                is_array($queued) ? (string) $queued['wp_error'] : 'http_request_failed',
                'No response was queued for this request.'
            );
        }

        return [
            'response' => ['code' => (int) ($queued['code'] ?? 200)],
            'body'     => (string) ($queued['body'] ?? ''),
            'headers'  => $queued['headers'] ?? [],
        ];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(mixed $response): int|string
    {
        return is_array($response) ? ($response['response']['code'] ?? '') : '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header(mixed $response, string $header): string
    {
        return is_array($response) ? (string) ($response['headers'][strtolower($header)] ?? '') : '';
    }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, mixed> Parameters the route pattern captured. */
        private array $urlParams = [];

        /** @var array<string, mixed> Parameters that arrived in the request body. */
        private array $bodyParams = [];

        public function __construct(private readonly string $method = 'GET', private readonly string $route = '')
        {
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        /**
         * Mirrors core's precedence for a write request: JSON and POST body
         * parameters are consulted before the ones the URL captured. That order
         * is the whole reason FCHub reads get_url_params() instead.
         */
        public function get_param(string $key): mixed
        {
            return $this->bodyParams[$key] ?? $this->urlParams[$key] ?? null;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_url_params(): array
        {
            return $this->urlParams;
        }

        /** What the route captured — the setter the FCHub routes' behaviour matches. */
        public function set_param(string $key, mixed $value): void
        {
            $this->urlParams[$key] = $value;
        }

        public function set_body_param(string $key, mixed $value): void
        {
            $this->bodyParams[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(private readonly mixed $data = null, private readonly int $status = 200)
        {
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

if (!function_exists('register_rest_route')) {
    /**
     * Records registrations so the route contract can be asserted without a
     * REST server. Nothing here dispatches anything.
     *
     * @param array<string, mixed> $args
     */
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
    {
        $GLOBALS['_fchub_hub_test_rest_routes'][] = [
            'namespace' => $namespace,
            'route' => $route,
            'args' => $args,
            'override' => $override,
        ];

        return true;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return $GLOBALS['_fchub_hub_test_is_multisite'] ?? false;
    }
}

if (!function_exists('get_sites')) {
    /**
     * @param array<string, mixed> $args
     * @return list<int>
     */
    function get_sites(array $args = []): array
    {
        $all = $GLOBALS['_fchub_hub_test_sites'] ?? [1];
        $number = (int) ($args['number'] ?? 0);
        $offset = (int) ($args['offset'] ?? 0);

        return $number > 0 ? array_slice($all, $offset, $number) : array_slice($all, $offset);
    }
}

if (!function_exists('switch_to_blog')) {
    function switch_to_blog(int $blogId): bool
    {
        $GLOBALS['_fchub_hub_test_blog_stack'][] = $GLOBALS['_fchub_hub_test_current_blog_id'] ?? 1;
        $GLOBALS['_fchub_hub_test_current_blog_id'] = $blogId;
        $GLOBALS['_fchub_hub_test_switched_blogs'][] = $blogId;

        return true;
    }
}

if (!function_exists('restore_current_blog')) {
    function restore_current_blog(): bool
    {
        if (($GLOBALS['_fchub_hub_test_blog_stack'] ?? []) !== []) {
            $GLOBALS['_fchub_hub_test_current_blog_id'] = array_pop($GLOBALS['_fchub_hub_test_blog_stack']);
        }

        return true;
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return $GLOBALS['_fchub_hub_test_current_blog_id'] ?? 1;
    }
}
