<?php

namespace FChubP24\Gateway;

defined('ABSPATH') || exit;

use FChubP24\API\Przelewy24API;
use FluentCart\App\Models\Cart;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;

class Przelewy24Handler
{
    private Przelewy24Gateway $gateway;
    private Przelewy24API $api;

    public function __construct(Przelewy24Gateway $gateway)
    {
        $this->gateway = $gateway;
        $this->api = new Przelewy24API($gateway->getSettings());
    }

    private const P24_SUPPORTED_LANGUAGES = ['bg', 'cs', 'de', 'en', 'es', 'fr', 'hr', 'hu', 'it', 'nl', 'pl', 'pt', 'se', 'sk', 'ro'];

    /**
     * Process payment - register transaction with P24 and return redirect URL
     *
     * @param bool $isSubscription When true, forces card-only channel for recurring tokenization
     */
    public function handlePayment(PaymentInstance $paymentInstance, bool $isSubscription = false): string
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $settings = $this->gateway->getSettings();

        if (!$settings->isActive()) {
            throw new \Exception(esc_html__('Przelewy24 payment is not activated', 'fchub-p24'));
        }

        if (!$order->id) {
            throw new \Exception(esc_html__('Order not found!', 'fchub-p24'));
        }

        $order->payment_method_title = $this->gateway->getMeta('title');
        $order->save();

        $paymentHelper = new PaymentHelper('przelewy24');
        $listenerUrl = $paymentHelper->listenerUrl();
        $successUrl = $this->buildReturnUrl($transaction->uuid);

        $sessionId = $transaction->uuid;
        // FluentCart stores amounts in lowest currency unit (grosz), same as P24 expects
        $amount = (int) $transaction->total;
        $currency = strtoupper($transaction->currency ?: 'PLN');
        $payerEmail = $this->resolvePayerEmail($order);

        if (!$payerEmail) {
            throw new \Exception(
                esc_html__('Customer email is required to process Przelewy24 payment.', 'fchub-p24')
            );
        }

        $params = [
            'sessionId'   => $sessionId,
            'amount'      => $amount,
            'currency'    => $currency,
            'description' => $this->buildDescription($order),
            'email'       => mb_substr($payerEmail, 0, 50),
            'country'     => $this->resolveCountry($order->billing_address),
            'language'    => $this->resolveLanguage(),
            'urlReturn'   => $successUrl,
            'urlStatus'   => $listenerUrl['listener_url'],
            'timeLimit'   => (int) ($settings->get('time_limit') ?: 15),
            'transferLabel' => $this->buildTransferLabel($order),
            'encoding'    => 'UTF-8',
            'waitForResult' => true,
            'regulationAccept' => false,
            'channel'       => $isSubscription ? 1 : $settings->getChannel(),
        ];

        $params = array_merge($params, $this->buildCustomerParams($order));

        $psuData = $this->buildPsuData();
        if (!empty($psuData)) {
            $params['additional'] = ['PSU' => $psuData];
        }

        // Add selected payment method (bank, BLIK, etc.) — not applicable for subscription initial payments
        if (!$isSubscription) {
            $selectedMethod = isset($_POST['fchub_p24_selected_method'])
                ? (int) sanitize_text_field($_POST['fchub_p24_selected_method'])
                : 0;

            if ($selectedMethod > 0) {
                $params['method'] = $selectedMethod;
            }
        }

        // `cart` payload is optional in P24 register API and can be rejected by some accounts.
        // Keep it opt-in to avoid blocking checkout with "Invalid cart number".
        $includeCart = (bool) apply_filters('fchub_p24_enable_cart_payload', false, $order, $transaction);
        if ($includeCart) {
            $cartItems = $this->buildCartItems($order);
            if (!empty($cartItems)) {
                $params['cart'] = $cartItems;
            }
        }

        $response = $this->api->registerTransaction($params);

        if (isset($response['error'])) {
            fluent_cart_error_log('P24 Registration Error', json_encode($response), [
                'module_id'   => $order->id,
                'module_name' => 'Order',
            ]);
            throw new \Exception(
                esc_html__('Payment registration failed. Please try again.', 'fchub-p24')
            );
        }

        $token = $response['data']['token'] ?? null;

        if (!$token) {
            throw new \Exception(
                esc_html__('Failed to obtain payment token from Przelewy24.', 'fchub-p24')
            );
        }

        // Store P24 token and sessionId in transaction meta
        $meta = $transaction->meta ?: [];
        $meta['p24_token'] = $token;
        $meta['p24_session_id'] = $sessionId;
        $transaction->meta = $meta;
        $transaction->save();

        // Also store sessionId in order meta for receipt page lookup
        // (FluentCart may regenerate the transaction UUID on checkout retry)
        $orderMeta = $order->config ?: [];
        $orderMeta['p24_session_id'] = $sessionId;
        $order->config = $orderMeta;
        $order->save();

        // Mark cart as completed
        $relatedCart = Cart::query()
            ->where('order_id', $order->id)
            ->where('stage', '!=', 'completed')
            ->first();

        if ($relatedCart) {
            $relatedCart->stage = 'completed';
            $relatedCart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
            $relatedCart->save();
        }

