<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage\Queries;

use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class RateHistoryQuery
{
    /**
     * @return array<ExchangeRate>
     */
    public function forPair(string $baseCurrency, string $quoteCurrency, int $limit = 30): array
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_RATE_HISTORY;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative history must display the current append-only rate records.
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE base_currency = %s AND quote_currency = %s ORDER BY fetched_at DESC LIMIT %d",
                $table,
                strtoupper($baseCurrency),
                strtoupper($quoteCurrency),
                $limit,
            ),
            ARRAY_A,
        );

        return array_map(
            fn(array $row) => ExchangeRate::from($row),
            $results,
        );
    }

    public function pruneOlderThan(int $days): int
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_RATE_HISTORY;

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This bounded custom-table retention write has no WordPress CRUD equivalent and no query cache.
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE fetched_at < %s",
                $table,
                $cutoff,
            ),
        );
    }
}
