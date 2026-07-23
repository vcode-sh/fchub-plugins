<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class MigrationV4
{
    /** @return list<string> */
    public static function run(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'fchub_membership_event_locks';
        $columns = [
            'state' => "VARCHAR(20) NOT NULL DEFAULT 'processing' AFTER error",
            'owner_token' => 'VARCHAR(64) DEFAULT NULL AFTER state',
            'lease_expires_at' => 'DATETIME DEFAULT NULL AFTER owner_token',
            'attempt_count' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER lease_expires_at',
            'retryable' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER attempt_count',
            'next_retry_at' => 'DATETIME DEFAULT NULL AFTER retryable',
            'updated_at' => 'DATETIME DEFAULT NULL AFTER next_retry_at',
            'completed_at' => 'DATETIME DEFAULT NULL AFTER updated_at',
            'last_error' => 'TEXT DEFAULT NULL AFTER completed_at',
        ];

        foreach ($columns as $column => $definition) {
            if (!self::columnExists($table, $column)) {
                if ($wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}") === false) {
                    return ["event_locks: failed adding V4 column {$column}"];
                }
            }
        }

        $unknownLegacyResults = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE updated_at IS NULL
               AND (result IS NULL OR result NOT IN ('success', 'failed'))"
        );
        if ($unknownLegacyResults > 0) {
            return ['event_locks: unknown legacy result values prevent safe V4 mapping'];
        }

        $clock = new Clock();
        $now = $clock->storage($clock->now());
        $mappedSuccess = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET state = 'succeeded',
                 owner_token = NULL,
                 lease_expires_at = NULL,
                 retryable = 0,
                 updated_at = COALESCE(updated_at, processed_at, %s),
                 completed_at = COALESCE(completed_at, processed_at, %s),
                 last_error = NULL
             WHERE result = 'success'
               AND state = 'processing'
               AND owner_token IS NULL
               AND lease_expires_at IS NULL
               AND updated_at IS NULL",
            $now,
            $now
        ));
        if ($mappedSuccess === false) {
            return ['event_locks: failed mapping legacy success rows'];
        }

        $mappedFailures = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET state = 'failed',
                 owner_token = NULL,
                 lease_expires_at = NULL,
                 retryable = 1,
                 next_retry_at = COALESCE(next_retry_at, %s),
                 updated_at = COALESCE(updated_at, processed_at, %s),
                 completed_at = NULL,
                 last_error = COALESCE(last_error, error)
             WHERE result = 'failed'
               AND state = 'processing'
               AND owner_token IS NULL
               AND lease_expires_at IS NULL
               AND updated_at IS NULL",
            $now,
            $now
        ));
        if ($mappedFailures === false) {
            return ['event_locks: failed mapping legacy failed rows'];
        }

        $unmappedLegacyResults = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE updated_at IS NULL
               AND result IN ('success', 'failed')
               AND state = 'processing'
               AND owner_token IS NULL
               AND lease_expires_at IS NULL"
        );
        if ($unmappedLegacyResults > 0) {
            return ['event_locks: legacy result rows remain unmapped after V4 mapping'];
        }

        $backfilledTimestamps = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET updated_at = COALESCE(updated_at, processed_at, %s)
             WHERE updated_at IS NULL",
            $now
        ));
        if ($backfilledTimestamps === false) {
            return ['event_locks: failed backfilling updated_at'];
        }

        if ($wpdb->query("ALTER TABLE {$table} MODIFY COLUMN updated_at DATETIME NOT NULL") === false) {
            return ['event_locks: failed enforcing updated_at NOT NULL'];
        }

        $indexes = [
            'idx_event_lock_state_lease' => 'state, lease_expires_at',
            'idx_event_lock_completed' => 'completed_at',
        ];
        foreach ($indexes as $name => $columnsSql) {
            if (!self::indexExists($table, $name)) {
                if ($wpdb->query("ALTER TABLE {$table} ADD INDEX {$name} ({$columnsSql})") === false) {
                    return ["event_locks: failed adding index {$name}"];
                }
            }
        }

        return [];
    }

    private static function columnExists(string $table, string $column): bool
    {
        global $wpdb;

        return !empty($wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ), ARRAY_A));
    }

    private static function indexExists(string $table, string $index): bool
    {
        global $wpdb;

        return !empty($wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            $index
        ), ARRAY_A));
    }
}
