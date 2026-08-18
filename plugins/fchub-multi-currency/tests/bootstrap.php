<?php

define('FCHUB_TESTING', true);
define('ABSPATH', '/tmp/wordpress/');
define('FCHUB_MC_VERSION', '1.4.8');
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

require_once __DIR__ . '/Support/CookieFunctions.php';

// Mock $wpdb global
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
        public string $posts = 'wp_posts';
        public string $options = 'wp_options';
        public string $usermeta = 'wp_usermeta';
        public int $insert_id = 0;
        /** @var array<int, string> */
        public array $queries = [];

        public function prepare($query, ...$args)
        {
            $this->queries[] = $query;
            $index = 0;

            return preg_replace_callback(
                '/%(?:\d+\$)?([idsf])/',
                static function (array $matches) use (&$index, $args): string {
                    $value = $args[$index++] ?? null;

                    return match ($matches[1]) {
                        'i' => '`' . str_replace('`', '``', (string) $value) . '`',
                        'd' => (string) (int) $value,
                        'f' => (string) (float) $value,
                        's' => "'" . addslashes((string) $value) . "'",
                    };
                },
                $query,
            );
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
            return $GLOBALS['wpdb_mock_update_result'] ?? 1;
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
$GLOBALS['fchub_mc_setcookie_calls'] = [];
$GLOBALS['fchub_mc_setcookie_result'] = true;
$GLOBALS['wp_mock_user_meta'] = [];
$GLOBALS['wp_mock_update_user_meta_result'] = null;
$GLOBALS['wp_mock_post_meta'] = [];
$GLOBALS['wp_cache_store'] = [];
$GLOBALS['wp_registered_scripts'] = [];
$GLOBALS['wp_registered_styles'] = [];
$GLOBALS['wp_enqueued_scripts'] = [];
$GLOBALS['wp_enqueued_styles'] = [];
$GLOBALS['wp_localized_scripts'] = [];
$GLOBALS['wp_inline_scripts'] = [];
$GLOBALS['wp_inline_styles'] = [];
$GLOBALS['wp_registered_blocks'] = [];
$GLOBALS['wp_registered_block_patterns'] = [];
$GLOBALS['wp_registered_block_pattern_categories'] = [];
$GLOBALS['wp_scheduled_events'] = [];
$GLOBALS['wp_remote_requests'] = [];
$GLOBALS['fluent_cart_logs'] = [];

// wpdb mock return values
$GLOBALS['wpdb_mock_results'] = [];
$GLOBALS['wpdb_mock_row'] = null;
$GLOBALS['wpdb_mock_var'] = null;
$GLOBALS['wpdb_mock_col'] = [];
$GLOBALS['wpdb_mock_query_result'] = true;
$GLOBALS['wpdb_mock_update_result'] = 1;

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

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null): string
    {
        return date($format, $timestamp ?? time());
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses(string $content, $allowed_html = [], $allowed_protocols = []): string
    {
        return strip_tags($content, array_map(fn($tag) => "<{$tag}>", array_keys(is_array($allowed_html) ? $allowed_html : [])));
    }
}

if (!function_exists('shortcode_atts')) {
    function shortcode_atts(array $defaults, $atts, string $shortcode = ''): array
    {
        $atts = (array) $atts;
        $out = [];
        foreach ($defaults as $name => $default) {
            $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
        }
        return $out;
    }
}

