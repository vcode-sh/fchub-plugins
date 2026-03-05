<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

// TODO: Logging infrastructure is ready but not yet wired up.
// Call log() from key actions (rate refresh, currency switch) when event tracking is needed.
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
}
