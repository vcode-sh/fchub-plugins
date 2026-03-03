<?php

/**
 * Minimal bootstrap for dependency-path tests.
 *
 * Deliberately does NOT define FLUENTCART_VERSION or FLUENTCRM
 * so we can test the guard clauses in Plugin::boot() and FluentCrmModule::register().
 */

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

// NOTE: FLUENTCART_VERSION and FLUENTCRM are intentionally NOT defined here.

// Reuse the main bootstrap's wpdb mock and WP function stubs.
// We include the main bootstrap but pre-define the constants we want to skip.
// Since we can't selectively include parts, we duplicate the essentials.

$GLOBALS['wpdb'] = new class {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public int $insert_id = 0;
    public array $queries = [];

    public function prepare($query, ...$args) { $this->queries[] = $query; return $query; }
    public function get_results($query, $output = 'OBJECT') { $this->queries[] = $query; return $GLOBALS['wpdb_mock_results'] ?? []; }
    public function get_row($query, $output = 'OBJECT', $y = 0) { $this->queries[] = $query; return $GLOBALS['wpdb_mock_row'] ?? null; }
    public function get_var($query) { $this->queries[] = $query; return $GLOBALS['wpdb_mock_var'] ?? null; }
    public function get_col($query) { $this->queries[] = $query; return $GLOBALS['wpdb_mock_col'] ?? []; }
    public function insert($table, $data, $format = null) { $this->insert_id++; $this->queries[] = "INSERT INTO {$table}"; return 1; }
    public function update($table, $data, $where, $format = null, $where_format = null) { $this->queries[] = "UPDATE {$table}"; return 1; }
    public function delete($table, $where, $where_format = null) { $this->queries[] = "DELETE FROM {$table}"; return 1; }
    public function query($query) { $this->queries[] = $query; return $GLOBALS['wpdb_mock_query_result'] ?? true; }
    public function esc_like($text) { return addcslashes($text, '_%\\'); }
    public function resetQueries(): void { $this->queries = []; $this->insert_id = 0; }
};

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
$GLOBALS['wpdb_mock_results'] = [];
$GLOBALS['wpdb_mock_row'] = null;
$GLOBALS['wpdb_mock_var'] = null;
$GLOBALS['wpdb_mock_col'] = [];
$GLOBALS['wpdb_mock_query_result'] = true;

// FluentCRM mock state
$GLOBALS['fluentcrm_mock_subscriber'] = null;
$GLOBALS['fluentcrm_mock_already_in_funnel'] = false;
$GLOBALS['fluentcrm_removed_from_funnel'] = [];
$GLOBALS['fluentcrm_funnel_sequences'] = [];
$GLOBALS['fluentcrm_sequence_status_changes'] = [];
$GLOBALS['wp_mock_users'] = [];

// WordPress function stubs
if (!function_exists('get_option')) { function get_option($key, $default = false) { return $GLOBALS['wp_options'][$key] ?? $default; } }
if (!function_exists('update_option')) { function update_option($key, $value) { $GLOBALS['wp_options'][$key] = $value; return true; } }
if (!function_exists('delete_option')) { function delete_option($key) { unset($GLOBALS['wp_options'][$key]); return true; } }
if (!function_exists('current_time')) { function current_time($type) { return $type === 'timestamp' ? time() : date('Y-m-d H:i:s'); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
if (!function_exists('wp_parse_args')) { function wp_parse_args($args, $defaults) { if (is_object($args)) $args = get_object_vars($args); if (is_object($defaults)) $defaults = get_object_vars($defaults); return array_merge($defaults, is_array($args) ? $args : []); } }
if (!function_exists('do_action')) { function do_action($tag, ...$args) { $GLOBALS['wp_actions_fired'][] = ['tag' => $tag, 'args' => $args]; } }
if (!function_exists('did_action')) { function did_action($tag) { return count(array_filter($GLOBALS['wp_actions_fired'], fn($a) => $a['tag'] === $tag)); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value, ...$args) { return $value; } }
if (!function_exists('add_filter')) { function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['wp_filters_registered'][] = ['tag' => $tag, 'callback' => $callback, 'priority' => $priority]; return true; } }
if (!function_exists('add_action')) { function add_action($tag, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['wp_actions_registered'][] = ['tag' => $tag, 'callback' => $callback, 'priority' => $priority]; return true; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($str) { return trim(strip_tags($str)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($str) { return trim(strip_tags($str)); } }
if (!function_exists('__')) { function __($text, $domain = 'default') { return $text; } }
if (!function_exists('esc_html')) { function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_html__')) { function esc_html__($text, $domain = 'default') { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }
if (!function_exists('is_admin')) { function is_admin() { return $GLOBALS['wp_mock_is_admin'] ?? false; } }
if (!function_exists('register_rest_route')) { function register_rest_route($namespace, $route, $args = []) { return true; } }
if (!function_exists('add_menu_page')) { function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon = '', $position = null) {} }
if (!function_exists('add_submenu_page')) { function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null) {} }

// Stubs for WP classes
if (!class_exists('WP_Error')) {
    class WP_Error { public $errors = []; public function __construct($code = '', $message = '', $data = '') { if ($code) { $this->errors[$code][] = $message; } } }
}
if (!class_exists('WP_Post')) {
    class WP_Post { public $ID = 0; public $post_type = 'post'; public $post_title = ''; public $post_content = ''; public $post_status = 'publish'; public $post_name = ''; }
}
if (!class_exists('WP_User')) {
    class WP_User { public $ID = 0; public $user_email = ''; public $user_login = ''; public $display_name = ''; public $first_name = ''; public $last_name = ''; }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request { private array $params = []; public function __construct(string $method = 'GET', string $route = '') {} public function set_param(string $key, $value): void { $this->params[$key] = $value; } public function get_param(string $key) { return $this->params[$key] ?? null; } }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response { private $data; private int $status; public function __construct($data = null, int $status = 200) { $this->data = $data; $this->status = $status; } public function get_data() { return $this->data; } public function get_status(): int { return $this->status; } }
}

// FluentCRM stubs
require_once __DIR__ . '/stubs/fluentcrm-stubs.php';

// Autoloaders
spl_autoload_register(function ($class) {
    $prefix = 'FChubWishlist\\';
    $baseDir = __DIR__ . '/../app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require_once $file;
});

spl_autoload_register(function ($class) {
    $prefix = 'FChubWishlist\\Tests\\';
    $baseDir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require_once $file;
});
