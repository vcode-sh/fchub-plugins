<?php

declare(strict_types=1);

// WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

// CartShift constants
if (!defined('CARTSHIFT_VERSION')) {
    define('CARTSHIFT_VERSION', '1.0.3');
}

if (!defined('CARTSHIFT_PLUGIN_PATH')) {
    define('CARTSHIFT_PLUGIN_PATH', dirname(__DIR__, 2) . '/');
}

if (!defined('CARTSHIFT_PLUGIN_URL')) {
    define('CARTSHIFT_PLUGIN_URL', 'https://example.com/wp-content/plugins/cartshift/');
}

if (!defined('CARTSHIFT_PLUGIN_FILE')) {
    define('CARTSHIFT_PLUGIN_FILE', dirname(__DIR__, 2) . '/cartshift.php');
}

if (!defined('CARTSHIFT_DB_VERSION')) {
    define('CARTSHIFT_DB_VERSION', '1');
}

// ──────────────────────────────────────────────
// WordPress function stubs
// ──────────────────────────────────────────────

if (!function_exists('defined')) {
    // already a PHP built-in, no stub needed
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

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['_cartshift_test_actions'][$hook][] = $callback;
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['_cartshift_test_filters'][$hook][] = $callback;
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $callbacks = $GLOBALS['_cartshift_test_filters'][$hook] ?? [];
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        $callbacks = $GLOBALS['_cartshift_test_actions'][$hook] ?? [];
        foreach ($callbacks as $callback) {
            $callback(...$args);
        }
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        return 'test-nonce-' . $action;
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://example.com/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $GLOBALS['_cartshift_test_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, ?bool $autoload = null): bool
    {
        $GLOBALS['_cartshift_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset($GLOBALS['_cartshift_test_options'][$option]);
        return true;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
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

if (!function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args(array|string $args, array $defaults = []): array
    {
        if (is_string($args)) {
            parse_str($args, $parsed);
            $args = $parsed;
        }
        return array_merge($defaults, $args);
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('set_time_limit')) {
    // PHP built-in, but may be disabled — stub for safety
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post(int $postId, bool $forceDelete = false): mixed
    {
        $GLOBALS['_cartshift_test_deleted_posts'][] = [$postId, $forceDelete];
        return (object) ['ID' => $postId];
    }
}

if (!function_exists('wp_delete_term')) {
    function wp_delete_term(int $termId, string $taxonomy): bool
    {
        $GLOBALS['_cartshift_test_deleted_terms'][] = [$termId, $taxonomy];
        return true;
    }
}

if (!function_exists('dbDelta')) {
    function dbDelta(string $sql): array
    {
        return [];
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
        return 'https://example.com/wp-content/plugins/cartshift/';
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void
    {
        $GLOBALS['_cartshift_test_activation_hooks'][$file] = $callback;
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $callback): void
    {
        $GLOBALS['_cartshift_test_deactivation_hooks'][$file] = $callback;
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachmentId): string|false
    {
        return 'https://example.com/wp-content/uploads/test.jpg';
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(int $postId): string
    {
        return 'Test Post ' . $postId;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $metaKey, mixed $metaValue): int|bool
    {
        $GLOBALS['_cartshift_test_post_meta'][$postId][$metaKey] = $metaValue;
        return true;
    }
}

if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, string|false $deprecated = false, string|false $pluginRelPath = false): bool
    {
        return true;
    }
}

// ──────────────────────────────────────────────
// WordPress class stubs
// ──────────────────────────────────────────────

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

// ──────────────────────────────────────────────
// WooCommerce class stubs
// ──────────────────────────────────────────────

if (!class_exists('WC_Product')) {
    class WC_Product
    {
        protected int $id = 0;
        protected string $name = '';
        protected string $status = 'publish';
        protected string $price = '';
        protected string $regular_price = '';
        protected string $sale_price = '';
        protected string $description = '';
        protected string $short_description = '';
        protected string $sku = '';
        protected string $weight = '';
        protected array $downloads = [];
        protected string $type = 'simple';
        protected array $meta = [];
        protected bool $virtual = false;
        protected bool $downloadable = false;
        protected int $image_id = 0;
        protected array $gallery_image_ids = [];
        protected array $category_ids = [];

        public function get_id(): int { return $this->id; }
        public function get_name(): string { return $this->name; }
        public function get_status(): string { return $this->status; }
        public function get_price(): string { return $this->price; }
        public function get_regular_price(): string { return $this->regular_price; }
        public function get_sale_price(): string { return $this->sale_price; }
        public function get_description(): string { return $this->description; }
        public function get_short_description(): string { return $this->short_description; }
        public function get_sku(): string { return $this->sku; }
        public function get_weight(): string { return $this->weight; }
        public function get_downloads(): array { return $this->downloads; }
        public function get_type(): string { return $this->type; }
        public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
        public function is_virtual(): bool { return $this->virtual; }
        public function is_downloadable(): bool { return $this->downloadable; }
        public function get_image_id(): int { return $this->image_id; }
        public function get_gallery_image_ids(): array { return $this->gallery_image_ids; }
        public function get_category_ids(): array { return $this->category_ids; }
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order
    {
        protected int $id = 0;
        protected string $status = '';
        protected string $billing_email = '';
        protected string $total = '0';
        protected string $total_tax = '0';
        protected string $shipping_total = '0';
        protected string $discount_total = '0';
        protected array $items = [];
        protected string $billing_first_name = '';
        protected string $billing_last_name = '';
        protected string $billing_address_1 = '';
        protected string $billing_address_2 = '';
        protected string $billing_city = '';
        protected string $billing_state = '';
        protected string $billing_postcode = '';
        protected string $billing_country = '';
        protected string $billing_phone = '';
        protected string $shipping_first_name = '';
        protected string $shipping_last_name = '';
        protected string $shipping_address_1 = '';
        protected string $shipping_address_2 = '';
        protected string $shipping_city = '';
        protected string $shipping_state = '';
        protected string $shipping_postcode = '';
        protected string $shipping_country = '';
        protected string $payment_method = '';
        protected ?object $date_created = null;
        protected ?object $date_paid = null;
        protected int $customer_id = 0;
        protected bool $prices_include_tax = false;
        protected string $currency = 'USD';
        protected array $meta = [];

        public function get_id(): int { return $this->id; }
        public function get_status(): string { return $this->status; }
        public function get_billing_email(): string { return $this->billing_email; }
        public function get_total(): string { return $this->total; }
        public function get_total_tax(): string { return $this->total_tax; }
        public function get_shipping_total(): string { return $this->shipping_total; }
        public function get_discount_total(): string { return $this->discount_total; }
        public function get_items(): array { return $this->items; }
        public function get_billing_first_name(): string { return $this->billing_first_name; }
        public function get_billing_last_name(): string { return $this->billing_last_name; }
        public function get_billing_address_1(): string { return $this->billing_address_1; }
        public function get_billing_address_2(): string { return $this->billing_address_2; }
        public function get_billing_city(): string { return $this->billing_city; }
        public function get_billing_state(): string { return $this->billing_state; }
        public function get_billing_postcode(): string { return $this->billing_postcode; }
        public function get_billing_country(): string { return $this->billing_country; }
        public function get_billing_phone(): string { return $this->billing_phone; }
        public function get_shipping_first_name(): string { return $this->shipping_first_name; }
        public function get_shipping_last_name(): string { return $this->shipping_last_name; }
        public function get_shipping_address_1(): string { return $this->shipping_address_1; }
        public function get_shipping_address_2(): string { return $this->shipping_address_2; }
        public function get_shipping_city(): string { return $this->shipping_city; }
        public function get_shipping_state(): string { return $this->shipping_state; }
        public function get_shipping_postcode(): string { return $this->shipping_postcode; }
        public function get_shipping_country(): string { return $this->shipping_country; }
        public function get_payment_method(): string { return $this->payment_method; }
        public function get_date_created(): ?object { return $this->date_created; }
        public function get_date_paid(): ?object { return $this->date_paid; }
        public function get_customer_id(): int { return $this->customer_id; }
        public function get_prices_include_tax(): bool { return $this->prices_include_tax; }
        public function get_currency(): string { return $this->currency; }
        public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
    }
}

if (!class_exists('WC_Coupon')) {
    class WC_Coupon
    {
        protected int $id = 0;
        protected string $code = '';
        protected string $discount_type = '';
        protected float $amount = 0.0;
        protected ?object $date_expires = null;
        protected int $usage_limit = 0;
        protected int $usage_count = 0;
        protected array $product_ids = [];
        protected array $excluded_product_ids = [];
        protected array $email_restrictions = [];
        protected array $meta = [];

        public function get_id(): int { return $this->id; }
        public function get_code(): string { return $this->code; }
        public function get_discount_type(): string { return $this->discount_type; }
        public function get_amount(): float { return $this->amount; }
        public function get_date_expires(): ?object { return $this->date_expires; }
        public function get_usage_limit(): int { return $this->usage_limit; }
        public function get_usage_count(): int { return $this->usage_count; }
        public function get_product_ids(): array { return $this->product_ids; }
        public function get_excluded_product_ids(): array { return $this->excluded_product_ids; }
        public function get_email_restrictions(): array { return $this->email_restrictions; }
        public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
    }
}

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product
    {
        protected int $product_id = 0;
        protected int $variation_id = 0;
        protected int $quantity = 1;
        protected string $total = '0';
        protected string $subtotal = '0';
        protected string $total_tax = '0';
        protected string $name = '';

        public function get_product_id(): int { return $this->product_id; }
        public function get_variation_id(): int { return $this->variation_id; }
        public function get_quantity(): int { return $this->quantity; }
        public function get_total(): string { return $this->total; }
        public function get_subtotal(): string { return $this->subtotal; }
        public function get_total_tax(): string { return $this->total_tax; }
        public function get_name(): string { return $this->name; }
    }
}

// ──────────────────────────────────────────────
// Global $wpdb stub
// ──────────────────────────────────────────────

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public int $insert_id = 0;

        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }

        public function get_var(string $query): string|int|float|null
        {
            $GLOBALS['_cartshift_test_queries'][] = ['get_var', $query];
            return 0;
        }

        public function get_col(string $query): array
        {
            $GLOBALS['_cartshift_test_queries'][] = ['get_col', $query];
            return [];
        }

        public function get_results(string $query, string $output = OBJECT): array
        {
            $GLOBALS['_cartshift_test_queries'][] = ['get_results', $query, $output];
            return [];
        }

        public function insert(string $table, array $data, ?array $format = null): int|false
        {
            $this->insert_id++;
            $GLOBALS['_cartshift_test_queries'][] = ['insert', $table, $data];
            return 1;
        }

        public function delete(string $table, array $where, ?array $where_format = null): int|false
        {
            $GLOBALS['_cartshift_test_queries'][] = ['delete', $table, $where];
            return 1;
        }

        public function query(string $query): int|false
        {
            $GLOBALS['_cartshift_test_queries'][] = ['query', $query];
            return 0;
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    }
}

if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
    $GLOBALS['wpdb'] = new wpdb();
}

// ──────────────────────────────────────────────
// CartShift autoloader
// ──────────────────────────────────────────────

spl_autoload_register(static function (string $class): void {
    $prefix = 'CartShift\\';
    $baseDir = dirname(__DIR__, 2) . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);

    // Skip test namespace — Composer handles that
    if (str_starts_with($relativeClass, 'Tests\\')) {
        return;
    }

    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
