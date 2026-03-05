<?php

define('FCHUB_TESTING', true);
define('ABSPATH', '/tmp/wordpress/');
define('FCHUB_MC_VERSION', '1.0.0');
define('FCHUB_MC_PATH', dirname(__DIR__) . '/');
define('FCHUB_MC_URL', 'http://localhost/wp-content/plugins/fchub-multi-currency/');
define('FCHUB_MC_DB_VERSION', '1.0.0');
define('FCHUB_MC_FILE', dirname(__DIR__) . '/fchub-multi-currency.php');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
define('OBJECT', 'OBJECT');
define('COOKIEPATH', '/');
define('COOKIE_DOMAIN', '');
define('WP_DEBUG', true);
define('FLUENTCART_VERSION', '1.3.9');
define('FLUENTCRM', true);

// Mock $wpdb global
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
        public string $posts = 'wp_posts';
        public string $usermeta = 'wp_usermeta';
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

        public function get_charset_collate()
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
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
$GLOBALS['wp_mock_user_meta'] = [];
$GLOBALS['wp_mock_post_meta'] = [];
$GLOBALS['wp_cache_store'] = [];

// wpdb mock return values
$GLOBALS['wpdb_mock_results'] = [];
$GLOBALS['wpdb_mock_row'] = null;
$GLOBALS['wpdb_mock_var'] = null;
$GLOBALS['wpdb_mock_col'] = [];
$GLOBALS['wpdb_mock_query_result'] = true;

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

if (!function_exists('add_option')) {
    function add_option($key, $value = '', $deprecated = '', $autoload = 'yes')
    {
        if (array_key_exists($key, $GLOBALS['wp_options'])) {
            return false;
        }

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
        return trim(strip_tags((string) $str));
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

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
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

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($text)
    {
        return (string) $text;
    }
}

if (!function_exists('absint')) {
    function absint($value)
    {
        return abs((int) $value);
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

if (!function_exists('get_user_meta')) {
    function get_user_meta($userId, $key = '', $single = false)
    {
        if ($key === '') {
            return $GLOBALS['wp_mock_user_meta'][$userId] ?? [];
        }
        $value = $GLOBALS['wp_mock_user_meta'][$userId][$key] ?? null;
        if ($single) {
            return $value ?? '';
        }
        return $value !== null ? [$value] : [];
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta($userId, $key, $value)
    {
        $GLOBALS['wp_mock_user_meta'][$userId][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_user_meta')) {
    function delete_user_meta($userId, $key)
    {
        unset($GLOBALS['wp_mock_user_meta'][$userId][$key]);
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($postId, $key = '', $single = false)
    {
        if ($key === '') {
            return $GLOBALS['wp_mock_post_meta'][$postId] ?? [];
        }
        $value = $GLOBALS['wp_mock_post_meta'][$postId][$key] ?? null;
        if ($single) {
            return $value ?? '';
        }
        return $value !== null ? [$value] : [];
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($postId, $key, $value)
    {
        $GLOBALS['wp_mock_post_meta'][$postId][$key] = $value;
        return true;
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

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '')
    {
        return $GLOBALS['wp_cache_store'][$group][$key] ?? false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0)
    {
        $GLOBALS['wp_cache_store'][$group][$key] = $data;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '')
    {
        unset($GLOBALS['wp_cache_store'][$group][$key]);
        return true;
    }
}

if (!function_exists('wp_cache_add')) {
    function wp_cache_add($key, $data, $group = '', $expire = 0)
    {
        if (isset($GLOBALS['wp_cache_store'][$group][$key])) {
            return false;
        }
        $GLOBALS['wp_cache_store'][$group][$key] = $data;
        return true;
    }
}

if (!function_exists('wp_cache_flush_group')) {
    function wp_cache_flush_group($group)
    {
        unset($GLOBALS['wp_cache_store'][$group]);
        return true;
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

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = [])
    {
        return $GLOBALS['wp_mock_remote_response'] ?? new \WP_Error('mock', 'No mock response set');
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return $GLOBALS['wp_mock_remote_body'] ?? '';
    }
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

if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null) {}
}

if (!function_exists('wp_send_json')) {
    function wp_send_json($data, $status_code = 200) {
        // In tests, just store what would be sent
        $GLOBALS['wp_send_json_data'] = $data;
        $GLOBALS['wp_send_json_status'] = $status_code;
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

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0)
    {
        return number_format($number, $decimals);
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {}
}

if (!function_exists('fluent_cart_log')) {
    function fluent_cart_log($message, $context = []) {}
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

        public function get_json_params()
        {
            return $this->jsonBody;
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

// WP_User stub
if (!class_exists('WP_User')) {
    class WP_User
    {
        public $ID = 0;
        public $user_email = '';
        public $user_login = '';
        public $display_name = '';
    }
}

// Include FluentCRM stubs
require_once __DIR__ . '/stubs/fluentcrm-stubs.php';
require_once __DIR__ . '/stubs/fluentcart-stubs.php';

// Plugin class autoloader
spl_autoload_register(function ($class) {
    $prefix = 'FChubMultiCurrency\\';
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
    $prefix = 'FChubMultiCurrency\\Tests\\';
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
