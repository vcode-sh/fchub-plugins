<?php

namespace FChubFakturownia\API;

defined('ABSPATH') || exit;

class FakturowniaAPI
{
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
     * Send existing invoice to KSeF
     */
    public function sendToKSeF(int $id): array
    {
        return $this->request('GET', '/invoices/' . $id . '.json', [
            'api_token'     => $this->apiToken,
            'send_to_ksef'  => 'yes',
        ]);
    }

    /**
     * Download invoice PDF content (server-side, token never exposed to browser)
     */
    public function downloadInvoicePdf(int $id): array
    {
        $url = $this->getBaseUrl() . '/invoices/' . $id . '.pdf?api_token=' . urlencode($this->apiToken);

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/pdf'],
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode >= 400) {
            return ['error' => sprintf('PDF download failed (HTTP %d)', $statusCode)];
        }

        return [
            'body'         => wp_remote_retrieve_body($response),
            'content_type' => wp_remote_retrieve_header($response, 'content-type') ?: 'application/pdf',
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
        if (is_array($result) && !empty($result) && isset($result[0])) {
            return $result[0];
        }

        return null;
    }

    /**
     * Create a client
     */
    public function createClient(array $clientData): array
    {
        return $this->request('POST', '/clients.json', [
            'api_token' => $this->apiToken,
            'client'    => $clientData,
        ]);
    }

    /**
     * Get base URL for the Fakturownia account
     */
    public function getBaseUrl(): string
    {
        // Support full domain or just subdomain
        if (strpos($this->domain, '.') !== false) {
            return 'https://' . $this->domain;
        }

        return 'https://' . $this->domain . '.fakturownia.pl';
    }

    /**
     * Make an HTTP request to Fakturownia API
     */
    private function request(string $method, string $endpoint, array $params = []): array
    {
        $url = $this->getBaseUrl() . $endpoint;

        $args = [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        if ($method === 'GET') {
            $url = add_query_arg($params, $url);
            $response = wp_remote_get($url, $args);
        } else {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = wp_remote_post($url, $args);
        }

        if (is_wp_error($response)) {
            return [
                'error' => $response->get_error_message(),
                'code'  => 500,
            ];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
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

            return [
                'error' => $errorMessage,
                'code'  => $statusCode,
            ];
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
}
