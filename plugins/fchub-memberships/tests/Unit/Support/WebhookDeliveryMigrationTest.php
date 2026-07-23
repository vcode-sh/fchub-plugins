<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Http\AccessApiCredential;
use FChubMemberships\Support\MigrationV8;
use FChubMemberships\Support\Migrations;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookDeliveryMigrationTest extends PluginTestCase
{
    public function test_v8_declares_the_exact_event_and_leased_delivery_tables(): void
    {
        MigrationV8::run();

        $schema = implode("\n", $GLOBALS['_fchub_test_dbdelta']);
        foreach ([
            'CREATE TABLE wp_fchub_membership_webhook_events',
            'event_id CHAR(36) NOT NULL',
            'event_type VARCHAR(64) NOT NULL',
            "schema_version VARCHAR(10) NOT NULL DEFAULT '1.0'",
            'body LONGTEXT NOT NULL',
            'occurred_at DATETIME NOT NULL',
            'UNIQUE KEY event_id (event_id)',
            'KEY type_occurred (event_type, occurred_at)',
            'CREATE TABLE wp_fchub_membership_webhook_deliveries',
            'destination_url VARCHAR(2048) NOT NULL',
            'destination_hash CHAR(64) NOT NULL',
            "status VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0',
            'lease_owner VARCHAR(64) NULL',
            'lease_expires_at DATETIME NULL',
            'response_code SMALLINT UNSIGNED NULL',
            'response_body TEXT NULL',
            'error_message TEXT NULL',
            'next_attempt_at DATETIME NULL',
            'last_attempt_at DATETIME NULL',
            'delivered_at DATETIME NULL',
            'UNIQUE KEY event_destination (event_id, destination_hash)',
            'KEY status_next (status, next_attempt_at)',
            'KEY status_lease (status, lease_expires_at)',
            'KEY created_at (created_at)',
        ] as $definition) {
            self::assertStringContainsString($definition, $schema);
        }
        self::assertStringNotContainsString('FOREIGN KEY', $schema);
        self::assertStringNotContainsString('DROP TABLE', $schema);
    }

    public function test_v8_replay_migrates_plaintext_once_without_advancing_the_version(): void
    {
        $secret = str_repeat('L', 40);
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'api_key' => $secret,
            'webhook_enabled' => 'no',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = '1.7.0';

        self::assertSame([], MigrationV8::run());
        $first = $GLOBALS['_fchub_test_options']['fchub_memberships_settings'];
        self::assertSame([], MigrationV8::run());
        $second = $GLOBALS['_fchub_test_options']['fchub_memberships_settings'];

        self::assertSame($first, $second);
        self::assertTrue(AccessApiCredential::verify($secret, $second));
        self::assertArrayNotHasKey('api_key', $second);
        self::assertSame('no', $second['webhook_enabled']);
        self::assertSame('1.7.0', $GLOBALS['_fchub_test_options']['fchub_memberships_db_version']);
        self::assertCount(4, $GLOBALS['_fchub_test_dbdelta']);
    }

    public function test_v8_fails_closed_when_credential_migration_cannot_lock(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['api_key' => str_repeat('K', 40)];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): mixed =>
            str_contains($query, 'GET_LOCK(') ? 0 : null;

        $failures = MigrationV8::run();

        self::assertContains('settings:access_api_credential lock_unavailable', $failures);
        self::assertArrayHasKey('api_key', $GLOBALS['_fchub_test_options']['fchub_memberships_settings']);
    }

    public function test_shared_postconditions_and_drop_all_cover_both_tables_in_dependency_order(): void
    {
        $failures = Migrations::verifySchema();
        self::assertContains('table:webhook_events missing', $failures);
        self::assertContains('table:webhook_deliveries missing', $failures);

        Migrations::dropAll();
        $drops = array_values(array_map(
            static fn(array $entry): string => $entry[1],
            array_filter(
                $GLOBALS['_fchub_test_queries'],
                static fn(array $entry): bool => $entry[0] === 'query' && str_contains($entry[1], 'DROP TABLE')
            )
        ));

        $deliveryDrop = array_search(
            'DROP TABLE IF EXISTS wp_fchub_membership_webhook_deliveries',
            $drops,
            true
        );
        $eventDrop = array_search(
            'DROP TABLE IF EXISTS wp_fchub_membership_webhook_events',
            $drops,
            true
        );
        self::assertIsInt($deliveryDrop);
        self::assertIsInt($eventDrop);
        self::assertLessThan($eventDrop, $deliveryDrop);
        self::assertStringNotContainsString('event_locks', implode("\n", $GLOBALS['_fchub_test_dbdelta']));
        self::assertSame('1.9.0', FCHUB_MEMBERSHIPS_DB_VERSION);
    }

    public function test_shared_verification_enforces_v8_types_widths_defaults_and_nullability(): void
    {
        $columns = $this->webhookColumns();
        $this->installWebhookMetadata($columns);

        $correctFailures = array_values(array_filter(
            Migrations::verifySchema(),
            static fn(string $failure): bool => str_starts_with($failure, 'column:webhook_')
        ));
        self::assertSame([], $correctFailures);

        foreach ([
            ['webhook_events', 'body', 'Type', 'text'],
            ['webhook_deliveries', 'destination_url', 'Type', 'varchar(191)'],
            ['webhook_deliveries', 'response_body', 'Type', 'longtext'],
            ['webhook_deliveries', 'lease_owner', 'Null', 'NO'],
            ['webhook_deliveries', 'status', 'Default', null],
            ['webhook_deliveries', 'attempt_count', 'Default', '1'],
        ] as [$table, $column, $property, $wrongValue]) {
            $wrong = $columns;
            $wrong[$table][$column][$property] = $wrongValue;
            $this->installWebhookMetadata($wrong);

            self::assertContains(
                "column:{$table}.{$column} expected " . $this->expectedDescription($table, $column),
                Migrations::verifySchema()
            );
        }
    }

    public function test_webhook_only_verification_fails_closed_on_partial_v8_schema(): void
    {
        $columns = $this->webhookColumns();
        unset($columns['webhook_deliveries']['destination_hash']);
        $this->installWebhookMetadata($columns);

        self::assertContains(
            'column:webhook_deliveries.destination_hash missing',
            Migrations::verifyWebhookSchema()
        );

        $this->installWebhookMetadata($this->webhookColumns());
        self::assertSame([], Migrations::verifyWebhookSchema());
    }

    /** @return array<string, array<string, array{Field:string, Type:string, Null:string, Default:?string}>> */
    private function webhookColumns(): array
    {
        $definitions = [
            'webhook_events' => [
                'id' => ['bigint unsigned', 'NO', null],
                'event_id' => ['char(36)', 'NO', null],
                'event_type' => ['varchar(64)', 'NO', null],
                'schema_version' => ['varchar(10)', 'NO', '1.0'],
                'body' => ['longtext', 'NO', null],
                'occurred_at' => ['datetime', 'NO', null],
                'created_at' => ['datetime', 'NO', null],
            ],
            'webhook_deliveries' => [
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
            ],
        ];
        $columns = [];
        foreach ($definitions as $table => $tableDefinitions) {
            foreach ($tableDefinitions as $field => [$type, $null, $default]) {
                $columns[$table][$field] = [
                    'Field' => $field,
                    'Type' => $type,
                    'Null' => $null,
                    'Default' => $default,
                ];
            }
        }

        return $columns;
    }

    /** @param array<string, array<string, array{Field:string, Type:string, Null:string, Default:?string}>> $columns */
    private function installWebhookMetadata(array $columns): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): string|int|null {
            foreach (['webhook_events', 'webhook_deliveries'] as $table) {
                if (str_contains($query, "SHOW TABLES LIKE 'wp_fchub_membership_{$table}'")) {
                    return "wp_fchub_membership_{$table}";
                }
            }

            return str_contains($query, 'SELECT COUNT(*)') ? 0 : null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $query) use ($columns): array {
            foreach (['webhook_events', 'webhook_deliveries'] as $table) {
                if (str_contains($query, "SHOW TABLE STATUS LIKE 'wp_fchub_membership_{$table}'")) {
                    return [['Name' => "wp_fchub_membership_{$table}", 'Engine' => 'InnoDB']];
                }
                if (str_contains($query, "SHOW COLUMNS FROM wp_fchub_membership_{$table}")) {
                    return array_values($columns[$table]);
                }
                if (str_contains($query, "SHOW INDEX FROM wp_fchub_membership_{$table}")) {
                    return $this->webhookIndexes($table);
                }
            }

            return [];
        };
    }

    /** @return list<array{Key_name:string, Column_name:string, Seq_in_index:int, Non_unique:int}> */
    private function webhookIndexes(string $table): array
    {
        $definitions = $table === 'webhook_events'
            ? ['PRIMARY' => [0, ['id']], 'event_id' => [0, ['event_id']], 'type_occurred' => [1, ['event_type', 'occurred_at']]]
            : [
                'PRIMARY' => [0, ['id']],
                'event_destination' => [0, ['event_id', 'destination_hash']],
                'status_next' => [1, ['status', 'next_attempt_at']],
                'status_lease' => [1, ['status', 'lease_expires_at']],
                'created_at' => [1, ['created_at']],
            ];
        $rows = [];
        foreach ($definitions as $name => [$nonUnique, $indexColumns]) {
            foreach ($indexColumns as $position => $column) {
                $rows[] = [
                    'Key_name' => $name,
                    'Column_name' => $column,
                    'Seq_in_index' => $position + 1,
                    'Non_unique' => $nonUnique,
                ];
            }
        }

        return $rows;
    }

    private function expectedDescription(string $table, string $column): string
    {
        $metadata = $this->webhookColumns()[$table][$column];
        $type = $metadata['Type'];
        $nullability = $metadata['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $metadata['Default'] ?? 'NULL';

        return "{$type} {$nullability} default {$default}";
    }
}
