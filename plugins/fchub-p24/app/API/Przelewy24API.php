<?php

namespace FChubP24\API;

defined('ABSPATH') || exit;

use FChubP24\Gateway\Przelewy24Settings;

class Przelewy24API
{
    private Przelewy24Settings $settings;

    public function __construct(Przelewy24Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Test API connection
     */
    public function testAccess(): array
    {
        return $this->request('GET', '/api/v1/testAccess');
    }

    /**
     * Register a transaction with P24
     *
     * @param array $params Transaction parameters
     * @return array Response with token on success
     */
    public function registerTransaction(array $params): array
    {
        $merchantId = (int) $this->settings->getMerchantId();
        $shopId = (int) $this->settings->getShopId();
        $crcKey = $this->settings->getCrcKey();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- P24 signs this exact JSON representation.
        $signData = json_encode([
            'sessionId'  => $params['sessionId'],
            'merchantId' => $merchantId,
            'amount'     => $params['amount'],
            'currency'   => $params['currency'],
            'crc'        => $crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $body = array_merge($params, [
            'merchantId' => $merchantId,
            'posId'      => $shopId,
            'sign'       => hash('sha384', $signData),
        ]);

        return $this->request('POST', '/api/v1/transaction/register', $body);
    }

    /**
     * Verify a completed transaction
     *
     * @param array $params Verification parameters
     * @return array Response
     */
    public function verifyTransaction(array $params): array
    {
        $crcKey = $this->settings->getCrcKey();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- P24 signs this exact JSON representation.
        $signData = json_encode([
            'sessionId' => $params['sessionId'],
            'orderId'   => $params['orderId'],
            'amount'    => $params['amount'],
            'currency'  => $params['currency'],
            'crc'       => $crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $body = array_merge($params, [
            'merchantId' => (int) $this->settings->getMerchantId(),
            'posId'      => (int) $this->settings->getShopId(),
            'sign'       => hash('sha384', $signData),
        ]);

        return $this->request('PUT', '/api/v1/transaction/verify', $body);
    }

    /**
     * Get available payment methods
     *
     * @param string $lang Language code (pl, en)
     * @param int $amount Amount in grosz
     * @param string $currency Currency code
     * @return array List of payment methods
     */
    public function getPaymentMethods(string $lang = 'pl', int $amount = 0, string $currency = 'PLN'): array
    {
        $endpoint = '/api/v1/payment/methods/' . $lang;
        $params = [];
        if ($amount > 0) {
            $params['amount'] = $amount;
        }
        $params['currency'] = $currency;
        $endpoint .= '?' . http_build_query($params);

        return $this->request('GET', $endpoint);
    }

    /**
     * Get card info (refId) after a successful card payment
     *
     * @param int $orderId P24 order ID from IPN
     * @return array Card data including refId, mask, cardType, cardDate
     */
    public function getCardInfo(int $orderId): array
    {
        return $this->request('GET', '/api/v1/card/info/' . $orderId);
    }

    /**
     * Charge a stored card using a previously obtained token
     *
     * @param string $token Token from transaction registration with methodRefId
     * @return array Response (async - IPN will follow)
     */
    public function chargeCard(string $token): array
    {
        return $this->request('POST', '/api/v1/card/charge', ['token' => $token]);
    }

    /**
     * Request a refund
     *
     * @param array $params Refund parameters
     * @return array Response
     */
    public function refund(array $params): array
    {
        return $this->request('POST', '/api/v1/transaction/refund', $params);
    }

    /**
     * Verify refund notification sign
     *
     * @param array $notification Refund notification data from P24
     * @return bool Whether the sign is valid
     */
    public function verifyRefundNotificationSign(array $notification): bool
    {
        $crcKey = $this->settings->getCrcKey();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- P24 signs this exact JSON representation.
        $signData = json_encode([
            'orderId'     => (int) $notification['orderId'],
            'sessionId'   => $notification['sessionId'],
            'refundsUuid' => $notification['refundsUuid'],
            'merchantId'  => (int) $notification['merchantId'],
            'amount'      => (int) $notification['amount'],
            'currency'    => $notification['currency'],
            'status'      => (int) $notification['status'],
            'crc'         => $crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $expectedSign = hash('sha384', $signData);

        return hash_equals($expectedSign, $notification['sign']);
    }

    /**
     * Verify notification sign
     *
     * @param array $notification Notification data from P24
     * @return bool Whether the sign is valid
     */
    public function verifyNotificationSign(array $notification): bool
    {
        $crcKey = $this->settings->getCrcKey();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- P24 signs this exact JSON representation.
        $signData = json_encode([
            'merchantId'   => (int) $notification['merchantId'],
            'posId'        => (int) $notification['posId'],
            'sessionId'    => $notification['sessionId'],
            'amount'       => (int) $notification['amount'],
            'originAmount' => (int) $notification['originAmount'],
            'currency'     => $notification['currency'],
            'orderId'      => (int) $notification['orderId'],
            'methodId'     => (int) $notification['methodId'],
            'statement'    => $notification['statement'],
            'crc'          => $crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $expectedSign = hash('sha384', $signData);

        return hash_equals($expectedSign, $notification['sign']);
    }

    /**
     * Make an HTTP request to the P24 API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $body Request body
     * @return array Response data or error
     */
    private function request(string $method, string $endpoint, array $body = []): array
    {
        if (!$this->settings->hasCompleteCredentials()) {
            return [
                'error' => __('Complete Przelewy24 credentials are required.', 'fchub-p24'),
                'code' => 400,
            ];
        }

        $url = $this->settings->getBaseUrl() . $endpoint;
        if (!$this->isAllowedUrl($url)) {
            return [
                'error' => __('Invalid Przelewy24 API endpoint.', 'fchub-p24'),
                'code' => 400,
            ];
        }

        $shopId = $this->settings->getShopId();
        $apiKey = $this->settings->getApiKey();

        $args = [
            'method'  => $method,
            'headers' => [
                'Content-Type'  => 'application/json',
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication requires Base64.
                'Authorization' => 'Basic ' . base64_encode($shopId . ':' . $apiKey),
            ],
            'timeout' => 30,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 2 * 1024 * 1024,
        ];

        if (!empty($body)) {
            try {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON_THROW_ON_ERROR is required here.
                $args['body'] = json_encode(
                    $body,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $exception) {
                return [
                    'error' => __('Unable to encode the Przelewy24 request.', 'fchub-p24'),
                    'code' => 400,
                ];
            }
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'error' => __('Przelewy24 request failed.', 'fchub-p24'),
                'code' => 502,
            ];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        try {
            $responseBody = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [
                'error' => __('Invalid response from Przelewy24.', 'fchub-p24'),
                'code' => $statusCode,
            ];
        }

        if (!is_array($responseBody) || array_is_list($responseBody)) {
            return [
                'error' => __('Invalid response from Przelewy24.', 'fchub-p24'),
                'code' => $statusCode,
            ];
        }

        if ($statusCode >= 400) {
            return [
                'error' => $this->redactError($responseBody['error'] ?? __('Unknown error', 'fchub-p24')),
                'code' => $statusCode,
            ];
        }

        return $responseBody;
    }

    private function isAllowedUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $allowedHosts = ['sandbox.przelewy24.pl', 'secure.przelewy24.pl'];

        return ($parts['scheme'] ?? '') === 'https'
            && in_array($parts['host'] ?? '', $allowedHosts, true)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['port']);
    }

    /**
     * @param mixed $error
     * @return string|array
     */
    private function redactError($error)
    {
        if (is_array($error)) {
            $safeError = [];
            foreach ($error as $key => $value) {
                $safeError[$key] = $this->redactError($value);
            }

            return $safeError;
        }

        $message = sanitize_text_field(wp_strip_all_tags((string) $error));
        foreach (array_filter([$this->settings->getApiKey(), $this->settings->getCrcKey()]) as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        $message = preg_replace('/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $message);

        return mb_substr((string) $message, 0, 200);
    }
}