if (!function_exists('human_time_diff')) {
    function human_time_diff($from, $to = null)
    {
        $to = $to ?? time();
        $diff = abs((int) $to - (int) $from);
        $hours = (int) floor($diff / 3600);
        if ($hours > 0) {
            return $hours . ' hours';
        }

        $minutes = (int) floor($diff / 60);
        if ($minutes > 0) {
            return $minutes . ' mins';
        }

        return $diff . ' secs';
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
        $filters = array_values(array_filter(
            $GLOBALS['wp_filters_registered'],
            static fn (array $filter): bool => $filter['tag'] === $tag,
        ));
        usort($filters, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        foreach ($filters as $filter) {
            $callbackArgs = array_slice([$value, ...$args], 0, $filter['accepted_args']);
            $value = $filter['callback'](...$callbackArgs);
        }

        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['wp_filters_registered'][] = ['tag' => $tag, 'callback' => $callback, 'priority' => $priority, 'accepted_args' => $accepted_args];
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

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default')
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

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default')
    {
        $translations = get_translations_for_domain($domain);
        if (method_exists($translations, 'translate_plural')) {
            $translated = $translations->translate_plural($single, $plural, (int) $number);
            if (is_string($translated) && $translated !== '') {
                return $translated;
            }
        }

        return ((int) $number === 1) ? $single : $plural;
    }
}

if (!function_exists('_n_noop')) {
    function _n_noop($singular, $plural, $domain = null)
    {
        return [
            0          => $singular,
            1          => $plural,
            'singular' => $singular,
            'plural'   => $plural,
            'context'  => null,
            'domain'   => $domain,
        ];
    }
}

if (!function_exists('translate_nooped_plural')) {
    function translate_nooped_plural($nooped_plural, $count, $domain = 'default')
    {
        if (!empty($nooped_plural['domain'])) {
            $domain = $nooped_plural['domain'];
        }

        return _n($nooped_plural['singular'], $nooped_plural['plural'], $count, $domain);
    }
}

if (!function_exists('get_translations_for_domain')) {
    // Tests plant a mock at $GLOBALS['wp_domain_translations'][$domain] to
    // simulate a loaded textdomain; the default mirrors NOOP_Translations.
    function get_translations_for_domain($domain)
    {
        return $GLOBALS['wp_domain_translations'][$domain] ?? new class {
            public function get_header($header)
            {
                return false;
            }

            public function translate_plural($single, $plural, $count)
            {
                return null;
            }
        };
    }
}

if (!class_exists('Plural_Forms')) {
    /**
     * Test double for core's gettext plural-expression evaluator. It knows
     * only the expressions the tests exercise and throws on anything else,
     * mirroring the real parser's failure mode on malformed input.
     */
    class Plural_Forms
    {
        private Closure $selector;

        public function __construct(string $expression)
        {
            $this->selector = match (preg_replace('/\s+/', '', $expression)) {
                'n!=1', '(n!=1)' => static fn(int $n): int => $n !== 1 ? 1 : 0,
                'n==1?0:n%10>=2&&n%10<=4&&(n%100<10||n%100>=20)?1:2',
                '(n==1?0:n%10>=2&&n%10<=4&&(n%100<10||n%100>=20)?1:2)' =>
                    static fn(int $n): int => $n === 1
                        ? 0
                        : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? 1 : 2),
                default => throw new RuntimeException('Unknown plural expression in test stub: ' . $expression),
            };
        }

        public function get(int $n): int
        {
            return ($this->selector)($n);
        }
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
        if ($GLOBALS['wp_mock_update_user_meta_result'] === false) {
            return false;
        }

        $GLOBALS['wp_mock_user_meta'][$userId][$key] = $value;
        return $GLOBALS['wp_mock_update_user_meta_result'] ?? true;
    }
}

if (!function_exists('delete_user_meta')) {
    function delete_user_meta($userId, $key)
    {
        unset($GLOBALS['wp_mock_user_meta'][$userId][$key]);
        return true;
    }
}

if (!function_exists('delete_metadata')) {
    function delete_metadata($metaType, $objectId, $metaKey, $metaValue = '', $deleteAll = false)
    {
        if ($metaType !== 'user' || !$deleteAll) {
            return false;
        }

        foreach ($GLOBALS['wp_mock_user_meta'] as $userId => $metadata) {
            unset($GLOBALS['wp_mock_user_meta'][$userId][$metaKey]);
        }

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
        $GLOBALS['wp_scheduled_events'][$hook] = [
            'timestamp'  => $timestamp,
            'recurrence' => $recurrence,
            'args'       => $args,
        ];
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = [])
    {
        $cleared = isset($GLOBALS['wp_scheduled_events'][$hook]) ? 1 : 0;
        unset($GLOBALS['wp_scheduled_events'][$hook]);
        return $cleared;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = [])
    {
        return $GLOBALS['wp_scheduled_events'][$hook]['timestamp'] ?? false;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {}
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {}
}

if (!function_exists('wp_register_script')) {
    function wp_register_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false)
    {
        $GLOBALS['wp_registered_scripts'][$handle] = [
            'src' => $src,
            'deps' => $deps,
            'ver' => $ver,
            'in_footer' => $in_footer,
        ];
        return true;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false)
    {
        if ($src !== '' && !isset($GLOBALS['wp_registered_scripts'][$handle])) {
            wp_register_script($handle, $src, $deps, $ver, $in_footer);
        }

        $GLOBALS['wp_enqueued_scripts'][] = $handle;
        return true;
    }
}

if (!function_exists('wp_register_style')) {
    function wp_register_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
    {
        $GLOBALS['wp_registered_styles'][$handle] = [
            'src' => $src,
            'deps' => $deps,
            'ver' => $ver,
            'media' => $media,
        ];
        return true;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
    {
        if ($src !== '' && !isset($GLOBALS['wp_registered_styles'][$handle])) {
            wp_register_style($handle, $src, $deps, $ver, $media);
        }

        $GLOBALS['wp_enqueued_styles'][] = $handle;
        return true;
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n)
    {
        $GLOBALS['wp_localized_scripts'][$handle][$object_name] = $l10n;
        return true;
    }
}

if (!function_exists('wp_set_script_translations')) {
    function wp_set_script_translations($handle, $domain = 'default', $path = '')
    {
        $GLOBALS['wp_script_translations'][$handle] = [
            'domain' => $domain,
            'path'   => $path,
        ];
        return true;
    }
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script($handle, $data, $position = 'after')
    {
        $GLOBALS['wp_inline_scripts'][$handle][$position][] = $data;
        return true;
    }
}

if (!function_exists('wp_add_inline_style')) {
    function wp_add_inline_style($handle, $data)
    {
        $GLOBALS['wp_inline_styles'][$handle][] = $data;
        return true;
    }
}

if (!function_exists('register_block_type')) {
    function register_block_type($block_type, $args = [])
    {
        $metadata = [];

        if (is_string($block_type)) {
            $metadataFile = is_dir($block_type) ? rtrim($block_type, '/\\') . '/block.json' : $block_type;
            if (file_exists($metadataFile)) {
                $decoded = json_decode((string) file_get_contents($metadataFile), true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }
        }

        $name = $metadata['name'] ?? (string) $block_type;

        $GLOBALS['wp_registered_blocks'][$name] = [
            'block_type' => $block_type,
            'metadata' => $metadata,
            'args' => $args,
        ];

        return $GLOBALS['wp_registered_blocks'][$name];
    }
}

if (!function_exists('register_block_pattern_category')) {
    function register_block_pattern_category($category_name, $properties)
    {
        $GLOBALS['wp_registered_block_pattern_categories'][$category_name] = $properties;
        return true;
    }
}

if (!function_exists('register_block_pattern')) {
    function register_block_pattern($pattern_name, $properties)
    {
        $GLOBALS['wp_registered_block_patterns'][$pattern_name] = $properties;
        return true;
    }
}

if (!function_exists('get_block_wrapper_attributes')) {
    function get_block_wrapper_attributes($extra_attributes = [])
    {
        $attributes = [];

        foreach ((array) $extra_attributes as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $attributes[] = $name . '="' . esc_attr((string) $value) . '"';
        }

        return implode(' ', $attributes);
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = [])
    {
        $GLOBALS['wp_remote_requests'][] = [
            'url'  => $url,
            'args' => $args,
        ];
        return $GLOBALS['wp_mock_remote_response'] ?? new \WP_Error('mock', 'No mock response set');
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return $GLOBALS['wp_mock_remote_body'] ?? '';
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
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
        // Tests set the override to model an expired-but-present nonce.
        return $GLOBALS['wp_mock_verify_nonce'] ?? (is_string($nonce) && $nonce !== '');
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
    function fluent_cart_log($message, $context = [])
    {
        $GLOBALS['fluent_cart_logs'][] = [
            'message' => $message,
            'context' => $context,
        ];
    }
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
        private array $headers = [];

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

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }
    }
}

// WP_REST_Response stub
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private $data;
        private int $status;
        private array $headers = [];

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

        public function header(string $key, string $value, bool $replace = true): void
        {
            if (!$replace && isset($this->headers[$key])) {
                $this->headers[$key] .= ', ' . $value;
                return;
            }

            $this->headers[$key] = $value;
        }

        public function get_headers(): array
        {
            return $this->headers;
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

// Multisite mock globals
$GLOBALS['wp_mock_is_multisite'] = false;
$GLOBALS['wp_mock_sites'] = [];
$GLOBALS['wp_mock_current_blog_id'] = 1;

if (!function_exists('is_multisite')) {
    function is_multisite()
    {
        return $GLOBALS['wp_mock_is_multisite'] ?? false;
    }
}

if (!function_exists('get_sites')) {
    function get_sites($args = [])
    {
        return $GLOBALS['wp_mock_sites'] ?? [];
    }
}

if (!function_exists('switch_to_blog')) {
    function switch_to_blog($blog_id)
    {
        $GLOBALS['wp_mock_current_blog_id'] = $blog_id;
        return true;
    }
}

if (!function_exists('restore_current_blog')) {
    function restore_current_blog()
    {
        $GLOBALS['wp_mock_current_blog_id'] = 1;
        return true;
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

// Public API function (defined in fchub-multi-currency.php, replicated here for tests)
if (!function_exists('fchub_mc_format_price')) {
    function fchub_mc_format_price(float $basePrice): string
    {
        return FChubMultiCurrency\Integration\PublicPriceApi::formatPrice($basePrice);
    }
}

if (!function_exists('fchub_mc_get_order_display_currency')) {
    function fchub_mc_get_order_display_currency(int $orderId): ?string
    {
        return FChubMultiCurrency\Integration\PublicPriceApi::getOrderDisplayCurrency($orderId);
    }
}

if (!function_exists('fchub_mc_format_order_price')) {
    function fchub_mc_format_order_price(float $basePrice, int $orderId): string
    {
        return FChubMultiCurrency\Integration\PublicPriceApi::formatOrderPrice($basePrice, $orderId);
    }
}
