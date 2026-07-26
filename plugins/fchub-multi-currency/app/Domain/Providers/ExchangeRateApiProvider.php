<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Providers;

use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class ExchangeRateApiProvider implements ProviderContract
{
    private const MAX_RESPONSE_BYTES = 1_048_576;

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

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
        ]);

        if (is_wp_error($response)) {
            Logger::error('ExchangeRate-API request failed', [
                'error' => $response->get_error_message(),
            ]);
            return [];
        }

        $responseBody = wp_remote_retrieve_body($response);
        if (
            wp_remote_retrieve_response_code($response) !== 200
            || strlen($responseBody) > self::MAX_RESPONSE_BYTES
        ) {
            Logger::error('ExchangeRate-API returned an invalid HTTP response');
            return [];
        }

        $body = json_decode($responseBody, true);

        if (
            !is_array($body)
            || ($body['result'] ?? '') !== 'success'
            || !is_array($body['conversion_rates'] ?? null)
        ) {
            Logger::error('ExchangeRate-API returned an invalid response');
            return [];
        }

        $rates = [];

        foreach ($body['conversion_rates'] as $code => $rate) {
            $rates[strtoupper($code)] = (string) $rate;
        }

        if (!self::hasPositiveBaseRate($rates, $baseCurrency)) {
            Logger::error('ExchangeRate-API response omitted a valid base rate', [
                'base_currency' => strtoupper($baseCurrency),
            ]);
            return [];
        }

        return $rates;
    }

    public function name(): string
    {
        return 'exchange_rate_api';
    }

    /**
     * @param array<string, string> $rates
     */
    private static function hasPositiveBaseRate(array $rates, string $baseCurrency): bool
    {
        $baseRate = $rates[strtoupper($baseCurrency)] ?? null;

        return $baseRate !== null
            && is_numeric($baseRate)
            && (float) $baseRate > 0.0;
    }
}
