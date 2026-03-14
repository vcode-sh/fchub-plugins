<?php

/**
 * PHPUnit bootstrap — mocks WordPress and FluentCart for standalone unit testing.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

// ──────────────────────────────────────────────────────────
// WordPress constants
// ──────────────────────────────────────────────────────────

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('FCHUB_FAKTUROWNIA_VERSION')) {
    define('FCHUB_FAKTUROWNIA_VERSION', '1.1.1-test');
}

if (!defined('FCHUB_FAKTUROWNIA_PATH')) {
    define('FCHUB_FAKTUROWNIA_PATH', dirname(__DIR__) . '/');
}

if (!defined('FCHUB_FAKTUROWNIA_URL')) {
    define('FCHUB_FAKTUROWNIA_URL', 'http://localhost/wp-content/plugins/fchub-fakturownia/');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// ──────────────────────────────────────────────────────────
// Global test state
// ──────────────────────────────────────────────────────────

$GLOBALS['_fchub_test_scheduled_events'] = [];
$GLOBALS['_fchub_test_cleared_events'] = [];
$GLOBALS['_fchub_test_current_user_can'] = true;
$GLOBALS['_fchub_test_wp_remote'] = null;
$GLOBALS['_fchub_test_options'] = [];
$GLOBALS['_fchub_test_filters'] = [];

// ──────────────────────────────────────────────────────────
// WordPress function mocks
// ──────────────────────────────────────────────────────────

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default') {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_js')) {
    function esc_js($text) {
        return addslashes($text);
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($name) {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []) {
        if (is_object($args)) {
            $args = get_object_vars($args);
        }
        return array_merge($defaults, (array) $args);
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show) {
        return $show === 'name' ? 'Test Store' : '';
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null) {
        // Use WordPress timezone simulation — defaults to UTC in tests
        $tz = $GLOBALS['_fchub_test_wp_timezone'] ?? 'UTC';
        $dt = new DateTime('now', new DateTimeZone($tz));
        if ($timestamp !== null) {
            $dt->setTimestamp($timestamp);
        }
        return $dt->format($format);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return $GLOBALS['_fchub_test_current_user_can'] ?? true;
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return $GLOBALS['_fchub_test_is_admin'] ?? false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        $GLOBALS['_fchub_test_scheduled_events'][] = [
            'timestamp' => $timestamp,
            'hook'      => $hook,
            'args'      => $args,
        ];
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = []) {
        $GLOBALS['_fchub_test_cleared_events'][] = [
            'hook' => $hook,
            'args' => $args,
        ];
        return 0;
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        return _fchub_test_wp_remote('GET', $url, $args);
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) {
        return _fchub_test_wp_remote('POST', $url, $args);
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return $response['response']['code'] ?? 200;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return $response['body'] ?? '';
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, $header) {
        return $response['headers'][$header] ?? '';
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) {
        $sep = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $sep . http_build_query($args);
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '') {
        return 'http://localhost/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['_fchub_test_filters'][$hook][] = [
            'callback'      => $callback,
            'priority'       => $priority,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        add_filter($hook, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        // Return value as-is unless tests register overrides
        return $value;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args) {
        // No-op
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {
        // No-op
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key) {
        return $GLOBALS['_fchub_test_transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) {
        $GLOBALS['_fchub_test_transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        unset($GLOBALS['_fchub_test_transients'][$key]);
        return true;
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return 'http://localhost/wp-content/plugins/fchub-fakturownia/';
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return 'fchub-fakturownia/fchub-fakturownia.php';
    }
}

if (!function_exists('wp_enqueue_scripts')) {
    function wp_enqueue_scripts() {}
}

if (!function_exists('wp_register_script')) {
    function wp_register_script() {}
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script() {}
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script() {}
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'http://localhost/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_send_json')) {
    function wp_send_json($data, $status = 200) {
        throw new \FChubFakturownia\Tests\WpSendJsonException($data, $status);
    }
}

if (!function_exists('fluent_cart_get_option')) {
    function fluent_cart_get_option($key, $default = null) {
        return $GLOBALS['_fchub_test_options'][$key] ?? $default;
    }
}

if (!function_exists('fluent_cart_update_option')) {
    function fluent_cart_update_option($key, $value) {
        $GLOBALS['_fchub_test_options'][$key] = $value;
    }
}

/**
 * Internal: dispatch test HTTP requests
 */
