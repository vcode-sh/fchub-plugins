<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class ExchangeRateRepository
{
    public function findLatest(string $baseCurrency, string $quoteCurrency): ?ExchangeRate
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_RATE_HISTORY;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE base_currency = %s AND quote_currency = %s ORDER BY fetched_at DESC LIMIT 1",
                strtoupper($baseCurrency),
                strtoupper($quoteCurrency),
            ),
            ARRAY_A,
        );

        if ($row === null) {
            return null;
        }

        return ExchangeRate::from($row);
    }

    /**
     * @return array<ExchangeRate>
     */
    public function findAllLatest(string $baseCurrency): array
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_RATE_HISTORY;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT rh.* FROM {$table} rh
                INNER JOIN (
                    SELECT quote_currency, MAX(fetched_at) as max_fetched
                    FROM {$table}
                    WHERE base_currency = %s
                    GROUP BY quote_currency
                ) latest ON rh.quote_currency = latest.quote_currency AND rh.fetched_at = latest.max_fetched
                WHERE rh.base_currency = %s",
                strtoupper($baseCurrency),
                strtoupper($baseCurrency),
            ),
            ARRAY_A,
        );

        return array_map(
            fn(array $row) => ExchangeRate::from($row),
            $results,
        );
    }

    public function insert(ExchangeRate $rate): void
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_RATE_HISTORY;

        $wpdb->insert($table, [
            'base_currency'  => $rate->baseCurrency,
            'quote_currency' => $rate->quoteCurrency,
            'rate'           => $rate->rate,
            'provider'       => $rate->provider->value,
            'fetched_at'     => $rate->fetchedAt,
        ]);
    }
}
