<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Providers;

use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class EcbProvider implements ProviderContract
{
    private const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    private const MAX_RESPONSE_BYTES = 1_048_576;

    public function fetchRates(string $baseCurrency): array
    {
        $response = wp_remote_get(self::ECB_URL, [
            'timeout' => 15,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
        ]);

        if (is_wp_error($response)) {
            Logger::error('ECB request failed', [
                'error' => $response->get_error_message(),
            ]);
            return [];
        }

        $responseBody = wp_remote_retrieve_body($response);
        if (
            wp_remote_retrieve_response_code($response) !== 200
            || strlen($responseBody) > self::MAX_RESPONSE_BYTES
        ) {
            Logger::error('ECB returned an invalid HTTP response');
            return [];
        }

        $previousLibxmlErrorMode = libxml_use_internal_errors(true);
        $xml = simplexml_load_string(
            $responseBody,
            \SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_NOCDATA,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlErrorMode);

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

            $isZeroBase = function_exists('bccomp')
                ? (bccomp($baseRate, '0', 8) === 0)
                : ((float) $baseRate === 0.0);

            if ($baseRate === '' || $baseRate === '0' || $isZeroBase) {
                Logger::error('ECB base rate is zero — cannot rebase', [
                    'base_currency' => $baseCurrency,
                ]);
                return [];
            }

            $rebased = [];

            foreach ($rates as $code => $rate) {
                $rebased[$code] = function_exists('bcdiv')
                    ? bcdiv($rate, $baseRate, 8)
                    : number_format(((float) $rate / (float) $baseRate), 8, '.', '');
            }

            return $rebased;
        } elseif (strtoupper($baseCurrency) !== 'EUR') {
            // Base currency not available from ECB — can't rebase
            Logger::error('ECB does not provide rate for base currency', [
                'base_currency' => strtoupper($baseCurrency),
            ]);
            return [];
        }

        return $rates;
    }

    public function name(): string
    {
        return 'ecb';
    }
}
