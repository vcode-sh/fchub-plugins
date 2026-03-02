<?php

// Signal test environment to avoid exit() calls
define('FCHUB_TESTING', true);

// Mock $wpdb global so repository constructors don't trigger "prefix on null" warnings
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query) { return null; }
        public function get_col($query) { return []; }
        public function insert($table, $data, $format = null) { $this->insert_id++; return 1; }
        public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
        public function delete($table, $where, $where_format = null) { return 1; }
        public function query($query) { return true; }
        public function esc_like($text) { return addcslashes($text, '_%\\'); }
    };
}

// Mock WordPress functions needed by the plugin
if (!function_exists('get_option')) {
    $GLOBALS['wp_options'] = [];
    function get_option($key, $default = false)
    {
        return $GLOBALS['wp_options'][$key] ?? $default;
    }
    function update_option($key, $value)
    {
        $GLOBALS['wp_options'][$key] = $value;
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

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
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
        return array_merge($defaults, $args);
    }
}

if (!function_exists('do_action')) {
    $GLOBALS['wp_actions_fired'] = [];
    function do_action($tag, ...$args)
    {
        $GLOBALS['wp_actions_fired'][] = ['tag' => $tag, 'args' => $args];
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        return $value;
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

if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('absint')) {
    function absint($value)
    {
        return abs((int) $value);
    }
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

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('site_url')) {
    function site_url($path = '')
    {
        return 'http://localhost' . $path;
    }
}

// WordPress post type / taxonomy mocks
if (!function_exists('get_post_types')) {
    function get_post_types($args = [], $output = 'names')
    {
        $types = [
            'post' => (object) ['name' => 'post', 'label' => 'Posts', 'labels' => (object) ['singular_name' => 'Post'], 'menu_icon' => 'dashicons-admin-post', 'public' => true, '_builtin' => true, 'show_in_rest' => true],
            'page' => (object) ['name' => 'page', 'label' => 'Pages', 'labels' => (object) ['singular_name' => 'Page'], 'menu_icon' => 'dashicons-admin-page', 'public' => true, '_builtin' => true, 'show_in_rest' => true],
            'attachment' => (object) ['name' => 'attachment', 'label' => 'Media', 'labels' => (object) ['singular_name' => 'Media'], 'menu_icon' => '', 'public' => true, '_builtin' => true, 'show_in_rest' => true],
        ];

        // Filter by _builtin flag
        if (isset($args['_builtin']) && $args['_builtin'] === false) {
            $types = array_filter($types, fn($t) => !$t->_builtin);
        }

        if (isset($args['public']) && $args['public'] === true) {
            $types = array_filter($types, fn($t) => $t->public);
        }

        if (isset($args['show_in_rest']) && $args['show_in_rest'] === true) {
            $types = array_filter($types, fn($t) => $t->show_in_rest ?? false);
        }

        if ($output === 'names') {
            return array_combine(array_keys($types), array_keys($types));
        }

        return $types;
    }
}

if (!function_exists('get_taxonomies')) {
    function get_taxonomies($args = [], $output = 'names')
    {
        $taxonomies = [
            'category' => (object) ['name' => 'category', 'label' => 'Categories', 'labels' => (object) ['singular_name' => 'Category'], 'public' => true, '_builtin' => true],
            'post_tag' => (object) ['name' => 'post_tag', 'label' => 'Tags', 'labels' => (object) ['singular_name' => 'Tag'], 'public' => true, '_builtin' => true],
        ];

        if (isset($args['_builtin']) && $args['_builtin'] === false) {
            $taxonomies = array_filter($taxonomies, fn($t) => !$t->_builtin);
        }

        if (isset($args['public']) && $args['public'] === true) {
            $taxonomies = array_filter($taxonomies, fn($t) => $t->public);
        }

        if ($output === 'names') {
            return array_combine(array_keys($taxonomies), array_keys($taxonomies));
        }

        return $taxonomies;
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type)
    {
        return in_array($post_type, ['post', 'page', 'attachment'], true);
    }
}

if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy)
    {
        return in_array($taxonomy, ['category', 'post_tag'], true);
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object($post_type)
    {
        $types = get_post_types([], 'objects');
        return $types[$post_type] ?? null;
    }
}

