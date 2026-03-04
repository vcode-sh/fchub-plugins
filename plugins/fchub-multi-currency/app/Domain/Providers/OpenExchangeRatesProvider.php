<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Providers;

use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class OpenExchangeRatesProvider implements ProviderContract
{
    public function __construct(
        private string $appId,
    ) {
    }

    public function fetchRates(string $baseCurrency): array
    {
        $url = sprintf(
            'https://openexchangerates.org/api/latest.json?app_id=%s&base=%s',
            $this->appId,
            strtoupper($baseCurrency),
        );

        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            Logger::error('OpenExchangeRates request failed', [
                'error' => $response->get_error_message(),
            ]);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || !isset($body['rates'])) {
            Logger::error('OpenExchangeRates invalid response', [
                'body' => $body,
            ]);
            return [];
        }

        $rates = [];

        foreach ($body['rates'] as $code => $rate) {
            $rates[strtoupper($code)] = (string) $rate;
        }

        return $rates;
    }

    public function name(): string
    {
        return 'open_exchange_rates';
    }
}
