<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\MigrationV5;
use FChubMemberships\Support\Migrations;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MigrationV5Test extends PluginTestCase
{
    public function test_empty_install_creates_both_v5_tables_with_the_required_contract(): void
    {
        if (!class_exists(MigrationV5::class)) {
            self::fail('Migration V5 must create the entitlement integrity schema.');
        }

        MigrationV5::run();

        $schemas = implode("\n", $GLOBALS['_fchub_test_dbdelta']);
        self::assertStringContainsString('CREATE TABLE wp_fchub_membership_entitlement_edges', $schemas);
        self::assertStringContainsString('CREATE TABLE wp_fchub_membership_provider_operations', $schemas);
        self::assertStringContainsString(
            'UNIQUE KEY entitlement_identity (user_id, provider, resource_type, resource_id, plan_id, feed_id, feed_scope, source_type, source_id)',
            $schemas
        );
        foreach ([
            'user_id BIGINT UNSIGNED NOT NULL',
            'provider VARCHAR(50) NOT NULL',
            'resource_type VARCHAR(50) NOT NULL',
            'resource_id VARCHAR(100) NOT NULL',
            'plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0',
            "feed_scope VARCHAR(20) NOT NULL DEFAULT 'external_unknown'",
            'source_type VARCHAR(30) NOT NULL',
            'source_id BIGINT UNSIGNED NOT NULL DEFAULT 0',
        ] as $identityDefinition) {
            self::assertStringContainsString($identityDefinition, $schemas);
        }
        foreach ([
            'owner', 'assignment_provenance', 'lifecycle', 'starts_at', 'expires_at',
            'drip_available_at', 'ended_at', 'end_reason', 'policy',
        ] as $column) {
            self::assertMatchesRegularExpression('/\\b' . $column . '\\b/i', $schemas);
        }
        foreach (['operation_key', 'desired_action', 'origin_event', 'state', 'lease_owner', 'lease_expires_at', 'attempt_count', 'retryable', 'next_retry_at', 'last_error_code', 'last_error_message', 'completed_at'] as $column) {
            self::assertMatchesRegularExpression('/\\b' . $column . '\\b/i', $schemas);
        }
        self::assertStringContainsString('UNIQUE KEY operation_key (operation_key)', $schemas);
        self::assertStringContainsString('retryable TINYINT(1) NOT NULL DEFAULT 1', $schemas);
        self::assertStringContainsString('KEY state_due (state, retryable, next_retry_at)', $schemas);
        self::assertStringContainsString('KEY lifecycle_expires (lifecycle, expires_at)', $schemas);
        self::assertStringContainsString('KEY lifecycle_drip (lifecycle, drip_available_at)', $schemas);
        self::assertStringContainsString('KEY lifecycle_ended (lifecycle, ended_at)', $schemas);
    }

    public function test_partial_migration_replay_is_additive_and_retries_both_tables(): void
    {
        if (!class_exists(MigrationV5::class)) {
            self::fail('Migration V5 must be replayable after a partial migration.');
        }

        MigrationV5::run();
        MigrationV5::run();

        $edgeSchemas = array_filter(
            $GLOBALS['_fchub_test_dbdelta'],
            static fn(string $schema): bool => str_contains($schema, 'fchub_membership_entitlement_edges')
        );
        $operationSchemas = array_filter(
            $GLOBALS['_fchub_test_dbdelta'],
            static fn(string $schema): bool => str_contains($schema, 'fchub_membership_provider_operations')
        );

        self::assertCount(2, $edgeSchemas);
        self::assertCount(2, $operationSchemas);
        self::assertStringNotContainsString('DROP TABLE', implode("\n", $GLOBALS['_fchub_test_dbdelta']));
    }

    public function test_failed_v5_ddl_cannot_pass_the_migration_postcondition(): void
    {
        $result = Migrations::run();

        self::assertFalse($result['success']);
        self::assertContains('table:entitlement_edges missing', $result['failures']);
        self::assertContains('table:provider_operations missing', $result['failures']);
    }

    public function test_missing_v5_index_is_reported_by_the_shared_schema_verifier(): void
    {
        $this->installV5TableMetadata(includeOperationKey: false);

        $failures = Migrations::verifySchema();

        self::assertContains('index:provider_operations.operation_key missing', $failures);
    }

    public function test_missing_edge_lifecycle_index_is_reported_by_the_shared_schema_verifier(): void
    {
        $this->installV5TableMetadata();
        $metadata = $GLOBALS['_fchub_test_wpdb_overrides']['get_results'];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($metadata): array {
            $rows = $metadata($query);
            if (!str_contains($query, 'SHOW INDEX FROM') || !str_contains($query, 'entitlement_edges')) {
                return $rows;
            }

            return array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row['Key_name'] !== 'lifecycle_drip'
            ));
        };

        $failures = Migrations::verifySchema();

        self::assertContains('index:entitlement_edges.lifecycle_drip missing', $failures);
    }

    #[DataProvider('invalidColumnDefinitionProvider')]
    public function test_v5_column_definition_postconditions_reject_drift(
        string $table,
        string $column,
        array $override,
        string $expectedFailure
    ): void {
        $this->installV5TableMetadata(columnOverrides: ["{$table}.{$column}" => $override]);

        $failures = Migrations::verifySchema();

        self::assertContains($expectedFailure, $failures);
    }

    public static function invalidColumnDefinitionProvider(): array
    {
        return [
            'edge type' => [
                'entitlement_edges',
                'feed_id',
                ['Type' => 'int unsigned'],
                'column:entitlement_edges.feed_id expected bigint unsigned NOT NULL default 0',
            ],
            'edge unsigned' => [
                'entitlement_edges',
                'user_id',
                ['Type' => 'bigint'],
                'column:entitlement_edges.user_id expected bigint unsigned NOT NULL default NULL',
            ],
            'edge nullability' => [
                'entitlement_edges',
                'provider',
                ['Null' => 'YES'],
                'column:entitlement_edges.provider expected varchar(50) NOT NULL default NULL',
            ],
            'edge default' => [
                'entitlement_edges',
                'lifecycle',
                ['Default' => 'ended'],
                'column:entitlement_edges.lifecycle expected varchar(20) NOT NULL default active',
            ],
            'operation type' => [
                'provider_operations',
                'attempt_count',
                ['Type' => 'bigint unsigned'],
                'column:provider_operations.attempt_count expected int unsigned NOT NULL default 0',
            ],
            'operation unsigned' => [
                'provider_operations',
                'edge_id',
                ['Type' => 'bigint'],
                'column:provider_operations.edge_id expected bigint unsigned NOT NULL default NULL',
            ],
            'operation nullability' => [
                'provider_operations',
                'operation_key',
                ['Null' => 'YES'],
                'column:provider_operations.operation_key expected char(64) NOT NULL default NULL',
            ],
            'operation default' => [
                'provider_operations',
                'retryable',
                ['Default' => '0'],
                'column:provider_operations.retryable expected tinyint(1) NOT NULL default 1',
            ],
        ];
    }

    #[DataProvider('everyV5ColumnProvider')]
    public function test_every_v5_column_has_an_exact_definition_postcondition(string $table, string $column): void
    {
        $this->installV5TableMetadata(columnOverrides: [
            "{$table}.{$column}" => ['Type' => 'binary(16)'],
        ]);

        $failures = Migrations::verifySchema();

        self::assertNotEmpty(array_filter(
            $failures,
            static fn(string $failure): bool => str_starts_with($failure, "column:{$table}.{$column} expected ")
        ));
    }

    public static function everyV5ColumnProvider(): array
    {
        $tables = [
            'entitlement_edges' => [
                'id', 'user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id',
                'feed_scope', 'source_type', 'source_id', 'owner', 'assignment_provenance', 'lifecycle',
                'starts_at', 'expires_at', 'drip_available_at', 'ended_at', 'end_reason', 'policy',
                'created_at', 'updated_at',
            ],
            'provider_operations' => [
                'id', 'edge_id', 'operation_key', 'desired_action', 'origin_event', 'state', 'lease_owner',
                'lease_expires_at', 'attempt_count', 'retryable', 'next_retry_at', 'last_error_code',
                'last_error_message', 'eligible_at', 'created_at', 'updated_at', 'completed_at',
            ],
        ];

        $cases = [];
        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                $cases["{$table}.{$column}"] = [$table, $column];
            }
        }

        return $cases;
    }

    public function test_missing_provider_operation_edge_relationship_is_reported(): void
    {
        $this->installV5TableMetadata(includeForeignKey: false);

        $failures = Migrations::verifySchema();

        self::assertContains(
            'foreign_key:provider_operations.fk_provider_operations_edge missing',
            $failures
        );
    }

    public function test_wrong_provider_operation_edge_relationship_is_reported(): void
    {
        $this->installV5TableMetadata(foreignKeyOverride: ['DELETE_RULE' => 'CASCADE']);

        $failures = Migrations::verifySchema();

        self::assertContains(
            'foreign_key:provider_operations.fk_provider_operations_edge expected edge_id -> entitlement_edges.id ON DELETE RESTRICT',
            $failures
        );
    }

    public function test_orphan_provider_operation_edge_is_reported(): void
    {
        $this->installV5TableMetadata(orphanOperations: 1);

        $failures = Migrations::verifySchema();

        self::assertContains(
            'orphan:provider_operations.edge_id references missing entitlement_edges rows',
            $failures
        );
    }

    public function test_v5_reports_failed_foreign_key_ddl(): void
    {
        $this->installV5TableMetadata(includeForeignKey: false);
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(string $query): int|false =>
            str_contains($query, 'ADD CONSTRAINT fk_provider_operations_edge') ? false : 0;

        $failures = MigrationV5::run();

        self::assertSame(
            ['provider_operations: failed adding foreign key fk_provider_operations_edge'],
            $failures
        );
    }

    public function test_migrations_refuses_success_when_v5_foreign_key_ddl_fails(): void
    {
        $this->installV5TableMetadata(includeForeignKey: false);
        $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = '1.4.0';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(string $query): int|false =>
            str_contains($query, 'ADD CONSTRAINT fk_provider_operations_edge') ? false : 0;

        $result = Migrations::run();
        if ($result['success']) {
            update_option('fchub_memberships_db_version', FCHUB_MEMBERSHIPS_DB_VERSION);
        }

        self::assertFalse($result['success']);
        self::assertContains(
            'provider_operations: failed adding foreign key fk_provider_operations_edge',
            $result['failures']
        );
        self::assertSame('1.4.0', get_option('fchub_memberships_db_version'));
    }

    public function test_partial_table_replay_adds_the_relationship_only_after_both_tables_exist(): void
    {
        $this->installV5TableMetadata(providerOperationsTableExists: false, includeForeignKey: false);

        self::assertSame([], MigrationV5::run());
        self::assertStringNotContainsString(
            'ADD CONSTRAINT fk_provider_operations_edge',
            serialize($GLOBALS['_fchub_test_queries'])
        );

        $GLOBALS['_fchub_test_queries'] = [];
        $this->installV5TableMetadata(includeForeignKey: false);
        $metadata = $GLOBALS['_fchub_test_wpdb_overrides']['get_results'];
        $foreignKeyAdded = false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (
            $metadata,
            &$foreignKeyAdded
        ): array {
            if (str_contains($query, 'information_schema.REFERENTIAL_CONSTRAINTS')
                && str_contains($query, 'provider_operations')
            ) {
                return $foreignKeyAdded ? [[
                    'CONSTRAINT_NAME' => 'fk_provider_operations_edge',
                    'COLUMN_NAME' => 'edge_id',
                    'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_entitlement_edges',
                    'REFERENCED_COLUMN_NAME' => 'id',
                    'DELETE_RULE' => 'RESTRICT',
                ]] : [];
            }

            return $metadata($query);
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$foreignKeyAdded): int {
            if (str_contains($query, 'ADD CONSTRAINT fk_provider_operations_edge')) {
                $foreignKeyAdded = true;
            }

            return 0;
        };

        self::assertSame([], MigrationV5::run());
        self::assertSame([], MigrationV5::run());
        self::assertStringContainsString(
            'ADD CONSTRAINT fk_provider_operations_edge',
            serialize($GLOBALS['_fchub_test_queries'])
        );
        $foreignKeyWrites = array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'query'
                && str_contains($query[1], 'ADD CONSTRAINT fk_provider_operations_edge')
        );
        self::assertCount(1, $foreignKeyWrites);
    }

    public function test_database_version_targets_v5_only_after_verified_schema(): void
    {
        self::assertSame('1.9.0', FCHUB_MEMBERSHIPS_DB_VERSION);

        $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = '1.4.0';
        $result = Migrations::run();
        if ($result['success']) {
            update_option('fchub_memberships_db_version', FCHUB_MEMBERSHIPS_DB_VERSION);
        }

        self::assertSame('1.4.0', get_option('fchub_memberships_db_version'));
    }

    public function test_drop_all_removes_operations_before_their_entitlement_edges(): void
    {
        Migrations::dropAll();

        $drops = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'query' && str_starts_with($query[1], 'DROP TABLE')
        ));
        $tables = array_map(static fn(array $query): string => $query[1], $drops);
        $operationPosition = array_search(
            'DROP TABLE IF EXISTS wp_fchub_membership_provider_operations',
            $tables,
            true
        );
        $edgePosition = array_search(
            'DROP TABLE IF EXISTS wp_fchub_membership_entitlement_edges',
            $tables,
            true
        );

        self::assertIsInt($operationPosition);
        self::assertIsInt($edgePosition);
        self::assertLessThan($edgePosition, $operationPosition);
    }

    /**
     * @param array<string, array<string, mixed>> $columnOverrides
     * @param array<string, mixed> $foreignKeyOverride
     */
    private function installV5TableMetadata(
        array $columnOverrides = [],
        bool $includeOperationKey = true,
        bool $includeForeignKey = true,
        array $foreignKeyOverride = [],
        int $orphanOperations = 0,
        bool $providerOperationsTableExists = true
    ): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (
            $orphanOperations,
            $providerOperationsTableExists
        ): string|int|null {
            if (str_contains($query, 'provider_operations child') && str_contains($query, 'LEFT JOIN')) {
                return $orphanOperations;
            }
            if (!str_contains($query, 'SHOW TABLES LIKE')) {
                return 0;
            }

            preg_match("/LIKE '([^']+)'/", $query, $matches);
            $table = str_replace('\\_', '_', $matches[1] ?? '');

            if (str_contains($table, 'entitlement_edges')) {
                return $table;
            }
            if (str_contains($table, 'provider_operations')) {
                return $providerOperationsTableExists ? $table : null;
            }

            return null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (
            $columnOverrides,
            $includeOperationKey,
            $includeForeignKey,
            $foreignKeyOverride
        ): array {
            if (str_contains($query, 'SHOW TABLE STATUS LIKE')) {
                preg_match("/LIKE '([^']+)'/", $query, $matches);
                return [['Name' => str_replace('\\_', '_', $matches[1] ?? ''), 'Engine' => 'InnoDB']];
            }
            if (str_contains($query, 'SHOW COLUMNS FROM')) {
                $table = str_contains($query, 'entitlement_edges')
                    ? 'entitlement_edges'
                    : 'provider_operations';
                $rows = self::v5ColumnRows($table);
                foreach ($rows as &$row) {
                    $override = $columnOverrides[$table . '.' . $row['Field']] ?? [];
                    $row = array_merge($row, $override);
                }
                unset($row);

                if (preg_match("/ LIKE '([^']+)'/", $query, $matches)) {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn(array $row): bool => $row['Field'] === $matches[1]
                    ));
                }

                return $rows;
            }
            if (str_contains($query, 'SHOW INDEX FROM') && str_contains($query, 'entitlement_edges')) {
                return self::indexRows([
                    'PRIMARY' => ['id'],
                    'entitlement_identity' => ['user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id', 'feed_scope', 'source_type', 'source_id'],
                    'active_resource' => ['user_id', 'provider', 'resource_type', 'resource_id', 'lifecycle'],
                    'source_lifecycle' => ['source_type', 'source_id', 'lifecycle'],
                    'plan_feed_lifecycle' => ['plan_id', 'feed_id', 'feed_scope', 'lifecycle'],
                    'lifecycle_expires' => ['lifecycle', 'expires_at'],
                    'lifecycle_drip' => ['lifecycle', 'drip_available_at'],
                    'lifecycle_ended' => ['lifecycle', 'ended_at'],
                    'plan_access_lifecycle_user' => ['plan_id', 'access_status', 'lifecycle', 'user_id'],
                ], ['PRIMARY', 'entitlement_identity']);
            }
            if (str_contains($query, 'SHOW INDEX FROM') && str_contains($query, 'provider_operations')) {
                $definitions = [
                    'PRIMARY' => ['id'],
                    'operation_key' => ['operation_key'],
                    'edge_state' => ['edge_id', 'state'],
                    'state_due' => ['state', 'retryable', 'next_retry_at'],
                    'state_lease' => ['state', 'lease_expires_at'],
                    'state_eligible' => ['state', 'eligible_at'],
                    'completed_at' => ['completed_at'],
                ];
                if (!$includeOperationKey) {
                    unset($definitions['operation_key']);
                }

                return self::indexRows($definitions, ['PRIMARY', 'operation_key']);
            }
            if (str_contains($query, 'information_schema.REFERENTIAL_CONSTRAINTS')
                && str_contains($query, 'provider_operations')
            ) {
                if (!$includeForeignKey) {
                    return [];
                }

                return [array_merge([
                    'CONSTRAINT_NAME' => 'fk_provider_operations_edge',
                    'COLUMN_NAME' => 'edge_id',
                    'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_entitlement_edges',
                    'REFERENCED_COLUMN_NAME' => 'id',
                    'DELETE_RULE' => 'RESTRICT',
                ], $foreignKeyOverride)];
            }

            return [];
        };
    }

    /** @return list<array{Field: string, Type: string, Null: string, Default: string|null}> */
    private static function v5ColumnRows(string $table): array
    {
        $definitions = $table === 'entitlement_edges'
            ? [
                'id' => ['bigint unsigned', 'NO', null],
                'user_id' => ['bigint unsigned', 'NO', null],
                'provider' => ['varchar(50)', 'NO', null],
                'resource_type' => ['varchar(50)', 'NO', null],
                'resource_id' => ['varchar(100)', 'NO', null],
                'plan_id' => ['bigint unsigned', 'NO', '0'],
                'feed_id' => ['bigint unsigned', 'NO', '0'],
                'feed_scope' => ['varchar(20)', 'NO', 'external_unknown'],
                'source_type' => ['varchar(30)', 'NO', null],
                'source_id' => ['bigint unsigned', 'NO', '0'],
                'owner' => ['varchar(20)', 'NO', 'external_unknown'],
                'assignment_provenance' => ['varchar(20)', 'NO', 'unknown'],
                'lifecycle' => ['varchar(20)', 'NO', 'active'],
                'access_status' => ['varchar(20)', 'NO', 'active'],
                'starts_at' => ['datetime', 'YES', null],
                'expires_at' => ['datetime', 'YES', null],
                'drip_available_at' => ['datetime', 'YES', null],
                'ended_at' => ['datetime', 'YES', null],
                'end_reason' => ['varchar(191)', 'YES', null],
                'policy' => ['longtext', 'YES', null],
                'created_at' => ['datetime', 'NO', null],
                'updated_at' => ['datetime', 'NO', null],
            ]
            : [
                'id' => ['bigint unsigned', 'NO', null],
                'edge_id' => ['bigint unsigned', 'NO', null],
                'operation_key' => ['char(64)', 'NO', null],
                'desired_action' => ['varchar(30)', 'NO', null],
                'origin_event' => ['varchar(100)', 'NO', null],
                'state' => ['varchar(20)', 'NO', 'pending'],
                'lease_owner' => ['varchar(64)', 'YES', null],
                'lease_expires_at' => ['datetime', 'YES', null],
                'attempt_count' => ['int unsigned', 'NO', '0'],
                'retryable' => ['tinyint(1)', 'NO', '1'],
                'next_retry_at' => ['datetime', 'YES', null],
                'last_error_code' => ['varchar(100)', 'YES', null],
                'last_error_message' => ['varchar(500)', 'YES', null],
                'eligible_at' => ['datetime', 'YES', null],
                'created_at' => ['datetime', 'NO', null],
                'updated_at' => ['datetime', 'NO', null],
                'completed_at' => ['datetime', 'YES', null],
            ];

        $rows = [];
        foreach ($definitions as $field => [$type, $null, $default]) {
            $rows[] = ['Field' => $field, 'Type' => $type, 'Null' => $null, 'Default' => $default];
        }

        return $rows;
    }

    /**
     * @param array<string, list<string>> $definitions
     * @param list<string> $uniqueIndexes
     * @return list<array<string, int|string>>
     */
    private static function indexRows(array $definitions, array $uniqueIndexes): array
    {
        $rows = [];
        foreach ($definitions as $name => $columns) {
            foreach ($columns as $position => $column) {
                $rows[] = [
                    'Key_name' => $name,
                    'Column_name' => $column,
                    'Seq_in_index' => $position + 1,
                    'Non_unique' => in_array($name, $uniqueIndexes, true) ? 0 : 1,
                ];
            }
        }

        return $rows;
    }
}
