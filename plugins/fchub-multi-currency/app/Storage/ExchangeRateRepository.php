<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\Logger;

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
                "SELECT rh.*
                FROM {$table} rh
                WHERE rh.base_currency = %s
                  AND rh.id = (
                      SELECT rh2.id
                      FROM {$table} rh2
                      WHERE rh2.base_currency = rh.base_currency
                        AND rh2.quote_currency = rh.quote_currency
                      ORDER BY rh2.fetched_at DESC, rh2.id DESC
                      LIMIT 1
                  )",
                strtoupper($baseCurrency),
            ),
            ARRAY_A,
        );

        return array_map(
            fn(array $row) => ExchangeRate::from($row),
            $results,
        );
    }

    public function insert(ExchangeRate $rate): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_RATE_HISTORY;

        $result = $wpdb->insert($table, [
            'base_currency'  => $rate->baseCurrency,
            'quote_currency' => $rate->quoteCurrency,
            'rate'           => $rate->rate,
            'provider'       => $rate->provider->value,
            'fetched_at'     => $rate->fetchedAt,
        ]);

        if ($result === false) {
            Logger::error('Failed to insert exchange rate', [
                'currency'  => $rate->quoteCurrency,
                'db_error'  => $wpdb->last_error,
            ]);
            return false;
        }

        return true;
    }
}
