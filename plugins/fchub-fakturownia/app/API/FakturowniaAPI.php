<?php

namespace FChubFakturownia\API;

defined('ABSPATH') || exit;

class FakturowniaAPI
{
    private const JSON_RESPONSE_LIMIT = 2 * MB_IN_BYTES;
    private const PDF_RESPONSE_LIMIT = 20 * MB_IN_BYTES;
    private const ERROR_MESSAGE_LIMIT = 500;

    private string $domain;
    private string $apiToken;

    public function __construct(string $domain, string $apiToken)
    {
        $this->domain = rtrim($domain, '/');
        $this->apiToken = $apiToken;
    }

    /**
     * Test API connection by fetching account info
     */
    public function testConnection(): array
    {
        return $this->request('GET', '/invoices.json', [
            'api_token' => $this->apiToken,
            'page'      => 1,
            'per_page'  => 1,
        ]);
    }

    /**
     * Create an invoice
     */
    public function createInvoice(array $invoiceData): array
    {
        return $this->request('POST', '/invoices.json', [
            'api_token' => $this->apiToken,
            'invoice'   => $invoiceData,
        ]);
    }

    /**
     * Create an invoice and auto-send to KSeF
     */
    public function createInvoiceWithKSeF(array $invoiceData): array
    {
        return $this->request('POST', '/invoices.json', [
            'api_token'          => $this->apiToken,
            'gov_save_and_send'  => true,
            'invoice'            => $invoiceData,
        ]);
    }

    /**
     * Get invoice by ID
     */
    public function getInvoice(int $id): array
    {
        return $this->request('GET', '/invoices/' . $id . '.json', [
            'api_token' => $this->apiToken,
        ]);
    }

