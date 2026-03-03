<?php

// Signal test environment to avoid exit() calls
define('FCHUB_TESTING', true);
define('ABSPATH', '/tmp/wordpress/');
define('FCHUB_WISHLIST_VERSION', '1.0.0');
define('FCHUB_WISHLIST_PATH', dirname(__DIR__) . '/');
define('FCHUB_WISHLIST_URL', 'http://localhost/wp-content/plugins/fchub-wishlist/');
define('FCHUB_WISHLIST_DB_VERSION', '1.0.0');
define('FCHUB_WISHLIST_FILE', dirname(__DIR__) . '/fchub-wishlist.php');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
define('OBJECT', 'OBJECT');
define('COOKIEPATH', '/');
define('COOKIE_DOMAIN', '');
define('WP_DEBUG', true);
define('FLUENTCART_VERSION', '1.3.13');
define('FLUENTCRM', true);

// Mock $wpdb global
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
        public string $posts = 'wp_posts';
        public int $insert_id = 0;
        /** @var array<int, string> */
        public array $queries = [];

        public function prepare($query, ...$args)
        {
            $this->queries[] = $query;
            return $query;
        }

        public function get_results($query, $output = 'OBJECT')
        {
            $this->queries[] = $query;
            return $GLOBALS['wpdb_mock_results'] ?? [];
        }

        public function get_row($query, $output = 'OBJECT', $y = 0)
        {
            $this->queries[] = $query;
            return $GLOBALS['wpdb_mock_row'] ?? null;
        }

        public function get_var($query)
        {
            $this->queries[] = $query;
            return $GLOBALS['wpdb_mock_var'] ?? null;
        }

        public function get_col($query)
        {
            $this->queries[] = $query;
            return $GLOBALS['wpdb_mock_col'] ?? [];
        }

        public function insert($table, $data, $format = null)
        {
            $this->insert_id++;
            $this->queries[] = "INSERT INTO {$table}";
            return 1;
        }

        public function update($table, $data, $where, $format = null, $where_format = null)
        {
            $this->queries[] = "UPDATE {$table}";
            return 1;
        }

        public function delete($table, $where, $where_format = null)
        {
            $this->queries[] = "DELETE FROM {$table}";
            return 1;
        }

        public function query($query)
        {
            $this->queries[] = $query;
            return $GLOBALS['wpdb_mock_query_result'] ?? true;
        }

        public function esc_like($text)
        {
            return addcslashes($text, '_%\\');
        }

        public function resetQueries(): void
        {
            $this->queries = [];
            $this->insert_id = 0;
        }
    };
}

// WordPress function mocks
$GLOBALS['wp_options'] = [];
$GLOBALS['wp_actions_fired'] = [];
$GLOBALS['wp_actions_registered'] = [];
$GLOBALS['wp_filters_registered'] = [];
$GLOBALS['wp_mock_posts'] = [];
$GLOBALS['wp_mock_current_user_id'] = 0;
$GLOBALS['wp_mock_user_caps'] = [];
$GLOBALS['wp_transients'] = [];
$GLOBALS['wp_mock_is_admin'] = false;
$GLOBALS['wp_mock_cookies'] = [];

// wpdb mock return values
$GLOBALS['wpdb_mock_results'] = [];
$GLOBALS['wpdb_mock_row'] = null;
$GLOBALS['wpdb_mock_var'] = null;
$GLOBALS['wpdb_mock_col'] = [];
$GLOBALS['wpdb_mock_query_result'] = true;

if (!function_exists('defined')) {
    // defined() is a PHP built-in, so this won't trigger
}

