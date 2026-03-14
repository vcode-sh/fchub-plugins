<?php

declare(strict_types=1);

namespace CartShift\Storage;

defined('ABSPATH') || exit;

final class MigrationLogRepository
{
    private readonly string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cartshift_migration_log';
    }

    /**
     * Write a log entry.
     */
    public function write(
        string $migrationId,
        string $entityType,
        string|int $wcId,
        string $status,
        string $message,
        array|null $details = null,
    ): void {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'migration_id' => $migrationId,
                'entity_type'  => $entityType,
                'wc_id'        => (string) $wcId,
                'status'       => $status,
                'message'      => $message,
                'details'      => $details !== null ? wp_json_encode($details) : null,
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        );
    }

    /**
     * Get paginated log entries with optional filters.
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function getPaginated(
        string|null $migrationId = null,
        int $page = 1,
        int $perPage = 50,
        string|null $status = null,
    ): array {
        global $wpdb;

        $where = [];
        $params = [];

        if ($migrationId !== null) {
            $where[] = 'migration_id = %s';
            $params[] = $migrationId;
        }

        if ($status !== null) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM {$this->table} {$whereClause}";
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params))
            : (int) $wpdb->get_var($countSql);

        $offset = ($page - 1) * $perPage;
        $dataSql = "SELECT * FROM {$this->table} {$whereClause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $dataParams = [...$params, $perPage, $offset];

        $rows = $wpdb->get_results($wpdb->prepare($dataSql, ...$dataParams), ARRAY_A);

        return [
            'data'     => array_map([$this, 'hydrate'], $rows ?: []),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get counts grouped by status for a migration.
     *
     * @return array{success: int, skipped: int, error: int, total: int}
     */
    public function getStats(string $migrationId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count FROM {$this->table}
             WHERE migration_id = %s
             GROUP BY status",
            $migrationId,
        ), ARRAY_A);

        $stats = ['success' => 0, 'skipped' => 0, 'error' => 0, 'total' => 0];

        foreach ($rows ?: [] as $row) {
            $count = (int) $row['count'];
            $stats[$row['status']] = $count;
            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * Delete all log entries for a migration.
     */
    public function deleteByMigration(string $migrationId): void
    {
        global $wpdb;

        $wpdb->delete(
            $this->table,
            ['migration_id' => $migrationId],
            ['%s'],
        );
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['details'] = isset($row['details']) ? json_decode($row['details'], true) : null;

        return $row;
    }
}
