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

        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }

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
        $url = $this->settings->getBaseUrl() . $endpoint;
        $shopId = $this->settings->getShopId();
        $apiKey = $this->settings->getApiKey();

        $args = [
            'method'  => $method,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($shopId . ':' . $apiKey),
            ],
            'timeout' => 30,
        ];

        if (!empty($body)) {
            $args['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'error' => $response->get_error_message(),
                'code'  => 500,
            ];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $responseBody = json_decode($rawBody, true);

        if ($statusCode >= 400) {
            return [
                'error' => $responseBody['error'] ?? __('Unknown error', 'fchub-p24'),
                'code'  => $statusCode,
            ];
        }

        if ($responseBody === null) {
            return [
                'error' => __('Invalid response from Przelewy24', 'fchub-p24'),
                'code'  => $statusCode,
            ];
        }

        return $responseBody ?: [];
    }
}
