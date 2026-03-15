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

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush(): bool
    {
        return true;
    }
}

if (!function_exists('wp_convert_hr_to_bytes')) {
    function wp_convert_hr_to_bytes(string $value): int
    {
        $value = strtolower(trim($value));
        $bytes = (int) $value;

        if (str_contains($value, 'g')) {
            $bytes *= GB_IN_BYTES;
        } elseif (str_contains($value, 'm')) {
            $bytes *= MB_IN_BYTES;
        } elseif (str_contains($value, 'k')) {
            $bytes *= KB_IN_BYTES;
        }

        return $bytes;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return $GLOBALS['_cartshift_test_user_can'] ?? true;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args): void
    {
        $GLOBALS['_cartshift_test_rest_routes'][$namespace . $route] = $args;
    }
}

if (!function_exists('wc_get_product')) {
    function wc_get_product(int $productId): mixed
    {
        return $GLOBALS['_cartshift_test_wc_products'][$productId] ?? null;
    }
}

if (!function_exists('get_term_by')) {
    function get_term_by(string $field, string $value, string $taxonomy = ''): mixed
    {
        return $GLOBALS['_cartshift_test_terms'][$taxonomy][$value] ?? false;
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        return strtolower(preg_replace('/[^a-z0-9\-]/', '-', strtolower($title)));
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta(int $userId, string $key = '', bool $single = false): mixed
    {
        return $GLOBALS['_cartshift_test_user_meta'][$userId][$key] ?? '';
    }
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('KB_IN_BYTES')) {
    define('KB_IN_BYTES', 1024);
}

if (!defined('MB_IN_BYTES')) {
    define('MB_IN_BYTES', 1024 * KB_IN_BYTES);
}

if (!defined('GB_IN_BYTES')) {
    define('GB_IN_BYTES', 1024 * MB_IN_BYTES);
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
        protected string $slug = '';
        protected string $status = 'publish';
        protected string $price = '';
        protected string $regular_price = '';
        protected string $sale_price = '';
        protected string $description = '';
        protected string $short_description = '';
        protected string $sku = '';
        protected string $weight = '';
        protected string $length = '';
        protected string $width = '';
        protected string $height = '';
        protected array $downloads = [];
        protected string $type = 'simple';
        protected array $meta = [];
        protected bool $virtual = false;
        protected bool $downloadable = false;
        protected int $image_id = 0;
        protected array $gallery_image_ids = [];
        protected array $category_ids = [];
        protected bool $in_stock = true;
        protected bool $manage_stock = false;
        protected bool $sold_individually = false;
        protected array $children = [];
        protected ?int $stock_quantity = null;
        protected string $backorders = 'no';
        protected ?object $date_created = null;

        public function get_id(): int { return $this->id; }
        public function get_name(): string { return $this->name; }
        public function get_slug(): string { return $this->slug; }
        public function get_status(): string { return $this->status; }
        public function get_price(): string { return $this->price; }
        public function get_regular_price(): string { return $this->regular_price; }
        public function get_sale_price(): string { return $this->sale_price; }
        public function get_description(): string { return $this->description; }
        public function get_short_description(): string { return $this->short_description; }
        public function get_sku(): string { return $this->sku; }
        public function get_weight(): string { return $this->weight; }
        public function get_length(): string { return $this->length; }
        public function get_width(): string { return $this->width; }
        public function get_height(): string { return $this->height; }
        public function get_downloads(): array { return $this->downloads; }
        public function get_type(): string { return $this->type; }
        public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
        public function is_virtual(): bool { return $this->virtual; }
        public function is_downloadable(): bool { return $this->downloadable; }
        public function get_image_id(): int { return $this->image_id; }
        public function get_gallery_image_ids(): array { return $this->gallery_image_ids; }
        public function get_category_ids(): array { return $this->category_ids; }
        public function is_in_stock(): bool { return $this->in_stock; }
        public function get_manage_stock(): bool { return $this->manage_stock; }
        public function is_sold_individually(): bool { return $this->sold_individually; }
        public function get_children(): array { return $this->children; }
        public function get_stock_quantity(): ?int { return $this->stock_quantity; }
        public function get_backorders(): string { return $this->backorders; }
        public function get_date_created(): ?object { return $this->date_created; }
    }
}