if (!function_exists('get_taxonomy')) {
    function get_taxonomy($taxonomy)
    {
        $taxonomies = get_taxonomies([], 'objects');
        return $taxonomies[$taxonomy] ?? null;
    }
}

if (!function_exists('get_object_taxonomies')) {
    function get_object_taxonomies($post_type, $output = 'names')
    {
        if ($post_type === 'post' || $post_type === 'page') {
            if ($output === 'names') {
                return ['category', 'post_tag'];
            }
            $taxonomies = get_taxonomies([], 'objects');
            return array_intersect_key($taxonomies, array_flip(['category', 'post_tag']));
        }
        return [];
    }
}

if (!function_exists('get_post')) {
    function get_post($id = null)
    {
        return $GLOBALS['wp_mock_posts'][$id] ?? null;
    }
}

if (!function_exists('get_the_terms')) {
    function get_the_terms($post_id, $taxonomy)
    {
        return $GLOBALS['wp_mock_post_terms'][$post_id][$taxonomy] ?? false;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title($post_id)
    {
        $post = get_post($post_id);
        return $post ? ($post->post_title ?? '') : '';
    }
}

if (!function_exists('get_term')) {
    function get_term($term_id)
    {
        return $GLOBALS['wp_mock_terms'][$term_id] ?? null;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return false;
    }
}

if (!function_exists('user_can')) {
    function user_can($user_id, $capability)
    {
        return ($GLOBALS['wp_mock_user_caps'][$user_id][$capability] ?? false);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return $GLOBALS['wp_mock_current_user_id'] ?? 0;
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null)
    {
        return date($format, $timestamp ?? time());
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key)
    {
        unset($GLOBALS['wp_transients'][$key]);
        return true;
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

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!function_exists('is_admin')) {
    function is_admin()
    {
        return $GLOBALS['wp_mock_is_admin'] ?? false;
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax()
    {
        return $GLOBALS['wp_mock_doing_ajax'] ?? false;
    }
}

if (!function_exists('is_singular')) {
    function is_singular()
    {
        return $GLOBALS['wp_mock_is_singular'] ?? false;
    }
}

if (!function_exists('is_search')) {
    function is_search()
    {
        return $GLOBALS['wp_mock_is_search'] ?? false;
    }
}

if (!function_exists('is_author')) {
    function is_author()
    {
        return $GLOBALS['wp_mock_is_author'] ?? false;
    }
}

if (!function_exists('is_date')) {
    function is_date()
    {
        return $GLOBALS['wp_mock_is_date'] ?? false;
    }
}

if (!function_exists('is_post_type_archive')) {
    function is_post_type_archive()
    {
        return $GLOBALS['wp_mock_is_post_type_archive'] ?? false;
    }
}

if (!function_exists('is_front_page')) {
    function is_front_page()
    {
        return $GLOBALS['wp_mock_is_front_page'] ?? false;
    }
}

if (!function_exists('is_home')) {
    function is_home()
    {
        return $GLOBALS['wp_mock_is_home'] ?? false;
    }
}

if (!function_exists('get_queried_object_id')) {
    function get_queried_object_id()
    {
        return $GLOBALS['wp_mock_queried_object_id'] ?? 0;
    }
}

if (!function_exists('get_query_var')) {
    function get_query_var($key, $default = '')
    {
        return $GLOBALS['wp_mock_query_vars'][$key] ?? $default;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location, $status = 302)
    {
        $GLOBALS['wp_mock_redirect_url'] = $location;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = [])
    {
        $GLOBALS['wp_mock_die_message'] = $message;
        $GLOBALS['wp_mock_die_title'] = $title;
        $GLOBALS['wp_mock_die_args'] = $args;
    }
}

if (!function_exists('wp_login_url')) {
    function wp_login_url($redirect = '')
    {
        $url = 'http://localhost/wp-login.php';
        if ($redirect) {
            $url .= '?redirect_to=' . urlencode($redirect);
        }
        return $url;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args)
    {
        return $GLOBALS['wp_mock_user_caps'][$GLOBALS['wp_mock_current_user_id'] ?? 0][$capability] ?? false;
    }
}

if (!function_exists('comments_open')) {
    function comments_open($post_id = null)
    {
        return $GLOBALS['wp_mock_comments_open'] ?? true;
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words($text, $num_words = 55, $more = '&hellip;')
    {
        $words = preg_split('/[\s]+/', trim(strip_tags($text)));
        if (count($words) <= $num_words) {
            return trim(strip_tags($text));
        }
        return implode(' ', array_slice($words, 0, $num_words)) . $more;
    }
}

if (!function_exists('get_the_excerpt')) {
    function get_the_excerpt($post = null)
    {
        if ($post) {
            return $post->post_excerpt ?? '';
        }
        $p = $GLOBALS['wp_mock_posts']['current'] ?? null;
        return $p ? ($p->post_excerpt ?? '') : '';
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

if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = 'default')
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
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

if (!function_exists('esc_textarea')) {
    function esc_textarea($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data)
    {
        return $data;
    }
}

if (!function_exists('wpautop')) {
    function wpautop($text, $br = true)
    {
        if (trim($text) === '') {
            return '';
        }
        return '<p>' . trim($text) . '</p>';
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false)
    {
        return strip_tags($string);
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return 'http://localhost' . $path;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'http://localhost/wp-admin/' . $path;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type($post_id = null)
    {
        $post = get_post($post_id);
        return $post ? ($post->post_type ?? false) : false;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post_id = null)
    {
        return $GLOBALS['wp_mock_permalink'] ?? 'http://localhost/sample-post/';
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user()
    {
        $userId = $GLOBALS['wp_mock_current_user_id'] ?? 0;
        $user = new \stdClass();
        $user->ID = $userId;
        $user->display_name = $userId ? 'Test User' : '';
        return $user;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1)
    {
        return true;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
    {
        return '';
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true)
    {
        return ($checked == $current) ? ' checked="checked"' : '';
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true)
    {
        return ($selected == $current) ? ' selected="selected"' : '';
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $count, $domain = 'default')
    {
        return $count === 1 ? $single : $plural;
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '', $force = false, &$found = null)
    {
        $found = false;
        return false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0)
    {
        return true;
    }
}

if (!function_exists('get_posts')) {
    function get_posts($args = [])
    {
        return [];
    }
}

if (!function_exists('wp_setup_nav_menu_item')) {
    function wp_setup_nav_menu_item($menuItem)
    {
        if (!isset($menuItem->title)) {
            $menuItem->title = $menuItem->post_title ?? '';
        }
        return $menuItem;
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($id = 0, $context = 'display')
    {
        return 'http://localhost/wp-admin/post.php?post=' . $id . '&action=edit';
    }
}

if (!function_exists('get_edit_term_link')) {
    function get_edit_term_link($term_id, $taxonomy = '', $object_type = '')
    {
        return 'http://localhost/wp-admin/term.php?tag_ID=' . $term_id;
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

if (!function_exists('add_meta_box')) {
    function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $callbackArgs = null)
    {
        return true;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '')
    {
        if (is_array($args)) {
            $separator = strpos($url, '?') === false ? '?' : '&';
            return $url . $separator . http_build_query($args);
        }
        return $url;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
    {
    }
}

// WP_Error stub
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $errors = [];
        public function __construct($code = '', $message = '', $data = '')
        {
            if ($code) {
                $this->errors[$code][] = $message;
            }
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
    }
}

// WP_Term stub
if (!class_exists('WP_Term')) {
    class WP_Term
    {
        public $term_id = 0;
        public $name = '';
        public $slug = '';
        public $taxonomy = '';
    }
}

// WP_Query stub
if (!class_exists('WP_Query')) {
    class WP_Query
    {
        public $query_vars = [];
        private $_is_search = false;
        private $_is_author = false;
        private $_is_date = false;
        private $_is_post_type_archive = false;
        private $_is_home = false;
        private $_is_archive = false;
        private $_is_main = true;

        public function __construct(array $config = [])
        {
            $this->_is_search = $config['is_search'] ?? false;
            $this->_is_author = $config['is_author'] ?? false;
            $this->_is_date = $config['is_date'] ?? false;
            $this->_is_post_type_archive = $config['is_post_type_archive'] ?? false;
            $this->_is_home = $config['is_home'] ?? false;
            $this->_is_archive = $config['is_archive'] ?? false;
            $this->_is_main = $config['is_main'] ?? true;
            $this->query_vars = $config['query_vars'] ?? [];
        }

        public function is_search(): bool { return $this->_is_search; }
        public function is_author(): bool { return $this->_is_author; }
        public function is_date(): bool { return $this->_is_date; }
        public function is_post_type_archive(): bool { return $this->_is_post_type_archive; }
        public function is_home(): bool { return $this->_is_home; }
        public function is_archive(): bool { return $this->_is_archive; }
        public function is_main_query(): bool { return $this->_is_main; }

        public function get($key, $default = '')
        {
            return $this->query_vars[$key] ?? $default;
        }

        public function set($key, $value): void
        {
            $this->query_vars[$key] = $value;
        }
    }
}

// WP_REST_Request stub
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private array $params = [];
        private ?array $jsonBody = null;

        public function __construct(string $method = 'GET', string $route = '')
        {
        }

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

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [])
    {
        return true;
    }
}

if (!defined('FCHUB_MEMBERSHIPS_URL')) {
    define('FCHUB_MEMBERSHIPS_URL', 'http://localhost/wp-content/plugins/fchub-memberships/');
}

if (!defined('FCHUB_MEMBERSHIPS_VERSION')) {
    define('FCHUB_MEMBERSHIPS_VERSION', '1.0.0-test');
}

// Initialize mock globals
$GLOBALS['wp_mock_posts'] = [];
$GLOBALS['wp_mock_terms'] = [];
$GLOBALS['wp_mock_post_terms'] = [];
$GLOBALS['wp_mock_user_caps'] = [];
$GLOBALS['wp_mock_current_user_id'] = 0;
$GLOBALS['wp_transients'] = [];
$GLOBALS['wp_mock_is_admin'] = false;
$GLOBALS['wp_mock_doing_ajax'] = false;
$GLOBALS['wp_mock_is_singular'] = false;
$GLOBALS['wp_mock_is_search'] = false;
$GLOBALS['wp_mock_is_author'] = false;
$GLOBALS['wp_mock_is_date'] = false;
$GLOBALS['wp_mock_is_post_type_archive'] = false;
$GLOBALS['wp_mock_is_front_page'] = false;
$GLOBALS['wp_mock_is_home'] = false;
$GLOBALS['wp_mock_queried_object_id'] = 0;
$GLOBALS['wp_mock_query_vars'] = [];
$GLOBALS['wp_mock_redirect_url'] = null;
$GLOBALS['wp_mock_die_message'] = null;
$GLOBALS['wp_mock_comments_open'] = true;
$GLOBALS['wp_mock_permalink'] = 'http://localhost/sample-post/';

// Stub FluentCRM classes for unit tests
require_once __DIR__ . '/stubs/fluentcrm-stubs.php';

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'FChubMemberships\\';
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

// Autoloader for test classes
spl_autoload_register(function ($class) {
    $prefix = 'FChubMemberships\\Tests\\';
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
