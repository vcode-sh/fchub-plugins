<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\FluentCrmProjectionJobRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FluentCrmProjectionJobRepositoryTest extends PluginTestCase
{
    public function test_repository_exposes_only_the_bounded_status_and_error_domains(): void
    {
        self::assertSame(
            ['pending', 'processing', 'succeeded', 'failed'],
            FluentCrmProjectionJobRepository::STATUSES
        );
        self::assertSame([
            'projection_invalid_user',
            'projection_load_failed',
            'projection_contact_failed',
            'projection_tag_failed',
            'projection_relation_read_failed',
            'projection_relation_write_failed',
            'projection_state_commit_failed',
            'projection_compensation_failed',
            'projection_custom_fields_failed',
            'projection_postflight_failed',
            'projection_unexpected_failure',
            'projection_retry_exhausted',
        ], FluentCrmProjectionJobRepository::ERROR_CODES);
    }

    public function test_new_lifecycle_request_atomically_increments_version_and_reopens_the_job(): void
    {
        $row = $this->row([
            'status' => 'failed',
            'request_version' => 4,
            'attempt_count' => 4,
            'last_success_at' => '2026-03-01 09:00:00',
        ]);
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql, \wpdb $wpdb) use (&$row, &$query): int {
            $query = $sql;
            $row['status'] = 'pending';
            $row['request_version']++;
            $row['attempt_count'] = 0;
            $row['next_retry_at'] = null;
            $row['last_error_code'] = null;
            return $wpdb->rows_affected = 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };

        $requested = $this->repository()->request(21);

        self::assertSame(5, $requested['request_version']);
        self::assertSame('pending', $requested['status']);
        self::assertSame(0, $requested['attempt_count']);
        self::assertSame('2026-03-01 09:00:00', $requested['last_success_at']);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $query);
        self::assertStringContainsString('request_version = request_version + 1', $query);
        self::assertStringNotContainsString('last_success_at =', $query);
    }

    public function test_claim_requires_exact_version_and_attempt_and_takes_a_five_minute_lease(): void
    {
        $row = $this->row();
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql, \wpdb $wpdb) use (&$row, &$query): int {
            $query = $sql;
            $row['status'] = 'processing';
            $row['lease_owner'] = 'worker-a';
            $row['lease_expires_at'] = '2026-03-14 12:35:00';
            $row['attempt_count'] = 1;
            return $wpdb->rows_affected = 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };

        $claimed = $this->repository()->claim(21, 1, 1, 'worker-a');

        self::assertSame('processing', $claimed['status']);
        self::assertSame(1, $claimed['attempt_count']);
        self::assertStringContainsString('request_version = 1', $query);
        self::assertStringContainsString('attempt_count = 0', $query);
        self::assertStringContainsString("lease_expires_at = '2026-03-14 12:35:00'", $query);
        self::assertStringContainsString('lease_expires_at IS NULL', $query);
        self::assertStringContainsString("lease_expires_at = '0000-00-00 00:00:00'", $query);
        self::assertStringContainsString("lease_expires_at <= '2026-03-14 12:30:00'", $query);
    }

    public function test_claim_loss_returns_null_instead_of_using_a_stale_row(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(): int => 0;

        self::assertNull($this->repository()->claim(21, 1, 1, 'worker-a'));
    }

    public function test_stale_processing_claim_reuses_the_same_attempt_number(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql, \wpdb $wpdb) use (&$query): int {
            $query = $sql;
            return $wpdb->rows_affected = 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(): array => $this->row([
            'status' => 'processing',
            'attempt_count' => 4,
        ]);

        $this->repository()->claim(21, 3, 4, 'worker-b');

        self::assertMatchesRegularExpression(
            "/status = 'pending'\\s+AND attempt_count = 3/s",
            $query
        );
        self::assertMatchesRegularExpression(
            "/status = 'processing'\\s+AND attempt_count = 4/s",
            $query
        );
    }

    public function test_success_completion_is_an_exact_live_lease_and_version_cas(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql, \wpdb $wpdb) use (&$query): int {
            $query = $sql;
            return $wpdb->rows_affected = str_contains($sql, 'request_version = 5') ? 1 : 0;
        };

        self::assertTrue($this->repository()->completeSuccess(21, 5, 2, 'worker-a'));
        self::assertFalse($this->repository()->completeSuccess(21, 4, 2, 'worker-a'));
        self::assertStringContainsString("status = 'processing'", $query);
        self::assertStringContainsString("lease_owner = 'worker-a'", $query);
        self::assertStringContainsString("lease_expires_at > '2026-03-14 12:30:00'", $query);
        self::assertStringContainsString('attempt_count = 2', $query);
    }

    #[DataProvider('failureSchedule')]
    public function test_failure_retry_schedule_is_bounded_and_fourth_failure_is_terminal(
        int $attempt,
        string $expectedStatus,
        ?string $expectedRetry,
        string $expectedCode
    ): void {
        $row = $this->row(['status' => 'processing', 'attempt_count' => $attempt]);
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql, \wpdb $wpdb) use (&$row, &$query): int {
            $query = $sql;
            $row['status'] = str_contains($sql, "status = 'failed'") ? 'failed' : 'pending';
            return $wpdb->rows_affected = 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };

        $recorded = $this->repository()->completeFailure(
            21,
            3,
            $attempt,
            'worker-a',
            'projection_contact_failed'
        );

        self::assertSame($expectedStatus, $recorded['status']);
        self::assertStringContainsString("last_error_code = '{$expectedCode}'", $query);
        self::assertStringContainsString(
            $expectedRetry === null ? 'next_retry_at = NULL' : "next_retry_at = '{$expectedRetry}'",
            $query
        );
    }

    public static function failureSchedule(): array
    {
        return [
            'attempt one' => [1, 'pending', '2026-03-14 12:35:00', 'projection_contact_failed'],
            'attempt two' => [2, 'pending', '2026-03-14 13:00:00', 'projection_contact_failed'],
            'attempt three' => [3, 'pending', '2026-03-14 14:30:00', 'projection_contact_failed'],
            'attempt four' => [4, 'failed', null, 'projection_retry_exhausted'],
        ];
    }

    public function test_unrecognised_error_codes_are_reduced_to_unexpected(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql, \wpdb $wpdb) use (&$query): int {
            $query = $sql;
            return $wpdb->rows_affected = 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(): array => $this->row(['status' => 'pending']);

        $this->repository()->completeFailure(21, 1, 1, 'worker-a', 'secret_token=do-not-store');

        self::assertStringContainsString("last_error_code = 'projection_unexpected_failure'", $query);
        self::assertStringNotContainsString('secret_token', $query);
    }

    public function test_recovery_query_is_capped_and_includes_null_zero_and_expired_leases(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(): array => [
            $this->row(['user_id' => 21]),
            $this->row(['user_id' => 22, 'status' => 'processing', 'attempt_count' => 1]),
        ];

        $rows = $this->repository()->findRecoverable(99);

        self::assertSame([21, 22], array_column($rows, 'user_id'));
        $evidence = serialize($GLOBALS['_fchub_test_queries']);
        self::assertStringContainsString("status = 'pending'", $evidence);
        self::assertStringContainsString("status = 'processing'", $evidence);
        self::assertStringContainsString('lease_expires_at IS NULL', $evidence);
        self::assertStringContainsString("lease_expires_at = '0000-00-00 00:00:00'", $evidence);
        self::assertStringContainsString('LIMIT 50', $evidence);
    }

    public function test_health_summary_reports_pending_failed_and_latest_success_only(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => [
            'pending' => '3',
            'failed' => '2',
            'last_success_at' => '2026-03-14 12:20:00',
        ];

        self::assertSame([
            'pending' => 3,
            'failed' => 2,
            'last_success_at' => '2026-03-14 12:20:00',
        ], $this->repository()->summary());
    }

    private function repository(): FluentCrmProjectionJobRepository
    {
        return new FluentCrmProjectionJobRepository(new Clock(
            new \DateTimeImmutable('2026-03-14 12:30:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        ));
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'user_id' => 21,
            'status' => 'pending',
            'request_version' => 1,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'attempt_count' => 0,
            'next_retry_at' => null,
            'last_error_code' => null,
            'last_attempt_at' => null,
            'last_success_at' => null,
            'created_at' => '2026-03-14 12:30:00',
            'updated_at' => '2026-03-14 12:30:00',
        ], $overrides);
    }
}
