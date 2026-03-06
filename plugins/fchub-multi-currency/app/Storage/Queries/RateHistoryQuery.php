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

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE base_currency = %s AND quote_currency = %s ORDER BY fetched_at DESC LIMIT %d",
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

        // fetched_at is stored using current_time('mysql') (site timezone), so
        // compute the cutoff in the same timezone basis to avoid mismatches.
        $cutoff = wp_date('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE fetched_at < %s",
                $cutoff,
            ),
        );
    }
}
