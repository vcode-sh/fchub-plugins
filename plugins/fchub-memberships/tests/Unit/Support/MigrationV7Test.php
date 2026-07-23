<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\MigrationV7;
use FChubMemberships\Support\Migrations;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MigrationV7Test extends PluginTestCase
{
    public function test_empty_install_declares_the_exact_mutation_receipt_lease_schema(): void
    {
        MigrationV7::run();

        $schema = implode("\n", $GLOBALS['_fchub_test_dbdelta']);
        foreach ([
            'CREATE TABLE wp_fchub_membership_mutation_requests',
            'request_key VARCHAR(191) NOT NULL',
            'fingerprint CHAR(64) NOT NULL',
            'user_id BIGINT UNSIGNED NOT NULL',
            "state VARCHAR(20) NOT NULL DEFAULT 'reserved'",
            'response_status SMALLINT UNSIGNED NULL',
            'response_body LONGTEXT NULL',
            'lease_token VARCHAR(64) NULL',
            'lease_expires_at DATETIME NULL',
            'attempt_count INT UNSIGNED NOT NULL DEFAULT 1',
            'created_at DATETIME NOT NULL',
            'updated_at DATETIME NOT NULL',
            'completed_at DATETIME NULL',
            'UNIQUE KEY request_key (request_key)',
            'KEY state_updated (state, updated_at)',
            'KEY state_lease (state, lease_expires_at)',
            'KEY retention_completed (completed_at, state, id)',
        ] as $definition) {
            self::assertStringContainsString($definition, $schema);
        }
        self::assertStringNotContainsString('DROP TABLE', $schema);
    }

    public function test_v7_replay_is_additive(): void
    {
        MigrationV7::run();
        MigrationV7::run();

        $schemas = array_values(array_filter(
            $GLOBALS['_fchub_test_dbdelta'],
            static fn(string $schema): bool => str_contains($schema, 'mutation_requests')
        ));

        self::assertCount(2, $schemas);
        self::assertStringNotContainsString('DROP TABLE', implode("\n", $schemas));
    }

    public function test_shared_postcondition_requires_v7_columns_and_indexes(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): string|int|null {
            if (str_contains($query, 'SHOW TABLES LIKE') && str_contains($query, 'mutation')) {
                return 'wp_fchub_membership_mutation_requests';
            }

            return null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            if (str_contains($query, 'SHOW TABLE STATUS') && str_contains($query, 'mutation')) {
                return [['Name' => 'wp_fchub_membership_mutation_requests', 'Engine' => 'InnoDB']];
            }
            if (str_contains($query, 'SHOW COLUMNS FROM') && str_contains($query, 'mutation')) {
                return array_map(
                    static fn(string $column): array => [
                        'Field' => $column,
                        'Type' => 'bigint unsigned',
                        'Null' => 'YES',
                        'Default' => null,
                    ],
                    [
                        'id', 'request_key', 'fingerprint', 'user_id', 'state', 'response_status',
                        'response_body', 'created_at', 'updated_at', 'completed_at',
                    ]
                );
            }
            if (str_contains($query, 'SHOW INDEX FROM') && str_contains($query, 'mutation')) {
                return [];
            }

            return [];
        };

        $failures = Migrations::verifySchema();

        self::assertContains('column:mutation_requests.lease_token missing', $failures);
        self::assertContains('column:mutation_requests.lease_expires_at missing', $failures);
        self::assertContains('column:mutation_requests.attempt_count missing', $failures);
        self::assertContains('index:mutation_requests.state_lease missing', $failures);
        self::assertContains('index:mutation_requests.retention_completed missing', $failures);
    }

    public function test_database_version_targets_v7(): void
    {
        self::assertSame('1.9.0', FCHUB_MEMBERSHIPS_DB_VERSION);
    }
}
