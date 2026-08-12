<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Runtime;

defined('ABSPATH') || exit;

final class WpdbTransferSchemaInspector implements TransferSchemaInspector
{
    public function inspect(array $tables): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return [];
        }

        $result = [];

        foreach ($tables as $table) {
            if (preg_match('/\A[a-z0-9_]+\z/', $table) !== 1) {
                throw new \InvalidArgumentException('Unsafe transfer schema table name.');
            }

            $physical = (string) $wpdb->prefix . $table;
            $status = $wpdb->get_row($wpdb->prepare(
                'SHOW TABLE STATUS WHERE Name = %s',
                $physical,
            ), ARRAY_A);

            if (!is_array($status)) {
                continue;
            }

            $columns = $wpdb->get_results("SHOW FULL COLUMNS FROM `{$physical}`", ARRAY_A);
            $indexes = $wpdb->get_results("SHOW INDEX FROM `{$physical}`", ARRAY_A);

            if (!is_array($columns) || !is_array($indexes)) {
                continue;
            }

            $columnMap = [];

            foreach ($columns as $column) {
                $name = (string) ($column['Field'] ?? '');

                if ($name === '') {
                    continue;
                }

                $columnMap[$name] = [
                    'type' => strtolower((string) ($column['Type'] ?? '')),
                    'nullable' => strtoupper((string) ($column['Null'] ?? 'NO')) === 'YES',
                    'default' => $column['Default'] ?? null,
                    'extra' => strtolower((string) ($column['Extra'] ?? '')),
                ];
            }

            $indexMap = [];

            foreach ($indexes as $index) {
                $name = (string) ($index['Key_name'] ?? '');

                if ($name === '') {
                    continue;
                }

                $position = max(1, (int) ($index['Seq_in_index'] ?? 1));
                $indexMap[$name]['unique'] = (int) ($index['Non_unique'] ?? 1) === 0;
                $indexMap[$name]['columns'][$position] = (string) ($index['Column_name'] ?? '');
            }

            foreach ($indexMap as &$index) {
                ksort($index['columns']);
                $index['columns'] = array_values($index['columns']);
            }
            unset($index);

            ksort($columnMap);
            ksort($indexMap);

            $result[$table] = [
                'engine' => (string) ($status['Engine'] ?? ''),
                'columns' => $columnMap,
                'indexes' => $indexMap,
            ];
        }

        ksort($result);

        return $result;
    }
}