function _fchub_test_wp_remote($method, $url, $args) {
    $handler = $GLOBALS['_fchub_test_wp_remote'] ?? null;
    if (is_callable($handler)) {
        return $handler($method, $url, $args);
    }
    return new \WP_Error('not_mocked', 'HTTP request not mocked in test');
}

// ──────────────────────────────────────────────────────────
// WordPress class mocks
// ──────────────────────────────────────────────────────────

if (!class_exists('WP_Error')) {
    class WP_Error {
        protected string $code;
        protected string $message;
        protected mixed $data;
        protected array $errors = [];

        public function __construct($code = '', $message = '', $data = '') {
            $this->code = (string) $code;
            $this->message = $message;
            $this->data = $data;
            if ($code) {
                $this->errors[$code][] = $message;
            }
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!$this->code) {
                $this->code = (string) $code;
                $this->message = $message;
            }
        }

        public function get_error_messages($code = '') {
            if ($code) {
                return $this->errors[$code] ?? [];
            }
            $all = [];
            foreach ($this->errors as $msgs) {
                $all = array_merge($all, $msgs);
            }
            return $all;
        }

        public function has_errors() {
            return !empty($this->errors);
        }
    }
}

// ──────────────────────────────────────────────────────────
// FluentCart class mocks
// ──────────────────────────────────────────────────────────

if (!class_exists('FluentCart\\Framework\\Support\\Arr')) {
    class FluentCart_Arr {
        public static function get($array, $key, $default = null) {
            if (is_null($key)) return $array;
            if (isset($array[$key])) return $array[$key];
            foreach (explode('.', $key) as $segment) {
                if (!is_array($array) || !array_key_exists($segment, $array)) {
                    return $default;
                }
                $array = $array[$segment];
            }
            return $array;
        }
    }
    class_alias('FluentCart_Arr', 'FluentCart\\Framework\\Support\\Arr');
}

if (!class_exists('FluentCart\\App\\Models\\Order')) {
    class FluentCart_Order {
        public $id;
        public $invoice_no;
        public $payment_method;
        public $paid_at;
        public $created_at;
        public $shipping_total;
        public $shipping_tax;
        public $billing_address;
        public $customer;
        public $order_items;
        public $currency;

        private array $_meta = [];
        private array $_logs = [];

        public static function find($id) {
            // Tests override via $GLOBALS
            $mockOrders = $GLOBALS['_fchub_test_orders'] ?? [];
            return $mockOrders[$id] ?? null;
        }

        public function getMeta($key, $default = null) {
            return $this->_meta[$key] ?? $default;
        }

        public function updateMeta($key, $value) {
            $this->_meta[$key] = $value;
        }

        public function deleteMeta($key) {
            unset($this->_meta[$key]);
        }

        public function addLog($title, $content, $level, $source) {
            $this->_logs[] = [
                'title'   => $title,
                'content' => $content,
                'level'   => $level,
                'source'  => $source,
            ];
        }

        public function getTestMeta(): array {
            return $this->_meta;
        }

        public function getTestLogs(): array {
            return $this->_logs;
        }

        public function setTestMeta(array $meta): void {
            $this->_meta = $meta;
        }
    }
    class_alias('FluentCart_Order', 'FluentCart\\App\\Models\\Order');
}

if (!class_exists('FluentCart\\App\\Modules\\Integrations\\BaseIntegrationManager')) {
    abstract class FluentCart_BaseIntegrationManager {
        protected string $title;
        protected string $slug;
        protected int $priority;
        protected string $description = '';
        protected string $logo = '';
        protected string $category = '';
        protected array $scopes = [];
        protected bool $hasGlobalMenu = false;
        protected bool $disableGlobalSettings = false;
        protected $runOnBackgroundForProduct = false;
        protected $runOnBackgroundForGlobal = false;

        public function __construct(string $title, string $slug, int $priority) {
            $this->title = $title;
            $this->slug = $slug;
            $this->priority = $priority;
        }

        public function register() {
            // No-op in tests
        }

        protected function actionFields() {
            return [];
        }
    }
    class_alias('FluentCart_BaseIntegrationManager', 'FluentCart\\App\\Modules\\Integrations\\BaseIntegrationManager');
}
