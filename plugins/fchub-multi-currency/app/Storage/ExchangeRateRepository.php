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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This authoritative fallback must see the latest persisted rate; service-level projection caching occurs above the repository.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE base_currency = %s AND quote_currency = %s ORDER BY fetched_at DESC LIMIT 1",
                $table,
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Refresh, diagnostics, and public-rate consumers require the current persisted rate set.
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT rh.*
                FROM %i rh
                WHERE rh.base_currency = %s
                  AND rh.id = (
                      SELECT rh2.id
                      FROM %i rh2
                      WHERE rh2.base_currency = rh.base_currency
                        AND rh2.quote_currency = rh.quote_currency
                      ORDER BY rh2.fetched_at DESC, rh2.id DESC
                      LIMIT 1
                )",
                $table,
                strtoupper($baseCurrency),
                $table,
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $wpdb->insert is the WordPress CRUD API for the plugin-owned rate history.
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
