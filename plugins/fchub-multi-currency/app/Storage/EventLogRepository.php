<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class EventLogRepository
{
    public function log(string $event, ?int $userId, ?string $ipHash, ?array $payload): void
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_EVENT_LOG;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $wpdb->insert is the WordPress CRUD API for the plugin-owned event log.
        $wpdb->insert($table, [
            'event'      => $event,
            'user_id'    => $userId,
            'ip_hash'    => $ipHash,
            'payload'    => $payload !== null ? wp_json_encode($payload) : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Age-based retention. Every storefront switch appends a row, so without
     * this the table grows for the life of the site.
     */
    public function pruneOlderThan(int $days): int
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_EVENT_LOG;

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This bounded custom-table retention write has no WordPress CRUD equivalent and no query cache.
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE created_at < %s",
                $table,
                $cutoff,
            ),
        );
    }

    /**
     * @return array<object>
     */
    public function findByUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_EVENT_LOG;

        $limit = max(1, $limit);
        $offset = max(0, $offset);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- GDPR export pagination must read the current append-only event log.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $table,
                $userId,
                $limit,
                $offset,
            ),
        );
    }

    public function deleteByUser(int $userId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_EVENT_LOG;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete is the WordPress CRUD API; this GDPR write has no query result cache.
        return (int) $wpdb->delete($table, ['user_id' => $userId], ['%d']);
    }

    /**
     * @return array<string, int>
     */
    public function countByEvent(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_EVENT_LOG;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics totals intentionally reflect the current append-only event log.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event, COUNT(*) AS total FROM %i GROUP BY event",
            $table,
        ));
        $counts = [];

        foreach ($rows as $row) {
            if (!isset($row->event)) {
                continue;
            }

            $counts[(string) $row->event] = (int) ($row->total ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<int, array{currency: string, total: int}>
     */
    public function topCurrenciesForEvent(string $event, int $limit = 5): array
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_EVENT_LOG;
        $limit = max(1, $limit);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics rankings intentionally reflect the current append-only event log.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT payload FROM %i WHERE event = %s ORDER BY created_at DESC LIMIT %d",
                $table,
                $event,
                200,
            ),
        );

        $counts = [];
        foreach ($rows as $row) {
            $payload = isset($row->payload) ? json_decode((string) $row->payload, true) : null;
            if (!is_array($payload)) {
                continue;
            }

            $currency = strtoupper((string) ($payload['currency'] ?? ''));
            if ($currency === '') {
                continue;
            }

            $counts[$currency] = ($counts[$currency] ?? 0) + 1;
        }

        arsort($counts);
        $output = [];
        foreach (array_slice($counts, 0, $limit, true) as $currency => $total) {
            $output[] = [
                'currency' => $currency,
                'total' => $total,
            ];
        }

        return $output;
    }
}