        // Return redirect URL to P24 payment page
        return $settings->getBaseUrl() . '/trnRequest/' . $token;
    }

    /**
     * Build cart items array for P24 transaction registration
     */
    private function buildCartItems($order): array
    {
        $items = $order->order_items;

        if (!$items || $items->isEmpty()) {
            return [];
        }

        $cartItems = [];

        foreach ($items as $item) {
            $name = trim(($item->post_title ?? '') . ' ' . ($item->title ?? ''));
            if (empty($name)) {
                $name = __('Item', 'fchub-p24');
            }

            $quantity = max(1, (int) $item->quantity);
            $unitPrice = (int) round((int) $item->line_total / $quantity);

            $cartItems[] = [
                'sellerId'       => '0',
                'sellerCategory' => 'default',
                'name'           => mb_substr($name, 0, 127),
                'description'    => mb_substr($name, 0, 127),
                'quantity'       => $quantity,
                'price'          => $unitPrice,
                'number'         => (string) $item->id,
            ];
        }

        return $cartItems;
    }

    /**
     * Build return URL without the trailing slash issue from FluentCart's getPageLink().
     */
    private function buildReturnUrl(string $trxHash): string
    {
        $receiptPageId = (new \FluentCart\Api\StoreSettings())->getReceiptPageId();

        if (!$receiptPageId) {
            $baseUrl = site_url();
        } else {
            $baseUrl = get_permalink($receiptPageId);
            if (!$baseUrl) {
                $baseUrl = site_url();
            }
        }

        // Remove trailing slash before adding query args to avoid page_id=78%2F
        $baseUrl = rtrim($baseUrl, '/');

        return add_query_arg([
            'method'       => 'przelewy24',
            'trx_hash'     => $trxHash,
            'fct_redirect' => 'yes',
        ], $baseUrl);
    }

    private function buildDescription(object $order): string
    {
        $ref = $order->invoice_no ?: $order->id;
        $desc = sprintf(__('Order #%s', 'fchub-p24'), $ref);

        $items = $order->order_items ?? null;
        if ($items && !$items->isEmpty()) {
            $parts = [];
            foreach ($items as $item) {
                $name = trim(($item->post_title ?? '') . ' ' . ($item->title ?? ''));
                if (empty($name)) {
                    $name = __('Item', 'fchub-p24');
                }
                $qty = max(1, (int) $item->quantity);
                $parts[] = $name . ' x' . $qty;
            }
            $desc .= ' | ' . implode(', ', $parts);
        }

        return mb_substr($desc, 0, 1024);
    }

    private function buildTransferLabel(object $order): string
    {
        $ref = $order->invoice_no ?: $order->id;
        $label = 'Zam ' . $ref;
        $label = preg_replace('/[^a-zA-Z0-9ęółśążźćńĘÓŁŚĄŻŹĆŃ .\/:\-]/u', '', $label);

        return mb_substr($label, 0, 20);
    }

    private function buildCustomerParams(object $order): array
    {
        $billing = $order->billing_address ?? null;
        if (!$billing) {
            return [];
        }

        $params = [];

        $firstName = trim($billing->first_name ?? '');
        $lastName = trim($billing->last_name ?? '');
        $name = trim($firstName . ' ' . $lastName);
        if (empty($name) && $order->customer) {
            $name = trim($order->customer->full_name ?? '');
        }
        if (!empty($name)) {
            $params['client'] = mb_substr($name, 0, 40);
        }

        $address = trim(($billing->address_line_1 ?? '') . ' ' . ($billing->address_line_2 ?? ''));
        if (!empty($address)) {
            $params['address'] = mb_substr($address, 0, 80);
        }

        $zip = trim($billing->zip ?? '');
        if (!empty($zip)) {
            $params['zip'] = mb_substr($zip, 0, 10);
        }

        $city = trim($billing->city ?? '');
        if (!empty($city)) {
            $params['city'] = mb_substr($city, 0, 50);
        }

        $phone = preg_replace('/\D/', '', $billing->phone ?? '');
        if (!empty($phone)) {
            $params['phone'] = substr($phone, 0, 12);
        }

        return $params;
    }

    private function buildPsuData(): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Use X-Forwarded-For only when REMOTE_ADDR is a known proxy/private IP
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!empty($forwarded)) {
            // Take the rightmost IP (last proxy-appended entry, not client-controlled)
            $parts = array_map('trim', explode(',', $forwarded));
            $ip = end($parts);
        }

        if (empty($ip)) {
            return [];
        }

        $psu = ['IP' => $ip];

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!empty($ua)) {
            $psu['userAgent'] = mb_substr($ua, 0, 255);
        }

        return $psu;
    }

    private function resolveCountry($billingAddress): string
    {
        if ($billingAddress && !empty($billingAddress->country)) {
            return strtoupper(substr($billingAddress->country, 0, 2));
        }

        return 'PL';
    }

    private function resolveLanguage(): string
    {
        $lang = substr(get_locale(), 0, 2);

        if (in_array($lang, self::P24_SUPPORTED_LANGUAGES, true)) {
            return $lang;
        }

        return 'pl';
    }

    private function resolvePayerEmail($order): string
    {
        $candidates = [
            $order->customer ? $order->customer->email : '',
            Arr::get($order->billing_address ? $order->billing_address->meta : [], 'other_data.email', ''),
            Arr::get($order->shipping_address ? $order->shipping_address->meta : [], 'other_data.email', ''),
        ];

        foreach ($candidates as $candidate) {
            $email = sanitize_email((string) $candidate);
            if ($email && is_email($email)) {
                return $email;
            }
        }

        return '';
    }
}
