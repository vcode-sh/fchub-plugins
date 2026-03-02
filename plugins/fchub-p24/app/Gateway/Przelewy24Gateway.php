<?php

namespace FChubP24\Gateway;

defined('ABSPATH') || exit;

use FChubP24\API\Przelewy24API;
use FChubP24\Subscription\Przelewy24RenewalHandler;
use FChubP24\Subscription\Przelewy24SubscriptionModule;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\Framework\Support\Arr;

class Przelewy24Gateway extends AbstractPaymentGateway
{
    public array $supportedFeatures = ['payment', 'refund', 'webhook'];

    public function __construct()
    {
        $settings = new Przelewy24Settings();
        $enableRecurring = $settings->get('enable_recurring') === 'yes';
        parent::__construct($settings, $enableRecurring ? new Przelewy24SubscriptionModule() : null);
    }

    public function boot()
    {
        // No additional boot hooks needed - IPN is handled via FluentCart's listener
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'fchub-p24-checkout',
                'src'    => FCHUB_P24_URL . 'assets/przelewy24-checkout.js',
            ],
        ];
    }

    public function getEnqueueVersion()
    {
        $assetPath = FCHUB_P24_PATH . 'assets/przelewy24-checkout.js';
        $assetVersion = file_exists($assetPath) ? (string) filemtime($assetPath) : '0';

        return FCHUB_P24_VERSION . '.' . $assetVersion;
    }

    public function meta(): array
    {
        return [
            'title'       => __('Przelewy24', 'fchub-p24'),
            'route'       => 'przelewy24',
            'slug'        => 'przelewy24',
            'description' => esc_html__('Pay via Przelewy24 - online transfers, BLIK, cards and more', 'fchub-p24'),
            'logo'        => FCHUB_P24_URL . 'assets/przelewy24-logo.svg',
            'icon'        => FCHUB_P24_URL . 'assets/przelewy24-icon.svg',
            'brand_color' => '#d13239',
            'upcoming'    => false,
            'status'      => $this->settings->get('is_active') === 'yes',
        ];
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        try {
            $isSubscription = !empty($paymentInstance->subscription);
            $redirectUrl = (new Przelewy24Handler($this))->handlePayment($paymentInstance, $isSubscription);

            return [
                'status'      => 'success',
                'message'     => __('Redirecting to Przelewy24...', 'fchub-p24'),
                'redirect_to' => $redirectUrl,
            ];
        } catch (\Exception $e) {
            return [
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function handleIPN(): void
    {
        // Validate HTTP method - P24 sends POST notifications
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json(['error' => 'Method not allowed'], 405);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input) || empty($input['sessionId'])) {
            wp_send_json(['error' => 'Invalid notification'], 400);
            return;
        }

        if (!empty($input['refundsUuid'])) {
            $this->handleRefundNotification($input);
            return;
        }

        $requiredKeys = ['merchantId', 'posId', 'sessionId', 'amount', 'originAmount',
                         'currency', 'orderId', 'methodId', 'statement', 'sign'];
        foreach ($requiredKeys as $key) {
            if (!isset($input[$key])) {
                wp_send_json(['error' => 'Missing field: ' . $key], 400);
                return;
            }
        }

        $api = new Przelewy24API($this->settings);

        // Verify notification signature
        if (!$api->verifyNotificationSign($input)) {
            fluent_cart_error_log('P24 IPN Error', 'Invalid notification signature', [
                'module_name' => 'Order',
            ]);
            wp_send_json(['error' => 'Invalid signature'], 400);
            return;
        }

        $sessionId = sanitize_text_field($input['sessionId']);
        $orderId = (int) $input['orderId'];
        // P24 sends amount in grosz (lowest currency unit), same as FluentCart stores
        $amount = (int) $input['amount'];
        $currency = sanitize_text_field($input['currency']);

        // Find transaction by session ID (which is transaction UUID)
        $transaction = OrderTransaction::query()
            ->where('uuid', $sessionId)
            ->first();

        // Fallback: FluentCart may regenerate transaction UUID on checkout retry.
        // Look up by p24_session_id stored in order config.
        if (!$transaction) {
            $transaction = $this->findTransactionBySessionId($sessionId);
        }

        if (!$transaction) {
            // Check if this is a renewal IPN
            $renewalHandled = $this->handleRenewalIPN($input, $sessionId, $orderId, $amount, $currency, $api);
            if ($renewalHandled !== null) {
                return;
            }

            fluent_cart_error_log('P24 IPN Error', 'Transaction not found: ' . $sessionId, [
                'module_name' => 'Order',
            ]);
            wp_send_json(['error' => 'Transaction not found'], 404);
            return;
        }

        // Idempotency check - if already succeeded, return OK without re-processing
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json(['status' => 'OK'], 200);
            return;
        }

        // Verify amount matches what was originally registered
        $expectedAmount = (int) $transaction->total;
        if ($amount !== $expectedAmount) {
            fluent_cart_error_log('P24 IPN Error', sprintf(
                'Amount mismatch: expected %d, received %d for session %s',
                $expectedAmount,
                $amount,
                $sessionId
            ), [
                'module_id'   => $transaction->order_id,
                'module_name' => 'Order',
            ]);
            wp_send_json(['error' => 'Amount mismatch'], 400);
            return;
        }

        // Verify currency matches what was originally registered
        $expectedCurrency = strtoupper($transaction->currency ?: 'PLN');
        if ($currency !== $expectedCurrency) {
            fluent_cart_error_log('P24 IPN Error', sprintf(
                'Currency mismatch: expected %s, received %s for session %s',
                $expectedCurrency,
                $currency,
                $sessionId
            ), [
                'module_id'   => $transaction->order_id,
                'module_name' => 'Order',
            ]);
            wp_send_json(['error' => 'Currency mismatch'], 400);
            return;
        }

        // Verify the transaction with P24
        $verifyResponse = $api->verifyTransaction([
            'sessionId' => $sessionId,
            'orderId'   => $orderId,
            'amount'    => $amount,
            'currency'  => $currency,
        ]);

        if (isset($verifyResponse['error']) || ($verifyResponse['data']['status'] ?? '') !== 'success') {
            fluent_cart_error_log('P24 Verification Error', json_encode($verifyResponse), [
                'module_id'   => $transaction->order_id,
                'module_name' => 'Order',
            ]);
            wp_send_json(['error' => 'Verification failed'], 400);
            return;
        }

        // Verification successful - update order
        $order = $transaction->order;

        if (!$order) {
            wp_send_json(['error' => 'Order not found'], 404);
            return;
        }

        // Store P24 orderId in vendor_charge_id (used for refunds and panel links)
        $transactionData = [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id' => (string) $orderId,
            'total'            => $amount,
        ];

        $this->updateOrderDataByOrder($order, $transactionData, $transaction);

        // For subscription initial payments, fetch card info and schedule first renewal
        $this->maybeStoreCardInfoAndScheduleRenewal($transaction, $orderId);

        wp_send_json(['status' => 'OK'], 200);
    }

    private function handleRefundNotification(array $input): void
    {
        $requiredKeys = ['orderId', 'sessionId', 'refundsUuid', 'merchantId', 'amount', 'currency', 'status', 'sign'];
        foreach ($requiredKeys as $key) {
            if (!isset($input[$key])) {
                wp_send_json(['error' => 'Missing field: ' . $key], 400);
                return;
            }
        }

        $api = new Przelewy24API($this->settings);

        if (!$api->verifyRefundNotificationSign($input)) {
            fluent_cart_error_log('P24 Refund IPN Error', 'Invalid refund notification signature', [
                'module_name' => 'Order',
            ]);
            wp_send_json(['error' => 'Invalid signature'], 400);
            return;
        }

        $sessionId = sanitize_text_field($input['sessionId']);
        $status = (int) $input['status']; // 0 = completed, 1 = rejected

        $transaction = OrderTransaction::query()
            ->where('uuid', $sessionId)
            ->first();

        // Fallback: FluentCart may regenerate transaction UUID on checkout retry
        if (!$transaction) {
            $transaction = $this->findTransactionBySessionId($sessionId);
        }

        if (!$transaction) {
            wp_send_json(['error' => 'Transaction not found'], 404);
            return;
        }

        if ($status === 0) {
            // Refund completed — record using FluentCart's Refund service
            Refund::createOrRecordRefund([
                'vendor_charge_id' => sanitize_text_field($input['refundsUuid']),
                'payment_method'   => 'przelewy24',
                'total'            => (int) $input['amount'],
            ], $transaction);
        }

        if ($status === 1) {
            // Refund was rejected by P24
            fluent_cart_error_log('P24 Refund Rejected', json_encode($input), [
                'module_id'   => $transaction->order_id,
                'module_name' => 'Order',
            ]);
        }

        // Log the refund notification regardless of status
        fluent_cart_error_log('P24 Refund Notification', json_encode([
            'status'       => $status === 0 ? 'completed' : 'rejected',
            'amount'       => $input['amount'],
            'refundsUuid'  => $input['refundsUuid'],
        ]), [
            'module_id'   => $transaction->order_id,
            'module_name' => 'Order',
        ]);

        wp_send_json(['status' => 'OK'], 200);
    }

    public function processRefund($transaction, $amount, $args)
    {
        if ($amount <= 0) {
            return new \WP_Error(
                'fchub_p24_refund_error',
                __('Refund amount is required.', 'fchub-p24')
            );
        }

        if (empty($transaction->vendor_charge_id)) {
            return new \WP_Error(
                'fchub_p24_refund_error',
                __('Payment has not been confirmed yet. Cannot refund.', 'fchub-p24')
            );
        }

        $api = new Przelewy24API($this->settings);

        // vendor_charge_id contains P24's orderId (set during IPN after successful payment)
        $orderId = (int) $transaction->vendor_charge_id;
        $sessionId = $transaction->uuid;
        $requestId = 'refund_' . $transaction->id . '_' . time();
        $refundsUuid = substr(str_replace('-', '', wp_generate_uuid4()), 0, 35);

        $paymentHelper = new PaymentHelper('przelewy24');
        $listenerUrl = $paymentHelper->listenerUrl();

        $response = $api->refund([
            'requestId'   => $requestId,
            'refundsUuid' => $refundsUuid,
            'urlStatus'   => $listenerUrl['listener_url'],
            'refunds'     => [
                [
                    'orderId'     => $orderId,
                    'sessionId'   => $sessionId,
                    'amount'      => (int) $amount,
                    'description' => mb_substr(Arr::get($args, 'reason', __('Refund', 'fchub-p24')), 0, 35),
                ],
            ],
        ]);

        if (isset($response['error'])) {
            $errorMsg = $response['error'];
            if (is_array($errorMsg)) {
                // 409 Conflict returns error as array of per-refund objects
                $first = $errorMsg[0] ?? [];
                $errorMsg = $first['message'] ?? __('Refund rejected by Przelewy24', 'fchub-p24');
            }
            return new \WP_Error(
                'fchub_p24_refund_error',
                $errorMsg
            );
        }

        return [
            'status'  => 'success',
            'message' => __('Refund request submitted to Przelewy24', 'fchub-p24'),
        ];
    }

    public function fields(): array
    {
        $webhookUrl = (new PaymentHelper('przelewy24'))->listenerUrl();

        return [
            'notice'       => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode', 'fchub-p24'),
                'type'  => 'notice',
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'fchub-p24'),
                        'value'  => 'live',
                        'schema' => [
                            'live_merchant_id' => [
                                'value'   => '',
                                'label'   => __('Merchant ID', 'fchub-p24'),
                                'type'    => 'text',
                            ],
                            'live_shop_id' => [
                                'value'   => '',
                                'label'   => __('Shop ID / POS ID', 'fchub-p24'),
                                'type'    => 'text',
                                'tooltip' => __('Leave empty if same as Merchant ID', 'fchub-p24'),
                            ],
                            'live_crc_key' => [
                                'value' => '',
                                'label' => __('CRC Key', 'fchub-p24'),
                                'type'  => 'password',
                            ],
                            'live_api_key' => [
                                'value'   => '',
                                'label'   => __('API Key', 'fchub-p24'),
                                'type'    => 'password',
                                'tooltip' => __('Also known as "Report Key" in P24 panel', 'fchub-p24'),
                            ],
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials (Sandbox)', 'fchub-p24'),
                        'value'  => 'test',
                        'schema' => [
                            'test_merchant_id' => [
                                'value' => '',
                                'label' => __('Merchant ID', 'fchub-p24'),
                                'type'  => 'text',
                            ],
                            'test_shop_id' => [
                                'value'   => '',
                                'label'   => __('Shop ID / POS ID', 'fchub-p24'),
                                'type'    => 'text',
                                'tooltip' => __('Leave empty if same as Merchant ID', 'fchub-p24'),
                            ],
                            'test_crc_key' => [
                                'value' => '',
                                'label' => __('CRC Key', 'fchub-p24'),
                                'type'  => 'password',
                            ],
                            'test_api_key' => [
                                'value'   => '',
                                'label'   => __('API Key', 'fchub-p24'),
                                'type'    => 'password',
                                'tooltip' => __('Also known as "Report Key" in P24 panel', 'fchub-p24'),
                            ],
                        ],
                    ],
                ],
            ],
            'time_limit' => [
                'value'   => '15',
                'label'   => __('Payment time limit (minutes)', 'fchub-p24'),
                'type'    => 'select',
                'options' => [
                    ['label' => __('No limit', 'fchub-p24'), 'value' => '0'],
                    ['label' => '5', 'value' => '5'],
                    ['label' => '10', 'value' => '10'],
                    ['label' => '15', 'value' => '15'],
                    ['label' => '30', 'value' => '30'],
                    ['label' => '60', 'value' => '60'],
                ],
                'tooltip' => __('How long the customer has to complete payment. 0 = no limit.', 'fchub-p24'),
            ],
            'channel_cards' => [
                'value'   => 'yes',
                'label'   => __('Credit/debit cards', 'fchub-p24'),
                'type'    => 'checkbox',
                'tooltip' => __('Visa, Mastercard, ApplePay, GooglePay', 'fchub-p24'),
            ],
            'channel_transfers' => [
                'value'   => 'yes',
                'label'   => __('Online transfers', 'fchub-p24'),
                'type'    => 'checkbox',
            ],
            'channel_blik' => [
                'value'   => 'yes',
                'label'   => __('BLIK', 'fchub-p24'),
                'type'    => 'checkbox',
            ],
            'channel_wallets' => [
                'value'   => 'yes',
                'label'   => __('Wallets', 'fchub-p24'),
                'type'    => 'checkbox',
                'tooltip' => __('PayPal, SkyCash, etc.', 'fchub-p24'),
            ],
            'channel_traditional' => [
                'value'   => 'no',
                'label'   => __('Traditional transfer', 'fchub-p24'),
                'type'    => 'checkbox',
            ],
            'channel_installments' => [
                'value'   => 'no',
                'label'   => __('Installments', 'fchub-p24'),
                'type'    => 'checkbox',
                'tooltip' => __('Requires separate agreement with Przelewy24', 'fchub-p24'),
            ],
            'channel_24_7' => [
                'value'   => 'no',
                'label'   => __('24/7 payments only', 'fchub-p24'),
                'type'    => 'checkbox',
                'tooltip' => __('Show only payment methods available 24/7', 'fchub-p24'),
            ],
            'enable_recurring' => [
                'value'   => 'yes',
                'label'   => __('Card recurring payments', 'fchub-p24'),
                'type'    => 'select',
                'options' => [
                    ['label' => __('Yes', 'fchub-p24'), 'value' => 'yes'],
                    ['label' => __('No', 'fchub-p24'), 'value' => 'no'],
                ],
                'tips'    => __('Enable subscription/recurring billing via card-on-file. Requires a separate agreement with Przelewy24 for card recurring transactions.', 'fchub-p24'),
            ],
            'webhook_desc' => [
                'value' => wp_kses(sprintf(
                    '<div class="pt-4"><p><strong>%s</strong></p><p><code>%s</code></p><p>%s</p></div>',
                    __('Webhook URL (urlStatus):', 'fchub-p24'),
                    esc_url($webhookUrl['listener_url'] ?? ''),
                    __('This URL will be sent automatically with each transaction registration. No manual configuration needed in the P24 panel.', 'fchub-p24')
                ), [
                    'div'    => ['class' => true],
                    'p'      => [],
                    'strong' => [],
                    'code'   => [],
                ]),
                'label' => __('Webhook URL', 'fchub-p24'),
                'type'  => 'html_attr',
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        $merchantId = Arr::get($data, $mode . '_merchant_id');
        $crcKey = Arr::get($data, $mode . '_crc_key');
        $apiKey = Arr::get($data, $mode . '_api_key');

        if (empty($merchantId) || empty($crcKey) || empty($apiKey)) {
            return [
                'status'  => 'failed',
                'message' => __('Merchant ID, CRC Key and API Key are required.', 'fchub-p24'),
            ];
        }

        // Test the API connection
        $settings = new Przelewy24Settings();
        $settings->settings = array_merge($settings->settings, $data);

        $api = new Przelewy24API($settings);
        $response = $api->testAccess();

        if (isset($response['error'])) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    __('Connection failed: %s', 'fchub-p24'),
                    $response['error']
                ),
            ];
        }

        // Invalidate cached payment methods on successful settings save
        delete_transient('fchub_p24_methods_pl_test');
        delete_transient('fchub_p24_methods_en_test');
        delete_transient('fchub_p24_methods_pl_live');
        delete_transient('fchub_p24_methods_en_live');

        return [
            'status'  => 'success',
            'message' => __('Przelewy24 connection verified!', 'fchub-p24'),
        ];
    }

    public function getTransactionUrl($url, $data): string
    {
        $transaction = Arr::get($data, 'transaction');
        if (!$transaction || !$transaction->vendor_charge_id) {
            return $url;
        }

        $baseUrl = $this->settings->getMode() === 'test'
            ? 'https://sandbox.przelewy24.pl'
            : 'https://panel.przelewy24.pl';

        return $baseUrl . '/panel/transakcja-' . $transaction->vendor_charge_id;
    }

    public function getLocalizeData(): array
    {
        return [
            'fchub_p24_i18n' => [
                'loading'           => __('Loading payment methods...', 'fchub-p24'),
                'no_methods'        => __('No payment methods available.', 'fchub-p24'),
                'error_loading'     => __('Error loading payment methods.', 'fchub-p24'),
                'place_order'       => __('Place Order', 'fchub-p24'),
                'pay'               => __('Pay', 'fchub-p24'),
                'group_blik'        => __('BLIK', 'fchub-p24'),
                'group_fast'        => __('Fast transfers', 'fchub-p24'),
                'group_wallets'     => __('Wallets', 'fchub-p24'),
                'group_etransfer'   => __('e-Transfers', 'fchub-p24'),
                'group_traditional' => __('Traditional transfer', 'fchub-p24'),
                'group_cards'       => __('Cards', 'fchub-p24'),
                'subscription_cards_only' => __('Subscription requires card payment.', 'fchub-p24'),
            ],
        ];
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function getOrderInfo(array $data)
    {
        $lang = substr(get_locale(), 0, 2) === 'pl' ? 'pl' : 'en';
        $cacheKey = 'fchub_p24_methods_' . $lang . '_' . $this->settings->getMode();
        $cachedMethods = get_transient($cacheKey);

        if ($cachedMethods !== false) {
            $rawMethods = $cachedMethods;
        } else {
            $api = new Przelewy24API($this->settings);
            $response = $api->getPaymentMethods($lang);
            $rawMethods = !empty($response['data']) && is_array($response['data']) ? $response['data'] : [];

            if (!empty($rawMethods)) {
                set_transient($cacheKey, $rawMethods, HOUR_IN_SECONDS);
            }
        }

        // Map P24 group names to our channel settings
        $groupToChannel = [
            'Credit Card'        => 'channel_cards',
            'FastTransfers'      => 'channel_transfers',
            'eTransfer'          => 'channel_transfers',
            'TraditionalTransfer' => 'channel_traditional',
            'Blik'               => 'channel_blik',
            'Wallet'             => 'channel_wallets',
            'Installments'       => 'channel_installments',
        ];

        $methods = [];
        foreach ($rawMethods as $method) {
            if (empty($method['status'])) {
                continue;
            }

            // Filter by enabled channels
            $group = $method['group'] ?? '';
            $channelKey = $groupToChannel[$group] ?? null;
            if ($channelKey && $this->settings->get($channelKey) !== 'yes') {
                continue;
            }

            $methods[] = [
                'id'     => $method['id'],
                'name'   => $method['name'],
                'group'  => $group,
                'imgUrl' => $method['imgUrl'],
            ];
        }

        wp_send_json([
            'status'       => 'success',
            'data'         => [],
            'payment_args' => [
                'methods' => $methods,
            ],
        ], 200);
    }

    /**
     * Find transaction by p24_session_id stored in order config.
     * Handles the case where FluentCart regenerated the transaction UUID on checkout retry.
     */
    private function findTransactionBySessionId(string $sessionId): ?OrderTransaction
    {
        $orders = \FluentCart\App\Models\Order::query()
            ->where('payment_method', 'przelewy24')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();

        foreach ($orders as $order) {
            $config = $order->config;
            if (is_array($config) && isset($config['p24_session_id']) && $config['p24_session_id'] === $sessionId) {
                return OrderTransaction::query()
                    ->where('order_id', $order->id)
                    ->latest()
                    ->first();
            }
        }

        return null;
    }

    /**
     * Handle IPN for a renewal card charge.
     * Returns true if handled (response sent), null if not a renewal IPN.
     */
    private function handleRenewalIPN(array $input, string $sessionId, int $orderId, int $amount, string $currency, Przelewy24API $api): ?bool
    {
        // Look for a subscription with this pending renewal session
        $subscriptions = Subscription::query()
            ->get();

        $targetSubscription = null;
        foreach ($subscriptions as $sub) {
            if ($sub->getMeta('_p24_pending_renewal_session') === $sessionId) {
                $targetSubscription = $sub;
                break;
            }
        }

        if (!$targetSubscription) {
            return null; // Not a renewal IPN
        }

        // Verify amount matches recurring total
        $expectedAmount = (int) $targetSubscription->recurring_total;
        if ($amount !== $expectedAmount) {
            fluent_cart_error_log('P24 Renewal IPN Error', sprintf(
                'Amount mismatch: expected %d, received %d for subscription #%d',
                $expectedAmount, $amount, $targetSubscription->id
            ), [
                'module_id'   => $targetSubscription->id,
                'module_name' => 'Subscription',
            ]);
            wp_send_json(['error' => 'Amount mismatch'], 400);
            return true;
        }

        // Verify the transaction with P24
        $verifyResponse = $api->verifyTransaction([
            'sessionId' => $sessionId,
            'orderId'   => $orderId,
            'amount'    => $amount,
            'currency'  => $currency,
        ]);

        if (isset($verifyResponse['error']) || ($verifyResponse['data']['status'] ?? '') !== 'success') {
            fluent_cart_error_log('P24 Renewal Verification Error', json_encode($verifyResponse), [
                'module_id'   => $targetSubscription->id,
                'module_name' => 'Subscription',
            ]);
            wp_send_json(['error' => 'Verification failed'], 400);
            return true;
        }

        // Clear the pending session marker
        $targetSubscription->updateMeta('_p24_pending_renewal_session', '');

        // Record the renewal payment via FluentCart's service
        $transactionData = [
            'subscription_id'  => $targetSubscription->id,
            'vendor_charge_id' => (string) $orderId,
            'total'            => $amount,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'meta'             => [
                'p24_order_id'  => $orderId,
                'p24_session_id' => $sessionId,
            ],
        ];

        $result = SubscriptionService::recordRenewalPayment($transactionData, $targetSubscription);

        if (is_wp_error($result)) {
            fluent_cart_error_log('P24 Renewal Record Error', $result->get_error_message(), [
                'module_id'   => $targetSubscription->id,
                'module_name' => 'Subscription',
            ]);
            wp_send_json(['error' => 'Failed to record renewal'], 500);
            return true;
        }

        // Schedule next renewal
        Przelewy24RenewalHandler::scheduleNextRenewal($targetSubscription);

        fluent_cart_error_log('P24 Renewal Success', sprintf(
            'Subscription #%d renewed, P24 order: %d, amount: %d %s',
            $targetSubscription->id, $orderId, $amount, $currency
        ), [
            'module_id'   => $targetSubscription->id,
            'module_name' => 'Subscription',
        ]);

        wp_send_json(['status' => 'OK'], 200);
        return true;
    }

    /**
     * After successful initial payment IPN, fetch card info and schedule first renewal.
     */
    private function maybeStoreCardInfoAndScheduleRenewal(OrderTransaction $transaction, int $p24OrderId): void
    {
        $subscriptionId = $transaction->subscription_id ?? null;
        if (!$subscriptionId) {
            return;
        }

        $subscription = Subscription::find($subscriptionId);
        if (!$subscription) {
            return;
        }

        // Idempotency - skip if card info already stored
        if ($subscription->getMeta('_p24_card_ref_id')) {
            return;
        }

        $api = new Przelewy24API($this->settings);
        $cardInfo = $api->getCardInfo($p24OrderId);

        if (isset($cardInfo['error']) || empty($cardInfo['data'])) {
            fluent_cart_error_log('P24 Card Info Error', json_encode($cardInfo), [
                'module_id'   => $subscriptionId,
                'module_name' => 'Subscription',
            ]);
            return;
        }

        $cardData = $cardInfo['data'];
        $refId = $cardData['refId'] ?? null;

        if (!$refId) {
            fluent_cart_error_log('P24 Card Info Error', 'No refId in card info response', [
                'module_id'   => $subscriptionId,
                'module_name' => 'Subscription',
            ]);
            return;
        }

        // Store card details on subscription meta
        $subscription->updateMeta('_p24_card_ref_id', $refId);
        $subscription->updateMeta('_p24_card_trace_order_id', $p24OrderId);

        if (!empty($cardData['mask'])) {
            $subscription->updateMeta('_p24_card_mask', $cardData['mask']);
        }
        if (!empty($cardData['cardType'])) {
            $subscription->updateMeta('_p24_card_type', $cardData['cardType']);
        }
        if (!empty($cardData['cardDate'])) {
            $subscription->updateMeta('_p24_card_expiry', $cardData['cardDate']);
        }

        // Set vendor_subscription_id for FluentCart's reference
        $subscription->vendor_subscription_id = 'p24_sub_' . $subscription->id;
        $subscription->save();

        // Schedule first renewal
        Przelewy24RenewalHandler::scheduleNextRenewal($subscription);
    }
}
