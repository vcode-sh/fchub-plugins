<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

final class Logger
{
    private const string PREFIX = '[CartShift]';

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        self::write('DEBUG', $message, $context);
    }

    public static function migrationLog(
        string $migrationId,
        string $entityType,
        string|int $wcId,
        string $status,
        string $message,
        ?array $details = null,
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cartshift_migration_log';

        $data = [
            'migration_id' => $migrationId,
            'entity_type'  => $entityType,
            'wc_id'        => (string) $wcId,
            'status'       => $status,
            'message'      => $message,
            'details'      => $details !== null ? wp_json_encode($details) : null,
            'created_at'   => current_time('mysql'),
        ];

        $formats = [
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        ];

        $wpdb->insert($table, $data, $formats);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $entry = sprintf('%s %s: %s', self::PREFIX, $level, $message);

        if ($context !== []) {
            $entry .= ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        error_log($entry);
    }
}
