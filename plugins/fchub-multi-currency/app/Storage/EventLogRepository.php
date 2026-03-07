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

        $wpdb->insert($table, [
            'event'      => $event,
            'user_id'    => $userId,
            'ip_hash'    => $ipHash,
            'payload'    => $payload !== null ? wp_json_encode($payload) : null,
            'created_at' => current_time('mysql'),
        ]);
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

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
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

        return (int) $wpdb->delete($table, ['user_id' => $userId], ['%d']);
    }

    /**
     * @return array<string, int>
     */
    public function countByEvent(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . Constants::TABLE_EVENT_LOG;

        $rows = $wpdb->get_results("SELECT event, COUNT(*) AS total FROM {$table} GROUP BY event");
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

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT payload FROM {$table} WHERE event = %s ORDER BY created_at DESC LIMIT %d",
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