if (!class_exists('WC_Product_Variation')) {
    class WC_Product_Variation extends WC_Product
    {
        protected array $attributes = [];

        public function get_attributes(): array { return $this->attributes; }
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order
    {
        protected int $id = 0;
        protected string $status = '';
        protected string $billing_email = '';
        protected string $total = '0';
        protected string $subtotal = '0';
        protected string $total_tax = '0';
        protected string $shipping_total = '0';
        protected string $shipping_tax = '0';
        protected string $discount_total = '0';
        protected string $total_refunded = '0';
        protected array $items = [];
        protected array $shipping_items = [];
        protected array $fee_items = [];
        protected string $billing_first_name = '';
        protected string $billing_last_name = '';
        protected string $billing_address_1 = '';
        protected string $billing_address_2 = '';
        protected string $billing_city = '';
        protected string $billing_state = '';
        protected string $billing_postcode = '';
        protected string $billing_country = '';
        protected string $billing_phone = '';
        protected string $billing_company = '';
        protected string $shipping_first_name = '';
        protected string $shipping_last_name = '';
        protected string $shipping_address_1 = '';
        protected string $shipping_address_2 = '';
        protected string $shipping_city = '';
        protected string $shipping_state = '';
        protected string $shipping_postcode = '';
        protected string $shipping_country = '';
        protected string $shipping_company = '';
        protected string $payment_method = '';
        protected string $payment_method_title = '';
        protected string $transaction_id = '';
        protected string $customer_note = '';
        protected string $customer_ip_address = '';
        protected ?object $date_created = null;
        protected ?object $date_paid = null;
        protected ?object $date_completed = null;
        protected int $customer_id = 0;
        protected int $parent_id = 0;
        protected bool $prices_include_tax = false;
        protected string $currency = 'USD';
        protected array $meta = [];

        public function get_id(): int { return $this->id; }
        public function get_status(): string { return $this->status; }
        public function get_billing_email(): string { return $this->billing_email; }
        public function get_total(): string { return $this->total; }
        public function get_subtotal(): string { return $this->subtotal; }
        public function get_total_tax(): string { return $this->total_tax; }
        public function get_shipping_total(): string { return $this->shipping_total; }
        public function get_shipping_tax(): string { return $this->shipping_tax; }
        public function get_discount_total(): string { return $this->discount_total; }
        public function get_total_refunded(): string { return $this->total_refunded; }
        public function get_items(string $type = ''): array
        {
            if ($type === 'shipping') {
                return $this->shipping_items;
            }
            if ($type === 'fee') {
                return $this->fee_items;
            }
            return $this->items;
        }
        public function get_billing_first_name(): string { return $this->billing_first_name; }
        public function get_billing_last_name(): string { return $this->billing_last_name; }
        public function get_billing_address_1(): string { return $this->billing_address_1; }
        public function get_billing_address_2(): string { return $this->billing_address_2; }
        public function get_billing_city(): string { return $this->billing_city; }
        public function get_billing_state(): string { return $this->billing_state; }
        public function get_billing_postcode(): string { return $this->billing_postcode; }
        public function get_billing_country(): string { return $this->billing_country; }
        public function get_billing_phone(): string { return $this->billing_phone; }
        public function get_billing_company(): string { return $this->billing_company; }
        public function get_shipping_first_name(): string { return $this->shipping_first_name; }
        public function get_shipping_last_name(): string { return $this->shipping_last_name; }
        public function get_shipping_address_1(): string { return $this->shipping_address_1; }
        public function get_shipping_address_2(): string { return $this->shipping_address_2; }
        public function get_shipping_city(): string { return $this->shipping_city; }
        public function get_shipping_state(): string { return $this->shipping_state; }
        public function get_shipping_postcode(): string { return $this->shipping_postcode; }
        public function get_shipping_country(): string { return $this->shipping_country; }
        public function get_shipping_company(): string { return $this->shipping_company; }
        public function get_payment_method(): string { return $this->payment_method; }
        public function get_payment_method_title(): string { return $this->payment_method_title; }
        public function get_transaction_id(): string { return $this->transaction_id; }
        public function get_customer_note(): string { return $this->customer_note; }
        public function get_customer_ip_address(): string { return $this->customer_ip_address; }
        public function get_date_created(): ?object { return $this->date_created; }
        public function get_date_paid(): ?object { return $this->date_paid; }
        public function get_date_completed(): ?object { return $this->date_completed; }
        public function get_customer_id(): int { return $this->customer_id; }
        public function get_parent_id(): int { return $this->parent_id; }
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
        protected ?object $date_created = null;
        protected int $usage_limit = 0;
        protected int $usage_limit_per_user = 0;
        protected int $usage_count = 0;
        protected array $product_ids = [];
        protected array $excluded_product_ids = [];
        protected array $product_categories = [];
        protected array $excluded_product_categories = [];
        protected array $email_restrictions = [];
        protected bool $individual_use = false;
        protected bool $exclude_sale_items = false;
        protected bool $free_shipping = false;
        protected string $description = '';
        protected float $minimum_amount = 0.0;
        protected float $maximum_amount = 0.0;
        protected array $meta = [];

        public function get_id(): int { return $this->id; }
        public function get_code(): string { return $this->code; }
        public function get_discount_type(): string { return $this->discount_type; }
        public function get_amount(): float { return $this->amount; }
        public function get_date_expires(): ?object { return $this->date_expires; }
        public function get_date_created(): ?object { return $this->date_created; }
        public function get_usage_limit(): int { return $this->usage_limit; }
        public function get_usage_limit_per_user(): int { return $this->usage_limit_per_user; }
        public function get_usage_count(): int { return $this->usage_count; }
        public function get_product_ids(): array { return $this->product_ids; }
        public function get_excluded_product_ids(): array { return $this->excluded_product_ids; }
        public function get_product_categories(): array { return $this->product_categories; }
        public function get_excluded_product_categories(): array { return $this->excluded_product_categories; }
        public function get_email_restrictions(): array { return $this->email_restrictions; }
        public function get_individual_use(): bool { return $this->individual_use; }
        public function get_exclude_sale_items(): bool { return $this->exclude_sale_items; }
        public function get_free_shipping(): bool { return $this->free_shipping; }
        public function get_description(): string { return $this->description; }
        public function get_minimum_amount(): float { return $this->minimum_amount; }
        public function get_maximum_amount(): float { return $this->maximum_amount; }
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
        protected ?\WC_Product $product = null;
        protected array $meta_data = [];

        public function get_product_id(): int { return $this->product_id; }
        public function get_variation_id(): int { return $this->variation_id; }
        public function get_quantity(): int { return $this->quantity; }
        public function get_total(): string { return $this->total; }
        public function get_subtotal(): string { return $this->subtotal; }
        public function get_total_tax(): string { return $this->total_tax; }
        public function get_name(): string { return $this->name; }
        public function get_product(): ?\WC_Product { return $this->product; }
        public function get_meta_data(): array { return $this->meta_data; }
    }
}

if (!class_exists('WC_Order_Item_Shipping')) {
    class WC_Order_Item_Shipping
    {
        protected string $method_title = '';
        protected string $total = '0';
        protected string $total_tax = '0';

        public function get_method_title(): string { return $this->method_title; }
        public function get_total(): string { return $this->total; }
        public function get_total_tax(): string { return $this->total_tax; }
    }
}

if (!class_exists('WC_Order_Item_Fee')) {
    class WC_Order_Item_Fee
    {
        protected string $name = '';
        protected string $total = '0';
        protected string $total_tax = '0';

        public function get_name(): string { return $this->name; }
        public function get_total(): string { return $this->total; }
        public function get_total_tax(): string { return $this->total_tax; }
    }
}

// ──────────────────────────────────────────────
// WordPress REST API stubs
// ──────────────────────────────────────────────

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
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
        public function __construct(
            private mixed $data = null,
            private int $status = 200,
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
    }
}

