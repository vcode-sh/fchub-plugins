<?php

/**
 * PHPUnit bootstrap - mocks WordPress functions for standalone unit testing
 */

// Autoload
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Mock WordPress functions used by the plugin
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var((string) $email, FILTER_SANITIZE_EMAIL);
    }
}

if (!function_exists('is_email')) {
    function is_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html) {
        return $string;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
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

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show) {
        return $show === 'name' ? 'Test Store' : '';
    }
}

if (!function_exists('get_locale')) {
    function get_locale() {
        return 'pl_PL';
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key) {
        global $_wp_transients;
        return $_wp_transients[$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) {
        global $_wp_transients;
        $_wp_transients[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        global $_wp_transients;
        unset($_wp_transients[$key]);
        return true;
    }
}

if (!function_exists('wp_send_json')) {
    function wp_send_json($data, $status = 200) {
        // In tests, throw an exception to capture the response
        throw new \FChubP24\Tests\WpSendJsonException($data, $status);
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = []) {
        global $_fchub_test_wp_remote_request;
        if (isset($_fchub_test_wp_remote_request)) {
            return is_callable($_fchub_test_wp_remote_request)
                ? ($_fchub_test_wp_remote_request)($url, $args)
                : $_fchub_test_wp_remote_request;
        }
        return new \WP_Error('not_mocked', 'wp_remote_request not mocked');
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

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('http_build_query')) {
    // Already exists in PHP
}

if (!function_exists('fluent_cart_error_log')) {
    function fluent_cart_error_log($title, $content, $extra = []) {
        // No-op in tests
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return 'http://localhost/wp-content/plugins/fchub-p24/';
    }
}

// Mock WP_Error class
if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $code;
        protected $message;
        protected $data;

        public function __construct($code = '', $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_code() {
            return $this->code;
        }
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

// Mock FluentCart classes needed by Przelewy24Settings
if (!class_exists('FluentCart\\Api\\StoreSettings')) {
    // phpcs:ignore
    class FluentCart_Api_StoreSettings {
        public function get($key) {
            return 'test';
        }
    }
    class_alias('FluentCart_Api_StoreSettings', 'FluentCart\\Api\\StoreSettings');
}

if (!class_exists('FluentCart\\App\\Modules\\PaymentMethods\\Core\\BaseGatewaySettings')) {
    // phpcs:ignore
    abstract class FluentCart_BaseGatewaySettings {
        public $settings;
        public $methodHandler;

        public function __construct() {
            $this->settings = wp_parse_args([], static::getDefaults());
        }

        abstract public function get($key = '');
        abstract public function getMode();
        abstract public function isActive();
        abstract public static function getDefaults(): array;
    }
    class_alias('FluentCart_BaseGatewaySettings', 'FluentCart\\App\\Modules\\PaymentMethods\\Core\\BaseGatewaySettings');
}

if (!class_exists('FluentCart\\App\\Helpers\\Status')) {
    class FluentCart_Status {
        const TRANSACTION_SUCCEEDED = 'succeeded';
        const TRANSACTION_PENDING = 'pending';
        const TRANSACTION_FAILED = 'failed';
    }
    class_alias('FluentCart_Status', 'FluentCart\\App\\Helpers\\Status');
}

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

// Mock FluentCart Refund service
if (!class_exists('FluentCart\\App\\Services\\Payments\\Refund')) {
    class FluentCart_Refund {
        public static function createOrRecordRefund($refundData, $parentTransaction) {
            global $_fchub_test_refund_calls;
            $_fchub_test_refund_calls[] = [
                'refundData' => $refundData,
                'parentTransaction' => $parentTransaction,
            ];
            return (object) ['id' => 999, 'status' => 'refunded'];
        }
    }
    class_alias('FluentCart_Refund', 'FluentCart\\App\\Services\\Payments\\Refund');
}

// Mock OrderTransaction model with minimal query builder
if (!class_exists('FluentCart\\App\\Models\\OrderTransaction')) {
    class FluentCart_OrderTransaction {
        public static $mockResult = null;
        public $uuid, $id, $order_id, $status, $total, $currency, $meta, $vendor_charge_id, $subscription_id, $order;
        public static function query() { return new FluentCart_OTQueryBuilder(); }
    }
    class FluentCart_OTQueryBuilder {
        public function where($col, $val) { return $this; }
        public function first() { return FluentCart_OrderTransaction::$mockResult; }
        public function latest() { return $this; }
    }
    class_alias('FluentCart_OrderTransaction', 'FluentCart\\App\\Models\\OrderTransaction');
}

// Mock Order model (needed by findTransactionBySessionId)
if (!class_exists('FluentCart\\App\\Models\\Order')) {
    class FluentCart_Order {
        public static $mockResults = [];
        public $id, $config, $payment_method;
        public static function query() { return new FluentCart_OrderQueryBuilder(); }
    }
    class FluentCart_OrderQueryBuilder {
        public function where($col, $val) { return $this; }
        public function orderBy($col, $dir) { return $this; }
        public function limit($n) { return $this; }
        public function get() { return FluentCart_Order::$mockResults; }
    }
    class_alias('FluentCart_Order', 'FluentCart\\App\\Models\\Order');
}

// Mock AbstractPaymentGateway (base class for Gateway)
if (!class_exists('FluentCart\\App\\Modules\\PaymentMethods\\Core\\AbstractPaymentGateway')) {
    abstract class FluentCart_AbstractPaymentGateway {
        public $settings;
        public $subscriptions;
        public array $supportedFeatures = [];
        public function __construct($settings, $subscriptions = null) {
            $this->settings = $settings;
            if ($subscriptions) {
                $this->supportedFeatures[] = 'subscriptions';
            }
            $this->subscriptions = $subscriptions;
        }
        public function getMeta($key) { return $this->meta()[$key] ?? ''; }
        public function getSettings() { return $this->settings; }
        abstract public function meta(): array;
        public function updateOrderDataByOrder($order, $data, $transaction) {}
        protected function init() {}
    }
    class_alias('FluentCart_AbstractPaymentGateway', 'FluentCart\\App\\Modules\\PaymentMethods\\Core\\AbstractPaymentGateway');
}

// Mock PaymentHelper
if (!class_exists('FluentCart\\App\\Services\\Payments\\PaymentHelper')) {
    class FluentCart_PaymentHelper {
        public function __construct($method = '') {}
        public function listenerUrl() { return ['listener_url' => 'http://localhost/listener']; }
    }
    class_alias('FluentCart_PaymentHelper', 'FluentCart\\App\\Services\\Payments\\PaymentHelper');
}

// Mock PaymentInstance
if (!class_exists('FluentCart\\App\\Services\\Payments\\PaymentInstance')) {
    class FluentCart_PaymentInstance {}
    class_alias('FluentCart_PaymentInstance', 'FluentCart\\App\\Services\\Payments\\PaymentInstance');
}

// Mock site_url
if (!function_exists('site_url')) {
    function site_url($path = '') {
        return 'http://localhost' . ($path ? '/' . ltrim($path, '/') : '');
    }
}

// Mock current_time
if (!function_exists('current_time')) {
    function current_time($type) {
        return $type === 'mysql' ? date('Y-m-d H:i:s') : time();
    }
}

// Mock Action Scheduler functions
if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = [], $group = '') {
        global $_fchub_test_as_actions;
        $_fchub_test_as_actions[] = [
            'type'      => 'schedule',
            'timestamp' => $timestamp,
            'hook'      => $hook,
            'args'      => $args,
            'group'     => $group,
        ];
        return 1;
    }
}

if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions($hook, $args = [], $group = '') {
        global $_fchub_test_as_actions;
        $_fchub_test_as_actions[] = [
            'type'  => 'unschedule',
            'hook'  => $hook,
            'args'  => $args,
            'group' => $group,
        ];
    }
}

// Mock AbstractSubscriptionModule
if (!class_exists('FluentCart\\App\\Modules\\PaymentMethods\\Core\\AbstractSubscriptionModule')) {
    abstract class FluentCart_AbstractSubscriptionModule {
        public function cancel($vendorSubscriptionId, $args = []) { return new \WP_Error('not_implemented', 'Not implemented'); }
        public function cancelSubscription($data, $order, $subscription) { throw new \Exception('Not implemented', 404); }
        public function pauseSubscription($data, $order, $subscription) { throw new \Exception('Not implemented', 404); }
        public function resumeSubscription($data, $order, $subscription) { throw new \Exception('Not implemented', 404); }
        public function cardUpdate($data, $subscriptionId) { throw new \Exception('Not implemented', 404); }
        public function cancelAutoRenew($subscription) {}
        public function cancelOnPlanChange($vendorSubscriptionId, $parentOrderId, $subscriptionId, $reason) {}
        public function reSyncSubscriptionFromRemote($subscriptionModel) { return new \WP_Error('not_implemented', 'Not implemented'); }
    }
    class_alias('FluentCart_AbstractSubscriptionModule', 'FluentCart\\App\\Modules\\PaymentMethods\\Core\\AbstractSubscriptionModule');
}

// Mock Subscription model
if (!class_exists('FluentCart\\App\\Models\\Subscription')) {
    class FluentCart_Subscription {
        public static $mockResult = null;
        public static $mockResults = [];
        public $id, $status, $recurring_total, $currency, $vendor_subscription_id;
        public $next_billing_date, $order;
        // Configurable return values for tests (null = use default behavior)
        public $_testRequiredBillTimes = null;
        public $_testNextBillingDate = null;
        private $_meta = [];
        private $_saved = false;
        private $_saveCount = 0;

        public static function find($id) {
            if (static::$mockResult && static::$mockResult->id == $id) {
                return static::$mockResult;
            }
            return null;
        }
        public static function query() { return new FluentCart_SubscriptionQueryBuilder(); }
        public function getMeta($key, $default = null) { return $this->_meta[$key] ?? $default; }
        public function updateMeta($key, $value) { $this->_meta[$key] = $value; }
        public function setMeta(array $meta) { $this->_meta = $meta; }
        public function getAllMeta() { return $this->_meta; }
        public function save() { $this->_saved = true; $this->_saveCount++; }
        public function wasSaved() { return $this->_saved; }
        public function getSaveCount() { return $this->_saveCount; }
        public function getRequiredBillTimes() {
            return $this->_testRequiredBillTimes !== null ? $this->_testRequiredBillTimes : 1;
        }
        public function guessNextBillingDate($forced = false) {
            if ($this->_testNextBillingDate !== null) {
                return $this->_testNextBillingDate;
            }
            return date('Y-m-d H:i:s', strtotime('+30 days'));
        }
    }
    class FluentCart_SubscriptionQueryBuilder {
        public function where($col, $val) { return $this; }
        public function get() { return FluentCart_Subscription::$mockResults; }
        public function first() { return FluentCart_Subscription::$mockResult; }
    }
    class_alias('FluentCart_Subscription', 'FluentCart\\App\\Models\\Subscription');
}

// Mock SubscriptionService
if (!class_exists('FluentCart\\App\\Modules\\Subscriptions\\Services\\SubscriptionService')) {
    class FluentCart_SubscriptionService {
        public static $lastRenewalData = null;
        public static $allRenewalCalls = [];
        public static $shouldFail = false;
        public static function recordRenewalPayment($transactionData, $subscriptionModel = null, $subscriptionUpdateArgs = []) {
            static::$lastRenewalData = $transactionData;
            static::$allRenewalCalls[] = [
                'transactionData' => $transactionData,
                'subscriptionModel' => $subscriptionModel,
                'subscriptionUpdateArgs' => $subscriptionUpdateArgs,
            ];
            if (static::$shouldFail) {
                return new \WP_Error('renewal_failed', 'Renewal recording failed');
            }
            return (object) ['id' => 999, 'status' => 'succeeded'];
        }
        public static function reset() {
            static::$lastRenewalData = null;
            static::$allRenewalCalls = [];
            static::$shouldFail = false;
        }
    }
    class_alias('FluentCart_SubscriptionService', 'FluentCart\\App\\Modules\\Subscriptions\\Services\\SubscriptionService');
}

// HOUR_IN_SECONDS constant (WordPress defines this)
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// Define plugin constants
if (!defined('FCHUB_P24_VERSION')) {
    define('FCHUB_P24_VERSION', '1.0.0-test');
}
if (!defined('FCHUB_P24_PATH')) {
    define('FCHUB_P24_PATH', dirname(__DIR__) . '/');
}
if (!defined('FCHUB_P24_URL')) {
    define('FCHUB_P24_URL', 'http://localhost/wp-content/plugins/fchub-p24/');
}
