<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Providers;

use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class EcbProvider implements ProviderContract
{
    private const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    public function fetchRates(string $baseCurrency): array
    {
        $response = wp_remote_get(self::ECB_URL, ['timeout' => 15]);

        if (is_wp_error($response)) {
            Logger::error('ECB request failed', [
                'error' => $response->get_error_message(),
            ]);
            return [];
        }

        $xml = simplexml_load_string(wp_remote_retrieve_body($response));

        if ($xml === false) {
            Logger::error('ECB XML parse failed');
            return [];
        }

        $rates = ['EUR' => '1.00000000'];

        foreach ($xml->Cube->Cube->Cube as $node) {
            $code = (string) $node['currency'];
            $rate = (string) $node['rate'];
            $rates[strtoupper($code)] = $rate;
        }

        // ECB always uses EUR as base — cross-rate if needed
        if (strtoupper($baseCurrency) !== 'EUR' && isset($rates[strtoupper($baseCurrency)])) {
            $baseRate = $rates[strtoupper($baseCurrency)];

            if ($baseRate === '' || $baseRate === '0' || bccomp($baseRate, '0', 8) === 0) {
                Logger::error('ECB base rate is zero — cannot rebase', [
                    'base_currency' => $baseCurrency,
                ]);
                return [];
            }

            $rebased = [];

            foreach ($rates as $code => $rate) {
                $rebased[$code] = bcdiv($rate, $baseRate, 8);
            }

            return $rebased;
        }

        return $rates;
    }

    public function name(): string
    {
        return 'ecb';
    }
}