// ──────────────────────────────────────────────
// Global $wpdb stub
// ──────────────────────────────────────────────

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $terms = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public string $term_relationships = 'wp_term_relationships';
        public string $options = 'wp_options';
        public int $insert_id = 0;

        public function prepare(string $query, mixed ...$args): string
        {
            if (empty($args)) {
                return $query;
            }

            // Simple interpolation: replace %s and %d with actual values.
            $i = 0;
            return preg_replace_callback('/%[sd]/', function ($match) use ($args, &$i) {
                $value = $args[$i] ?? '';
                $i++;
                if ($match[0] === '%d') {
                    return (string) (int) $value;
                }
                return "'" . $value . "'";
            }, $query);
        }

        public function get_var(string $query): string|int|float|null
        {
            $GLOBALS['_cartshift_test_queries'][] = ['get_var', $query];

            // Allow tests to configure return values via a callback.
            if (isset($GLOBALS['_cartshift_test_get_var_callback'])) {
                return ($GLOBALS['_cartshift_test_get_var_callback'])($query);
            }

            return $GLOBALS['_cartshift_test_get_var_return'] ?? 0;
        }

        public function get_col(string $query): array
        {
            $GLOBALS['_cartshift_test_queries'][] = ['get_col', $query];
            return [];
        }

        public function get_results(string $query, string $output = OBJECT): array
        {
            $GLOBALS['_cartshift_test_queries'][] = ['get_results', $query, $output];

            if (isset($GLOBALS['_cartshift_test_get_results_callback'])) {
                return ($GLOBALS['_cartshift_test_get_results_callback'])($query, $output);
            }

            return $GLOBALS['_cartshift_test_get_results_return'] ?? [];
        }

        public function insert(string $table, array $data, ?array $format = null): int|false
        {
            $this->insert_id++;
            $GLOBALS['_cartshift_test_queries'][] = ['insert', $table, $data];
            return 1;
        }

        public function update(string $table, array $data, array $where, ?array $format = null, ?array $where_format = null): int|false
        {
            $GLOBALS['_cartshift_test_queries'][] = ['update', $table, $data, $where];
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
