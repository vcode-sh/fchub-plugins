<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\MigrationV4;
use FChubMemberships\Support\Migrations;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MigrationV4Test extends PluginTestCase
{
    public function test_base_event_lock_schema_stays_legacy_until_v4_runs(): void
    {
        Migrations::run();

        $eventLockSchema = '';
        foreach ($GLOBALS['_fchub_test_dbdelta'] as $schema) {
            if (str_contains($schema, 'fchub_membership_event_locks')) {
                $eventLockSchema = strtolower($schema);
                break;
            }
        }

        self::assertNotSame('', $eventLockSchema);
        self::assertStringNotContainsString('owner_token', $eventLockSchema);
        self::assertStringNotContainsString('lease_expires_at', $eventLockSchema);
        self::assertStringNotContainsString('idx_event_lock_state_lease', $eventLockSchema);
    }

    public function test_v4_adds_lease_schema_indexes_and_maps_legacy_rows(): void
    {
        self::assertTrue(class_exists(MigrationV4::class), 'Migration V4 must own the event-lock lease upgrade.');
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [];

        MigrationV4::run();

        $sql = strtolower(serialize($GLOBALS['_fchub_test_queries']));
        foreach ([
            'state',
            'owner_token',
            'lease_expires_at',
            'attempt_count',
            'retryable',
            'next_retry_at',
            'updated_at',
            'completed_at',
            'last_error',
        ] as $column) {
            self::assertStringContainsString('add column ' . $column, $sql);
        }
        self::assertStringContainsString('idx_event_lock_state_lease', $sql);
        self::assertStringContainsString('state, lease_expires_at', $sql);
        self::assertStringContainsString('idx_event_lock_completed', $sql);
        self::assertStringContainsString('completed_at', $sql);
        self::assertStringContainsString("result = 'success'", $sql);
        self::assertStringContainsString("state = 'succeeded'", $sql);
        self::assertStringContainsString("result = 'failed'", $sql);
        self::assertStringContainsString("state = 'failed'", $sql);
        self::assertStringContainsString('retryable = 1', $sql);
        self::assertStringContainsString('next_retry_at', $sql);
        self::assertStringContainsString('owner_token is null', $sql);
        self::assertStringContainsString('lease_expires_at is null', $sql);
        self::assertStringContainsString('updated_at is null', $sql);
    }

    public function test_v4_rejects_unknown_legacy_results_before_backfilling_updated_at(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int =>
            str_contains($query, "result NOT IN ('success', 'failed')") ? 1 : 0;

        $failures = MigrationV4::run();

        self::assertIsArray($failures);
        self::assertContains('event_locks: unknown legacy result values prevent safe V4 mapping', $failures);
        $writes = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'query'
        ));
        $sql = strtolower(serialize($writes));
        self::assertStringNotContainsString("set state = 'succeeded'", $sql);
        self::assertStringNotContainsString('modify column updated_at datetime not null', $sql);
    }

    public function test_v4_preserves_a_genuine_processing_row_on_rerun(): void
    {
        $row = [
            'result' => 'success',
            'state' => 'processing',
            'owner_token' => 'current-owner',
            'lease_expires_at' => '2026-03-13 22:05:00',
            'updated_at' => '2026-03-13 22:00:00',
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [['present' => true]];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$row): int {
            if (!str_contains($query, "SET state = 'succeeded'")) {
                return 0;
            }

            $eligible = $row['result'] === 'success' && $row['state'] === 'processing';
            if (str_contains($query, 'owner_token IS NULL')) {
                $eligible = $eligible && $row['owner_token'] === null;
            }
            if (str_contains($query, 'lease_expires_at IS NULL')) {
                $eligible = $eligible && $row['lease_expires_at'] === null;
            }
            if (str_contains($query, 'updated_at IS NULL')) {
                $eligible = $eligible && $row['updated_at'] === null;
            }
            if ($eligible) {
                $row['state'] = 'succeeded';
                $row['owner_token'] = null;
            }

            return $eligible ? 1 : 0;
        };

        self::assertSame([], MigrationV4::run());
        self::assertSame('processing', $row['state']);
        self::assertSame('current-owner', $row['owner_token']);
    }

    #[DataProvider('v4WriteFailureProvider')]
    public function test_v4_stops_on_every_failed_database_write(
        string $queryNeedle,
        string $expectedFailure,
        string $metadataMode
    ): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($metadataMode): array {
            if ($metadataMode === 'missing_column' && str_contains($query, "LIKE 'state'")) {
                return [];
            }
            if ($metadataMode === 'missing_index' && str_contains($query, 'SHOW INDEX FROM')) {
                return [];
            }

            return [['present' => true]];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(string $query): int|false =>
            str_contains($query, $queryNeedle) ? false : 0;

        $failures = MigrationV4::run();

        self::assertSame([$expectedFailure], $failures);
    }

    public static function v4WriteFailureProvider(): array
    {
        return [
            'add column' => [
                'ADD COLUMN state',
                'event_locks: failed adding V4 column state',
                'missing_column',
            ],
            'map legacy success' => [
                "SET state = 'succeeded'",
                'event_locks: failed mapping legacy success rows',
                'complete',
            ],
            'map legacy failure' => [
                "SET state = 'failed'",
                'event_locks: failed mapping legacy failed rows',
                'complete',
            ],
            'backfill timestamp' => [
                "UPDATE wp_fchub_membership_event_locks\n             SET updated_at",
                'event_locks: failed backfilling updated_at',
                'complete',
            ],
            'enforce timestamp nullability' => [
                'MODIFY COLUMN updated_at DATETIME NOT NULL',
                'event_locks: failed enforcing updated_at NOT NULL',
                'complete',
            ],
            'add index' => [
                'ADD INDEX idx_event_lock_state_lease',
                'event_locks: failed adding index idx_event_lock_state_lease',
                'missing_index',
            ],
        ];
    }

    public function test_v4_requires_zero_unmapped_legacy_marker_rows_before_backfill(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [['present' => true]];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int =>
            str_contains($query, "result IN ('success', 'failed')") ? 1 : 0;

        $failures = MigrationV4::run();

        self::assertSame(
            ['event_locks: legacy result rows remain unmapped after V4 mapping'],
            $failures
        );
        self::assertStringNotContainsString(
            "UPDATE wp_fchub_membership_event_locks\n             SET updated_at",
            serialize($GLOBALS['_fchub_test_queries'])
        );
    }

    public function test_migrations_run_returns_success_for_complete_verified_schema(): void
    {
        $this->installSchemaPostconditions();

        $result = Migrations::run();

        self::assertIsArray($result);
        self::assertTrue($result['success']);
        self::assertSame([], $result['failures']);
    }

    #[DataProvider('missingPostconditionProvider')]
    public function test_migrations_run_returns_specific_failure_for_invalid_postcondition(
        string $missing,
        string $expectedFailure
    ): void
    {
        $this->installSchemaPostconditions($missing);

        $result = Migrations::run();

        self::assertIsArray($result);
        self::assertFalse($result['success']);
        self::assertContains($expectedFailure, $result['failures']);
    }

    public static function missingPostconditionProvider(): array
    {
        return [
            'table' => ['table', 'table:event_locks missing'],
            'column' => ['column', 'column:event_locks.owner_token missing'],
            'column definition' => [
                'column_definition',
                'column:event_locks.owner_token expected varchar(64) NULL default NULL',
            ],
            'column unsigned' => [
                'column_unsigned',
                'column:event_locks.attempt_count expected int unsigned NOT NULL default 1',
            ],
            'column nullability' => [
                'column_nullability',
                'column:event_locks.state expected varchar(20) NOT NULL default processing',
            ],
            'column default' => [
                'column_default',
                'column:event_locks.retryable expected tinyint(1) NOT NULL default 1',
            ],
            'foreign key' => [
                'foreign_key',
                'foreign_key:drip_notifications.fk_drip_rule missing',
            ],
            'foreign key definition' => [
                'foreign_key_definition',
                'foreign_key:drip_notifications.fk_drip_rule expected plan_rule_id -> plan_rules.id ON DELETE CASCADE',
            ],
            'foreign key child column' => [
                'foreign_key_column',
                'foreign_key:drip_notifications.fk_drip_rule expected plan_rule_id -> plan_rules.id ON DELETE CASCADE',
            ],
            'foreign key reference' => [
                'foreign_key_reference',
                'foreign_key:drip_notifications.fk_drip_rule expected plan_rule_id -> plan_rules.id ON DELETE CASCADE',
            ],
            'index' => ['index', 'index:event_locks.idx_event_lock_state_lease missing'],
            'index definition' => [
                'index_definition',
                'index:event_locks.idx_event_lock_state_lease expected non-unique (state, lease_expires_at)',
            ],
            'index uniqueness' => [
                'index_uniqueness',
                'index:event_locks.idx_event_lock_state_lease expected non-unique (state, lease_expires_at)',
            ],
            'engine' => ['engine', 'table:event_locks engine expected InnoDB, got MyISAM'],
            'orphan row' => [
                'orphan',
                'orphan:drip_notifications.plan_rule_id references missing plan_rules rows',
            ],
        ];
    }

    public function test_dbdelta_text_without_resulting_metadata_is_a_failure(): void
    {
        $result = Migrations::run();

        self::assertNotEmpty($GLOBALS['_fchub_test_dbdelta']);
        self::assertIsArray($result);
        self::assertFalse($result['success']);
        self::assertContains('table:plans missing', $result['failures']);
    }

    #[DataProvider('tableLookupFalsePositiveProvider')]
    public function test_schema_verification_rejects_similarly_named_table_false_positives(
        string $surface,
        string $expectedFailure
    ): void
    {
        $this->installSchemaPostconditions();
        $GLOBALS['wpdb'] = new class extends \wpdb {
            public function esc_like(string $value): string
            {
                return addcslashes($value, '_%\\');
            }
        };
        $lookups = [];
        $getVar = $GLOBALS['_fchub_test_wpdb_overrides']['get_var'];
        $getResults = $GLOBALS['_fchub_test_wpdb_overrides']['get_results'];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (
            $surface,
            $getVar,
            &$lookups
        ): int|string|null {
            $lookups[] = $query;
            if ($surface === 'existence'
                && str_contains($query, 'SHOW TABLES LIKE')
                && str_contains($query, 'event')
            ) {
                return 'wpxfchub_membership_event_locks';
            }

            return $getVar($query);
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (
            $surface,
            $getResults,
            &$lookups
        ): array {
            $lookups[] = $query;
            if ($surface === 'engine'
                && str_contains($query, 'SHOW TABLE STATUS')
                && str_contains($query, 'event')
            ) {
                return [['Name' => 'wpxfchub_membership_event_locks', 'Engine' => 'InnoDB']];
            }

            return $getResults($query);
        };

        $failures = Migrations::verifySchema();

        self::assertContains($expectedFailure, $failures);
        self::assertNotEmpty(array_filter(
            $lookups,
            static fn(string $query): bool => str_contains(
                $query,
                'wp\\_fchub\\_membership\\_event\\_locks'
            )
        ));
    }

    public static function tableLookupFalsePositiveProvider(): array
    {
        return [
            'existence lookup' => ['existence', 'table:event_locks missing'],
            'engine lookup' => ['engine', 'table:event_locks engine metadata missing'],
        ];
    }

    private function installSchemaPostconditions(?string $missing = null): void
    {
        $columns = $this->allRequiredColumns();
        if ($missing === 'column') {
            $columns = array_values(array_diff($columns, ['owner_token']));
        }
        $indexes = [
            'PRIMARY',
            'slug',
            'status',
            'level',
            'plan_provider_type',
            'plan_sort',
            'grant_key',
            'user_access',
            'user_plan',
            'source_lookup',
            'feed_id',
            'status_expires',
            'status_drip',
            'idx_trial_ends',
            'idx_cancellation_effective',
            'idx_renewal_count',
            'event_hash',
            'idx_retry',
            'idx_event_lock_state_lease',
            'idx_event_lock_completed',
            'request_key',
            'state_updated',
            'resource_lookup',
            'subscription_id',
            'pending_notify',
            'grant_id',
            'user_id',
            'date_plan',
            'entity_lookup',
            'actor_lookup',
            'created_at',
            'grant_source',
            'entitlement_identity',
            'active_resource',
            'source_lifecycle',
            'plan_feed_lifecycle',
            'lifecycle_expires',
            'lifecycle_drip',
            'lifecycle_ended',
            'plan_access_lifecycle_user',
            'operation_key',
            'edge_state',
            'state_due',
            'state_lease',
            'state_eligible',
            'completed_at',
            'status_due',
            'status_lease',
            'last_success',
            'retention_completed',
            'event_id',
            'type_occurred',
            'event_destination',
            'status_next',
        ];
        if ($missing === 'index') {
            $indexes = array_values(array_diff($indexes, ['idx_event_lock_state_lease']));
        }

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use ($missing): int|string|null {
            if (str_contains($query, 'fct_subscriptions')) {
                return null;
            }
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                if ($missing === 'table' && str_contains($query, 'event_locks')) {
                    return null;
                }
                preg_match("/LIKE '([^']+)'/", $query, $matches);
                return str_replace('\\_', '_', $matches[1] ?? 'present');
            }
            if (str_contains($query, 'LEFT JOIN') && str_contains($query, 'IS NULL')) {
                return $missing === 'orphan' && str_contains($query, 'plan_rule_id') ? 1 : 0;
            }
            if (str_contains($query, 'GET_LOCK(') || str_contains($query, 'RELEASE_LOCK(')) {
                return 1;
            }
            return 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (
            $columns,
            $indexes,
            $missing
        ): array {
            if (str_contains($query, 'SHOW TABLE STATUS LIKE')) {
                preg_match("/LIKE '([^']+)'/", $query, $matches);
                return [[
                    'Name' => str_replace('\\_', '_', $matches[1] ?? 'present'),
                    'Engine' => $missing === 'engine' && str_contains($query, 'event_locks') ? 'MyISAM' : 'InnoDB',
                ]];
            }
            if (str_contains($query, 'SHOW COLUMNS FROM')) {
                $rows = array_map(static function (string $column) use ($missing, $query): array {
                    $v8Definition = self::v8ColumnDefinition($query, $column);
                    if ($v8Definition !== null) {
                        return ['Field' => $column] + $v8Definition;
                    }
                    $v7Definition = self::v7ColumnDefinition($query, $column);
                    if ($v7Definition !== null) {
                        return ['Field' => $column] + $v7Definition;
                    }
                    $v6Definition = self::v6ColumnDefinition($query, $column);
                    if ($v6Definition !== null) {
                        return ['Field' => $column] + $v6Definition;
                    }

                    $v5Definition = self::v5ColumnDefinition($query, $column);
                    if ($v5Definition !== null) {
                        return ['Field' => $column] + $v5Definition;
                    }

                    $definition = match ($column) {
                        'state' => ['Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => 'processing'],
                        'owner_token' => [
                            'Type' => $missing === 'column_definition' ? 'varchar(32)' : 'varchar(64)',
                            'Null' => 'YES',
                            'Default' => null,
                        ],
                        'lease_expires_at', 'next_retry_at', 'completed_at' => [
                            'Type' => 'datetime', 'Null' => 'YES', 'Default' => null,
                        ],
                        'attempt_count' => [
                            'Type' => $missing === 'column_unsigned' ? 'int' : 'int unsigned',
                            'Null' => 'NO',
                            'Default' => '1',
                        ],
                        'retryable' => [
                            'Type' => 'tinyint(1)',
                            'Null' => 'NO',
                            'Default' => $missing === 'column_default' ? '0' : '1',
                        ],
                        'updated_at' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null],
                        'last_error' => ['Type' => 'text', 'Null' => 'YES', 'Default' => null],
                        default => ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null],
                    };

                    if ($column === 'state' && $missing === 'column_nullability') {
                        $definition['Null'] = 'YES';
                    }

                    return ['Field' => $column] + $definition;
                }, $columns);
                if (preg_match("/ LIKE '([^']+)'/", $query, $matches)) {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn(array $row): bool => $row['Field'] === $matches[1]
                    ));
                }

                return $rows;
            }
            if (str_contains($query, 'SHOW INDEX FROM')) {
                $definitions = self::indexDefinitions($query);
                $rows = [];
                foreach ($indexes as $index) {
                    $columnsForIndex = $definitions[$index] ?? ['id'];
                    if ($missing === 'index_definition' && $index === 'idx_event_lock_state_lease') {
                        $columnsForIndex = ['lease_expires_at', 'state'];
                    }
                    foreach ($columnsForIndex as $position => $column) {
                        $rows[] = [
                            'Key_name' => $index,
                            'Column_name' => $column,
                            'Seq_in_index' => $position + 1,
                            'Non_unique' => $missing === 'index_uniqueness' && $index === 'idx_event_lock_state_lease'
                                ? 0
                                : (in_array($index, ['PRIMARY', 'slug', 'grant_key', 'event_hash', 'request_key', 'date_plan', 'grant_source', 'entitlement_identity', 'operation_key', 'event_id', 'event_destination'], true) ? 0 : 1),
                        ];
                    }
                }

                if (preg_match("/Key_name = '([^']+)'/", $query, $matches)) {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn(array $row): bool => $row['Key_name'] === $matches[1]
                    ));
                }

                return $rows;
            }
            if (str_contains($query, 'information_schema.REFERENTIAL_CONSTRAINTS')) {
                $foreignKeys = [
                    ['CONSTRAINT_NAME' => 'fk_plan_rules_plan', 'COLUMN_NAME' => 'plan_id', 'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_plans', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'CASCADE'],
                    ['CONSTRAINT_NAME' => 'fk_grants_plan', 'COLUMN_NAME' => 'plan_id', 'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_plans', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'SET NULL'],
                    ['CONSTRAINT_NAME' => 'fk_drip_grant', 'COLUMN_NAME' => 'grant_id', 'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_grants', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'CASCADE'],
                    ['CONSTRAINT_NAME' => 'fk_provider_operations_edge', 'COLUMN_NAME' => 'edge_id', 'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_entitlement_edges', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'RESTRICT'],
                    [
                        'CONSTRAINT_NAME' => 'fk_drip_rule',
                        'COLUMN_NAME' => $missing === 'foreign_key_column' ? 'grant_id' : 'plan_rule_id',
                        'REFERENCED_TABLE_NAME' => $missing === 'foreign_key_reference'
                            ? 'wp_fchub_membership_grants'
                            : 'wp_fchub_membership_plan_rules',
                        'REFERENCED_COLUMN_NAME' => 'id',
                        'DELETE_RULE' => $missing === 'foreign_key_definition' ? 'SET NULL' : 'CASCADE',
                    ],
                ];
                if ($missing === 'foreign_key') {
                    $foreignKeys = array_values(array_filter(
                        $foreignKeys,
                        static fn(array $foreignKey): bool => $foreignKey['CONSTRAINT_NAME'] !== 'fk_drip_rule'
                    ));
                }

                return $foreignKeys;
            }
            return [];
        };
    }

    private static function v5ColumnDefinition(string $query, string $column): ?array
    {
        if (!str_contains($query, 'entitlement_edges') && !str_contains($query, 'provider_operations')) {
            return null;
        }

        $definitions = str_contains($query, 'entitlement_edges')
            ? [
                'id' => ['bigint unsigned', 'NO', null], 'user_id' => ['bigint unsigned', 'NO', null],
                'provider' => ['varchar(50)', 'NO', null], 'resource_type' => ['varchar(50)', 'NO', null],
                'resource_id' => ['varchar(100)', 'NO', null], 'plan_id' => ['bigint unsigned', 'NO', '0'],
                'feed_id' => ['bigint unsigned', 'NO', '0'], 'feed_scope' => ['varchar(20)', 'NO', 'external_unknown'],
                'source_type' => ['varchar(30)', 'NO', null], 'source_id' => ['bigint unsigned', 'NO', '0'],
                'owner' => ['varchar(20)', 'NO', 'external_unknown'],
                'assignment_provenance' => ['varchar(20)', 'NO', 'unknown'],
                'lifecycle' => ['varchar(20)', 'NO', 'active'],
                'access_status' => ['varchar(20)', 'NO', 'active'],
                'starts_at' => ['datetime', 'YES', null],
                'expires_at' => ['datetime', 'YES', null], 'drip_available_at' => ['datetime', 'YES', null],
                'ended_at' => ['datetime', 'YES', null], 'end_reason' => ['varchar(191)', 'YES', null],
                'policy' => ['longtext', 'YES', null], 'created_at' => ['datetime', 'NO', null],
                'updated_at' => ['datetime', 'NO', null],
            ]
            : [
                'id' => ['bigint unsigned', 'NO', null], 'edge_id' => ['bigint unsigned', 'NO', null],
                'operation_key' => ['char(64)', 'NO', null], 'desired_action' => ['varchar(30)', 'NO', null],
                'origin_event' => ['varchar(100)', 'NO', null], 'state' => ['varchar(20)', 'NO', 'pending'],
                'lease_owner' => ['varchar(64)', 'YES', null], 'lease_expires_at' => ['datetime', 'YES', null],
                'attempt_count' => ['int unsigned', 'NO', '0'], 'retryable' => ['tinyint(1)', 'NO', '1'],
                'next_retry_at' => ['datetime', 'YES', null], 'last_error_code' => ['varchar(100)', 'YES', null],
                'last_error_message' => ['varchar(500)', 'YES', null], 'eligible_at' => ['datetime', 'YES', null],
                'created_at' => ['datetime', 'NO', null], 'updated_at' => ['datetime', 'NO', null],
                'completed_at' => ['datetime', 'YES', null],
            ];

        if (!isset($definitions[$column])) {
            return ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null];
        }

        [$type, $nullability, $default] = $definitions[$column];
        return ['Type' => $type, 'Null' => $nullability, 'Default' => $default];
    }

    private static function v6ColumnDefinition(string $query, string $column): ?array
    {
        if (!str_contains($query, 'crm_projection_jobs')) {
            return null;
        }

        $definitions = [
            'user_id' => ['bigint unsigned', 'NO', null],
            'status' => ['varchar(20)', 'NO', 'pending'],
            'request_version' => ['bigint unsigned', 'NO', '1'],
            'lease_owner' => ['varchar(64)', 'YES', null],
            'lease_expires_at' => ['datetime', 'YES', null],
            'attempt_count' => ['int unsigned', 'NO', '0'],
            'next_retry_at' => ['datetime', 'YES', null],
            'last_error_code' => ['varchar(64)', 'YES', null],
            'last_attempt_at' => ['datetime', 'YES', null],
            'last_success_at' => ['datetime', 'YES', null],
            'created_at' => ['datetime', 'NO', null],
            'updated_at' => ['datetime', 'NO', null],
        ];

        if (!isset($definitions[$column])) {
            return ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null];
        }

        [$type, $nullability, $default] = $definitions[$column];
        return ['Type' => $type, 'Null' => $nullability, 'Default' => $default];
    }

    private static function v7ColumnDefinition(string $query, string $column): ?array
    {
        if (!str_contains($query, 'mutation_requests')) {
            return null;
        }

        $definitions = [
            'id' => ['bigint unsigned', 'NO', null],
            'request_key' => ['varchar(191)', 'NO', null],
            'fingerprint' => ['char(64)', 'NO', null],
            'user_id' => ['bigint unsigned', 'NO', null],
            'state' => ['varchar(20)', 'NO', 'reserved'],
            'response_status' => ['smallint unsigned', 'YES', null],
            'response_body' => ['longtext', 'YES', null],
            'lease_token' => ['varchar(64)', 'YES', null],
            'lease_expires_at' => ['datetime', 'YES', null],
            'attempt_count' => ['int unsigned', 'NO', '1'],
            'created_at' => ['datetime', 'NO', null],
            'updated_at' => ['datetime', 'NO', null],
            'completed_at' => ['datetime', 'YES', null],
        ];

        if (!isset($definitions[$column])) {
            return ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null];
        }

        [$type, $nullability, $default] = $definitions[$column];
        return ['Type' => $type, 'Null' => $nullability, 'Default' => $default];
    }

    private static function v8ColumnDefinition(string $query, string $column): ?array
    {
        if (!str_contains($query, 'webhook_events') && !str_contains($query, 'webhook_deliveries')) {
            return null;
        }

        $definitions = str_contains($query, 'webhook_events')
            ? [
                'id' => ['bigint unsigned', 'NO', null],
                'event_id' => ['char(36)', 'NO', null],
                'event_type' => ['varchar(64)', 'NO', null],
                'schema_version' => ['varchar(10)', 'NO', '1.0'],
                'body' => ['longtext', 'NO', null],
                'occurred_at' => ['datetime', 'NO', null],
                'created_at' => ['datetime', 'NO', null],
            ]
            : [
                'id' => ['bigint unsigned', 'NO', null],
                'event_id' => ['char(36)', 'NO', null],
                'destination_url' => ['varchar(2048)', 'NO', null],
                'destination_hash' => ['char(64)', 'NO', null],
                'status' => ['varchar(20)', 'NO', 'pending'],
                'attempt_count' => ['smallint unsigned', 'NO', '0'],
                'lease_owner' => ['varchar(64)', 'YES', null],
                'lease_expires_at' => ['datetime', 'YES', null],
                'response_code' => ['smallint unsigned', 'YES', null],
                'response_body' => ['text', 'YES', null],
                'error_message' => ['text', 'YES', null],
                'next_attempt_at' => ['datetime', 'YES', null],
                'last_attempt_at' => ['datetime', 'YES', null],
                'delivered_at' => ['datetime', 'YES', null],
                'created_at' => ['datetime', 'NO', null],
                'updated_at' => ['datetime', 'NO', null],
            ];

        if (!isset($definitions[$column])) {
            return ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null];
        }

        [$type, $nullability, $default] = $definitions[$column];
        return ['Type' => $type, 'Null' => $nullability, 'Default' => $default];
    }

    /** @return array<string, list<string>> */
    private static function indexDefinitions(string $query): array
    {
        if (str_contains($query, 'webhook_events')) {
            return [
                'PRIMARY' => ['id'],
                'event_id' => ['event_id'],
                'type_occurred' => ['event_type', 'occurred_at'],
            ];
        }
        if (str_contains($query, 'webhook_deliveries')) {
            return [
                'PRIMARY' => ['id'],
                'event_destination' => ['event_id', 'destination_hash'],
                'status_next' => ['status', 'next_attempt_at'],
                'status_lease' => ['status', 'lease_expires_at'],
                'created_at' => ['created_at'],
            ];
        }

        if (str_contains($query, 'crm_projection_jobs')) {
            return [
                'PRIMARY' => ['user_id'],
                'status_due' => ['status', 'next_retry_at'],
                'status_lease' => ['status', 'lease_expires_at'],
                'last_success' => ['last_success_at'],
            ];
        }

        return [
            'PRIMARY' => ['id'],
            'slug' => ['slug'],
            'status' => ['status'],
            'level' => ['level'],
            'plan_provider_type' => ['plan_id', 'provider', 'resource_type'],
            'plan_sort' => ['plan_id', 'sort_order'],
            'grant_key' => ['grant_key'],
            'user_access' => ['user_id', 'provider', 'resource_type', 'resource_id', 'status'],
            'user_plan' => ['user_id', 'plan_id', 'status'],
            'source_lookup' => ['source_type', 'source_id'],
            'feed_id' => ['feed_id'],
            'status_expires' => ['status', 'expires_at'],
            'status_drip' => ['status', 'drip_available_at'],
            'idx_trial_ends' => ['trial_ends_at'],
            'idx_cancellation_effective' => ['cancellation_effective_at'],
            'idx_renewal_count' => ['plan_id', 'renewal_count'],
            'event_hash' => ['event_hash'],
            'idx_retry' => ['status', 'next_retry_at', 'retry_count'],
            'idx_event_lock_state_lease' => ['state', 'lease_expires_at'],
            'idx_event_lock_completed' => ['completed_at'],
            'request_key' => ['request_key'],
            'state_updated' => ['state', 'updated_at'],
            'resource_lookup' => ['resource_type', 'resource_id'],
            'subscription_id' => ['subscription_id'],
            'pending_notify' => ['status', 'notify_at'],
            'grant_id' => ['grant_id'],
            'user_id' => ['user_id'],
            'date_plan' => ['stat_date', 'plan_id'],
            'entity_lookup' => ['entity_type', 'entity_id'],
            'actor_lookup' => ['actor_id', 'actor_type'],
            'created_at' => ['created_at'],
            'grant_source' => ['grant_id', 'source_type', 'source_id'],
            'entitlement_identity' => ['user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id', 'feed_scope', 'source_type', 'source_id'],
            'active_resource' => ['user_id', 'provider', 'resource_type', 'resource_id', 'lifecycle'],
            'source_lifecycle' => ['source_type', 'source_id', 'lifecycle'],
            'plan_feed_lifecycle' => ['plan_id', 'feed_id', 'feed_scope', 'lifecycle'],
            'lifecycle_expires' => ['lifecycle', 'expires_at'],
            'lifecycle_drip' => ['lifecycle', 'drip_available_at'],
            'lifecycle_ended' => ['lifecycle', 'ended_at'],
            'plan_access_lifecycle_user' => ['plan_id', 'access_status', 'lifecycle', 'user_id'],
            'operation_key' => ['operation_key'],
            'edge_state' => ['edge_id', 'state'],
            'state_due' => ['state', 'retryable', 'next_retry_at'],
            'state_lease' => ['state', 'lease_expires_at'],
            'state_eligible' => ['state', 'eligible_at'],
            'completed_at' => ['completed_at'],
            'retention_completed' => ['completed_at', 'state', 'id'],
        ];
    }

    private function allRequiredColumns(): array
    {
        return [
            'id',
            'title',
            'slug',
            'description',
            'status',
            'level',
            'includes_plan_ids',
            'restriction_message',
            'redirect_url',
            'plan_id',
            'grant_id',
            'user_id',
            'provider',
            'resource_type',
            'resource_id',
            'drip_delay_days',
            'drip_type',
            'drip_date',
            'sort_order',
            'meta',
            'source_type',
            'source_id',
            'feed_id',
            'grant_key',
            'starts_at',
            'expires_at',
            'drip_available_at',
            'source_ids',
            'request_key',
            'fingerprint',
            'response_status',
            'response_body',
            'plan_ids',
            'protection_mode',
            'show_teaser',
            'entity_type',
            'entity_id',
            'action',
            'actor_id',
            'actor_type',
            'old_value',
            'new_value',
            'context',
            'stat_date',
            'subscription_id',
            'last_valid_at',
            'expired_at',
            'dispatched_at',
            'duration_type',
            'duration_days',
            'trial_days',
            'grace_period_days',
            'settings',
            'scheduled_status',
            'scheduled_at',
            'trial_ends_at',
            'cancellation_requested_at',
            'cancellation_effective_at',
            'cancellation_reason',
            'renewal_count',
            'plan_rule_id',
            'notify_at',
            'sent_at',
            'retry_count',
            'next_retry_at',
            'active_count',
            'new_count',
            'churned_count',
            'revenue',
            'event_hash',
            'order_id',
            'trigger_name',
            'processed_at',
            'result',
            'error',
            'state',
            'owner_token',
            'lease_expires_at',
            'attempt_count',
            'retryable',
            'updated_at',
            'completed_at',
            'last_error',
            'created_at',
            'feed_scope',
            'owner',
            'assignment_provenance',
            'lifecycle',
            'access_status',
            'ended_at',
            'end_reason',
            'policy',
            'edge_id',
            'operation_key',
            'desired_action',
            'origin_event',
            'lease_owner',
            'last_error_code',
            'last_error_message',
            'eligible_at',
            'request_version',
            'last_attempt_at',
            'last_success_at',
            'lease_token',
            'event_id',
            'event_type',
            'schema_version',
            'body',
            'occurred_at',
            'destination_url',
            'destination_hash',
            'response_code',
            'error_message',
            'next_attempt_at',
            'delivered_at',
        ];
    }
}