if (!function_exists('get_option')) {
    function get_option($key, $default = false)
    {
        return $GLOBALS['wp_options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($key, $value)
    {
        $GLOBALS['wp_options'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($key)
    {
        unset($GLOBALS['wp_options'][$key]);
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type)
    {
        if ($type === 'timestamp') {
            return time();
        }
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data)
    {
        return json_encode($data);
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults)
    {
        if (is_object($args)) {
            $args = get_object_vars($args);
        }
        if (is_object($defaults)) {
            $defaults = get_object_vars($defaults);
        }
        return array_merge($defaults, is_array($args) ? $args : []);
    }
}

if (!function_exists('do_action')) {
    function do_action($tag, ...$args)
    {
        $GLOBALS['wp_actions_fired'][] = ['tag' => $tag, 'args' => $args];
    }
}

if (!function_exists('did_action')) {
    function did_action($tag)
    {
        return count(array_filter(
            $GLOBALS['wp_actions_fired'],
            fn($a) => $a['tag'] === $tag
        ));
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['wp_filters_registered'][] = ['tag' => $tag, 'callback' => $callback, 'priority' => $priority];
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['wp_actions_registered'][] = ['tag' => $tag, 'callback' => $callback, 'priority' => $priority];
        return true;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str)
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title, $fallback_title = '', $context = 'save')
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\-]/', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        return trim($title, '-');
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default')
    {
        echo $text;
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $count, $domain = 'default')
    {
        return $count === 1 ? $single : $plural;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default')
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default')
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('absint')) {
    function absint($value)
    {
        return abs((int) $value);
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'test_nonce_' . $action;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1)
    {
        return true;
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '')
    {
        return 'http://localhost/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'http://localhost/wp-admin/' . $path;
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return 'http://localhost' . $path;
    }
}

if (!function_exists('site_url')) {
    function site_url($path = '')
    {
        return 'http://localhost' . $path;
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file)
    {
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file)
    {
        return 'http://localhost/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return $GLOBALS['wp_mock_current_user_id'] ?? 0;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args)
    {
        return $GLOBALS['wp_mock_user_caps'][$GLOBALS['wp_mock_current_user_id'] ?? 0][$capability] ?? false;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value)
    {
        return $GLOBALS['wp_mock_users'][$field . ':' . $value] ?? null;
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata($userId)
    {
        return $GLOBALS['wp_mock_users']['ID:' . $userId] ?? null;
    }
}

if (!function_exists('get_post')) {
    function get_post($id = null)
    {
        return $GLOBALS['wp_mock_posts'][$id] ?? null;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl()
    {
        return false;
    }
}

if (!function_exists('is_admin')) {
    function is_admin()
    {
        return $GLOBALS['wp_mock_is_admin'] ?? false;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('error_log')) {
    // error_log is a PHP built-in, no override needed
}

if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = false)
    {
        if ($timestamp === false) {
            $timestamp = time();
        }
        return date($format, $timestamp);
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null)
    {
        return date($format, $timestamp ?? time());
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [])
    {
        return true;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = [])
    {
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = [])
    {
        return 0;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = [])
    {
        return false;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {}
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {}
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false) {}
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all') {}
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n) {}
}

if (!function_exists('setcookie')) {
    // setcookie is a PHP built-in, can't override in CLI
}

if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        return $GLOBALS['wp_transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0)
    {
        $GLOBALS['wp_transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key)
    {
        unset($GLOBALS['wp_transients'][$key]);
        return true;
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data)
    {
        return $data;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false)
    {
        return strip_tags($string);
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0)
    {
        return number_format($number, $decimals);
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {}
}

if (!function_exists('add_menu_page')) {
    function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon = '', $position = null) {}
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null) {}
}

// WP_Error stub
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $errors = [];
        private $error_data = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if ($code) {
                $this->errors[$code][] = $message;
                if ($data) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_message($code = '')
        {
            if (!$code) {
                $code = array_key_first($this->errors);
            }
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_code()
        {
            return array_key_first($this->errors) ?? '';
        }
    }
}

// WP_Post stub
if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public $ID = 0;
        public $post_type = 'post';
        public $post_title = '';
        public $post_content = '';
        public $post_excerpt = '';
        public $post_status = 'publish';
        public $post_name = '';
    }
}

// WP_User stub
if (!class_exists('WP_User')) {
    class WP_User
    {
        public $ID = 0;
        public $user_email = '';
        public $user_login = '';
        public $display_name = '';
        public $first_name = '';
        public $last_name = '';
    }
}

// WP_REST_Request stub
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private array $params = [];
        private ?array $jsonBody = null;

        public function __construct(string $method = 'GET', string $route = '') {}

        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        public function set_body(string $body): void
        {
            $this->jsonBody = json_decode($body, true);
        }

        public function set_json_params(array $params): void
        {
            $this->jsonBody = $params;
        }

        public function get_json_params(): array
        {
            return $this->jsonBody ?? [];
        }
    }
}

// WP_REST_Response stub
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private $data;
        private int $status;

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

// Include FluentCRM stubs
require_once __DIR__ . '/stubs/fluentcrm-stubs.php';

// Plugin class autoloader
spl_autoload_register(function ($class) {
    $prefix = 'FChubWishlist\\';
    $baseDir = __DIR__ . '/../app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Test class autoloader
spl_autoload_register(function ($class) {
    $prefix = 'FChubWishlist\\Tests\\';
    $baseDir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