    /**
     * Download invoice PDF content (server-side, token never exposed to browser)
     */
    public function downloadInvoicePdf(int $id): array
    {
        if (!$this->isAllowedDomain()) {
            return $this->errorResponse(__('Invalid Fakturownia account domain.', 'fchub-fakturownia'));
        }

        $url = $this->getBaseUrl() . '/invoices/' . $id . '.pdf?api_token=' . rawurlencode($this->apiToken);

        $response = wp_remote_get($url, [
            'timeout'             => 30,
            'redirection'         => 0,
            'sslverify'           => true,
            'limit_response_size' => self::PDF_RESPONSE_LIMIT,
            'headers'             => [
                'Accept'     => 'application/pdf',
                'User-Agent' => $this->userAgent(),
            ],
        ]);

        if (is_wp_error($response)) {
            return $this->errorResponse($response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return $this->errorResponse(sprintf('PDF download failed (HTTP %d).', $statusCode), $statusCode);
        }

        $body = wp_remote_retrieve_body($response);
        $contentType = strtolower(trim((string) wp_remote_retrieve_header($response, 'content-type')));

        if (strlen($body) > self::PDF_RESPONSE_LIMIT) {
            return $this->errorResponse(__('The PDF response exceeded the allowed size.', 'fchub-fakturownia'));
        }

        if (!str_starts_with($contentType, 'application/pdf')) {
            return $this->errorResponse(__('The provider returned an invalid PDF content type.', 'fchub-fakturownia'));
        }

        if (!str_starts_with($body, '%PDF-')) {
            return $this->errorResponse(__('The provider returned invalid PDF data.', 'fchub-fakturownia'));
        }

        return [
            'body'         => $body,
            'content_type' => 'application/pdf',
        ];
    }

    /**
     * Create a correction invoice, optionally with explicit positions and KSeF submission
     */
    public function createCorrection(int $originalInvoiceId, string $reason, array $positions = [], bool $sendToKsef = false): array
    {
        $invoiceData = [
            'kind'              => 'correction',
            'invoice_id'        => (string) $originalInvoiceId,
            'correction_reason' => $reason,
        ];

        if (!empty($positions)) {
            $invoiceData['positions'] = $positions;
        }

        $params = [
            'api_token' => $this->apiToken,
            'invoice'   => $invoiceData,
        ];

        if ($sendToKsef) {
            $params['gov_save_and_send'] = true;
        }

        return $this->request('POST', '/invoices.json', $params);
    }

    /**
     * Find client by NIP (tax number)
     *
     * @internal Reserved for future B2B client matching.
     */
    public function findClientByTaxNo(string $nip): ?array
    {
        $result = $this->request('GET', '/clients.json', [
            'api_token' => $this->apiToken,
            'tax_no'    => $nip,
        ]);

        if (isset($result['error'])) {
            return null;
        }

        // API returns array of clients
        if (!empty($result) && isset($result[0])) {
            return $result[0];
        }

        return null;
    }

    /**
     * Create a client
     *
     * @internal Reserved for future B2B client matching.
     */
    public function createClient(array $clientData): array
    {
        return $this->request('POST', '/clients.json', [
            'api_token' => $this->apiToken,
            'client'    => $clientData,
        ]);
    }

    /**
     * Get base URL for the Fakturownia account.
     * Only allows Fakturownia domains to prevent SSRF.
     */
    public function getBaseUrl(): string
    {
        if (preg_match('/^[a-z0-9-]+$/i', $this->domain) === 1) {
            return 'https://' . $this->domain . '.fakturownia.pl';
        }

        if (preg_match('/^[a-z0-9-]+\.fakturownia\.pl$/i', $this->domain) === 1) {
            return 'https://' . $this->domain;
        }

        return 'https://invalid.fakturownia.pl';
    }

    /**
     * Make an HTTP request to Fakturownia API
     */
    private function request(string $method, string $endpoint, array $params = []): array
    {
        if (!$this->isAllowedDomain()) {
            return $this->errorResponse(__('Invalid Fakturownia account domain.', 'fchub-fakturownia'));
        }

        $url = $this->getBaseUrl() . $endpoint;

        $args = [
            'timeout'             => 30,
            'redirection'         => 0,
            'sslverify'           => true,
            'limit_response_size' => self::JSON_RESPONSE_LIMIT,
            'headers'             => [
                'Accept'     => 'application/json',
                'User-Agent' => $this->userAgent(),
            ],
        ];

        if ($method === 'GET') {
            $url = add_query_arg($params, $url);
            $response = wp_remote_get($url, $args);
        } else {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = wp_remote_post($url, $args);
        }

        if (is_wp_error($response)) {
            return $this->errorResponse($response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (strlen($body) > self::JSON_RESPONSE_LIMIT) {
            return $this->errorResponse(__('The provider response exceeded the allowed size.', 'fchub-fakturownia'));
        }

        $decoded = json_decode($body, true);

        if ($statusCode >= 400) {
            $errorMessage = __('Unknown error', 'fchub-fakturownia');

            if (is_array($decoded)) {
                if (isset($decoded['code']) && $decoded['code'] === 'error') {
                    $errorMessage = is_array($decoded['message'])
                        ? $this->formatValidationErrors($decoded['message'])
                        : $decoded['message'];
                } elseif (isset($decoded['error'])) {
                    $errorMessage = $decoded['error'];
                }
            }

            return $this->errorResponse((string) $errorMessage, $statusCode);
        }

        if ($body !== '' && !is_array($decoded)) {
            return $this->errorResponse(__('The provider returned an invalid JSON response.', 'fchub-fakturownia'));
        }

        return $decoded ?: [];
    }

    /**
     * Format validation errors from Fakturownia into a readable string
     */
    private function formatValidationErrors(array $errors): string
    {
        $messages = [];
        foreach ($errors as $field => $fieldErrors) {
            $fieldErrors = (array) $fieldErrors;
            foreach ($fieldErrors as $error) {
                $messages[] = $field . ': ' . $error;
            }
        }

        return implode('; ', $messages);
    }

    private function isAllowedDomain(): bool
    {
        return preg_match('/^[a-z0-9-]+(?:\.fakturownia\.pl)?$/i', $this->domain) === 1;
    }

    private function userAgent(): string
    {
        return 'FCHub Fakturownia/' . FCHUB_FAKTUROWNIA_VERSION;
    }

    private function errorResponse(string $message, int $code = 500): array
    {
        $message = str_replace($this->apiToken, '[redacted]', $message);
        $message = preg_replace('~https?://\S+~i', '[redacted-url]', $message) ?? $message;
        $message = sanitize_text_field($message);
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;
        $message = trim(substr($message, 0, self::ERROR_MESSAGE_LIMIT));

        if ($message === '') {
            $message = __('Fakturownia request failed.', 'fchub-fakturownia');
        }

        return [
            'error' => $message,
            'code'  => $code,
        ];
    }
}
