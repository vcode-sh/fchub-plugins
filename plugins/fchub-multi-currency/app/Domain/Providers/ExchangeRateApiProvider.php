<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Providers;

use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class ExchangeRateApiProvider implements ProviderContract
{
    public function __construct(
        private string $apiKey,
    ) {
    }

    public function fetchRates(string $baseCurrency): array
    {
        $url = sprintf(
            'https://v6.exchangerate-api.com/v6/%s/latest/%s',
            $this->apiKey,
            strtoupper($baseCurrency),
        );

        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            Logger::error('ExchangeRate-API request failed', [
                'error' => $response->get_error_message(),
            ]);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || ($body['result'] ?? '') !== 'success') {
            Logger::error('ExchangeRate-API invalid response', [
                'body' => $body,
            ]);
            return [];
        }

        $rates = [];

        foreach ($body['conversion_rates'] ?? [] as $code => $rate) {
            $rates[strtoupper($code)] = (string) $rate;
        }

        return $rates;
    }

    public function name(): string
    {
        return 'exchange_rate_api';
    }
}
