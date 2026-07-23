<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\MigrationV6;
use FChubMemberships\Support\Migrations;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MigrationV6Test extends PluginTestCase
{
    public function test_empty_install_creates_the_exact_crm_projection_job_table(): void
    {
        MigrationV6::run();

        $schema = implode("\n", $GLOBALS['_fchub_test_dbdelta']);
        self::assertStringContainsString('CREATE TABLE wp_fchub_membership_crm_projection_jobs', $schema);
        foreach ([
            'user_id BIGINT UNSIGNED NOT NULL',
            "status VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'request_version BIGINT UNSIGNED NOT NULL DEFAULT 1',
            'lease_owner VARCHAR(64) NULL',
            'lease_expires_at DATETIME NULL',
            'attempt_count INT UNSIGNED NOT NULL DEFAULT 0',
            'next_retry_at DATETIME NULL',
            'last_error_code VARCHAR(64) NULL',
            'last_attempt_at DATETIME NULL',
            'last_success_at DATETIME NULL',
            'created_at DATETIME NOT NULL',
            'updated_at DATETIME NOT NULL',
            'PRIMARY KEY (user_id)',
            'KEY status_due (status, next_retry_at)',
            'KEY status_lease (status, lease_expires_at)',
            'KEY last_success (last_success_at)',
        ] as $definition) {
            self::assertStringContainsString($definition, $schema);
        }
        self::assertStringNotContainsString('payload', strtolower($schema));
        self::assertStringNotContainsString('json', strtolower($schema));
        self::assertStringNotContainsString('FOREIGN KEY', $schema);
    }

    public function test_v6_replay_is_additive_and_never_drops_the_table(): void
    {
        MigrationV6::run();
        MigrationV6::run();

        $schemas = array_values(array_filter(
            $GLOBALS['_fchub_test_dbdelta'],
            static fn(string $schema): bool => str_contains($schema, 'crm_projection_jobs')
        ));

        self::assertCount(2, $schemas);
        self::assertStringNotContainsString('DROP TABLE', implode("\n", $schemas));
    }

    public function test_shared_postcondition_rejects_missing_v6_table(): void
    {
        self::assertContains(
            'table:crm_projection_jobs missing',
            Migrations::verifySchema()
        );
    }

    public function test_database_version_targets_v6(): void
    {
        self::assertSame('1.9.0', FCHUB_MEMBERSHIPS_DB_VERSION);
    }
}
