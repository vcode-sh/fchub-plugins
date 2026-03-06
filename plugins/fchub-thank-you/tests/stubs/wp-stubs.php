<?php

declare(strict_types=1);

/**
 * WordPress stubs for PHPUnit and PHPStan.
 * Defines the minimum WordPress functions, constants, and classes
 * needed for unit testing without a full WordPress environment.
 */

// Constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!defined('FLUENTCART_VERSION')) {
    define('FLUENTCART_VERSION', '1.3.9');
}
if (!defined('FCHUB_THANK_YOU_VERSION')) {
    define('FCHUB_THANK_YOU_VERSION', '0.1.0');
}
if (!defined('FCHUB_THANK_YOU_PATH')) {
    define('FCHUB_THANK_YOU_PATH', dirname(__DIR__, 2) . '/');
}
if (!defined('FCHUB_THANK_YOU_URL')) {
    define('FCHUB_THANK_YOU_URL', 'http://localhost/wp-content/plugins/fchub-thank-you/');
}

// WordPress post meta functions
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        return $single ? '' : [];
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $metaKey, mixed $metaValue, mixed $prevValue = ''): int|bool
    {
        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $postId, string $metaKey, mixed $metaValue = ''): bool
    {
        return true;
    }
}

// WordPress content functions
if (!function_exists('get_permalink')) {
    function get_permalink(int|WP_Post $post = 0, bool $leavename = false): string|false
    {
        return 'http://localhost/?p=' . (is_int($post) ? $post : $post->ID);
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(int|WP_Post $post = 0): string
    {
        return 'Post Title ' . (is_int($post) ? $post : $post->ID);
    }
}

if (!function_exists('get_post_types')) {
    /**
     * @return array<string, string|WP_Post_Type>
     */
    function get_post_types(array|string $args = [], string $output = 'names', string $operator = 'and'): array
    {
        return [];
    }
}

// WordPress hook functions
if (!function_exists('add_action')) {
    function add_action(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): true
    {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): true
    {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hookName, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hookName, mixed ...$args): void
    {
    }
}

// WordPress plugin functions
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'http://localhost/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

// WordPress sanitization / escaping
if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url, ?array $protocols = null): string
    {
        return $url;
    }
}

if (!function_exists('absint')) {
    function absint(mixed $maybeint): int
    {
        return abs((int) $maybeint);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim($str);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

// WordPress REST functions
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'http://localhost/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        return 'test-nonce';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return true;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $routeNamespace, string $route, array $args = [], bool $override = false): bool
    {
        return true;
    }
}

// WordPress script functions
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, array|bool $args = false): void
    {
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $objectName, array $l10n): bool
    {
        return true;
    }
}

// WordPress i18n
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

// WordPress REST classes
if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
        public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params = [];

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
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
        public mixed $data;
        public int $status;

        public function __construct(mixed $data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
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

// WordPress query class
if (!class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var list<WP_Post> */
        public array $posts = [];
        public int $found_posts = 0;

        /** @param array<string, mixed> $query */
        public function __construct(array $query = [])
        {
        }
    }
}

// WordPress post type class
if (!class_exists('WP_Post_Type')) {
    class WP_Post_Type
    {
        public string $name;
        public string $label;
        public object $labels;

        public function __construct(string $name, string $label = '')
        {
            $this->name = $name;
            $this->label = $label;
            $this->labels = (object) ['name' => $label ?: $name];
        }
    }
}

// WordPress post class
if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_title = '';
        public string $post_status = 'publish';
    }
}
