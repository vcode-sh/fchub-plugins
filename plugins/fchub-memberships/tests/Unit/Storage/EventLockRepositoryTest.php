<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Domain\Event\EventClaimResult;
use FChubMemberships\Storage\EventLockRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class EventLockRepositoryTest extends PluginTestCase
{
    public function test_first_claim_starts_processing_lease(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'A first claim must use the atomic lease API.');
        $this->installFirstClaimWrite();

        $result = $repository->claim('event-first', $this->context(), 'owner-a', 300);

        $this->assertOutcome($result, 'acquired');
        $evidence = $this->queryEvidence();
        self::assertStringContainsString('processing', $evidence);
        self::assertStringContainsString('owner-a', $evidence);
        self::assertStringContainsString('2026-03-14 12:35:00', $evidence);
        self::assertStringNotContainsString("'success'", $evidence);
        self::assertGreaterThanOrEqual(2, substr_count($evidence, "'processing'"));
    }

    public function test_inserted_claim_restores_error_reporting_after_the_suppressed_insert(): void
    {
        $originalWpdb = $GLOBALS['wpdb'];
        $trackingWpdb = new class extends \wpdb {
            /** @var list<bool> */
            public array $suppressionCalls = [];

            public function suppress_errors(bool $suppress = true): bool
            {
                $this->suppressionCalls[] = $suppress;

                return parent::suppress_errors($suppress);
            }
        };
        $GLOBALS['wpdb'] = $trackingWpdb;
        $GLOBALS['_fchub_test_wpdb_suppress_errors'] = false;

        try {
            $repository = $this->repository();
            $this->installFirstClaimWrite();

            $result = $repository->claim('event-suppressed-insert', $this->context(), 'owner-a', 300);

            $this->assertOutcome($result, 'acquired');
            self::assertSame([true, false], $trackingWpdb->suppressionCalls);
            self::assertFalse($GLOBALS['_fchub_test_wpdb_suppress_errors']);
        } finally {
            $GLOBALS['wpdb'] = $originalWpdb;
            $GLOBALS['_fchub_test_wpdb_suppress_errors'] = false;
        }
    }

    public function test_second_claim_during_live_processing_reports_in_progress(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'A live lease must report in_progress through claim().');
        $row = $this->row([
            'state' => 'processing',
            'owner_token' => 'owner-a',
            'lease_expires_at' => '2026-03-14 12:35:00',
        ]);
        $this->installExistingRow($row);

        $result = $repository->claim('event-live', $this->context(), 'owner-b', 300);

        $this->assertOutcome($result, 'in_progress');
        self::assertStringNotContainsString('owner_token = \'owner-b\'', $this->queryEvidence());
    }

    public function test_succeeded_claim_reports_duplicate_succeeded(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'A completed lease must report duplicate_succeeded through claim().');
        $row = $this->row([
            'state' => 'succeeded',
            'owner_token' => null,
            'lease_expires_at' => null,
            'completed_at' => '2026-03-14 12:29:00',
        ]);
        $this->installExistingRow($row);

        $result = $repository->claim('event-succeeded', $this->context(), 'owner-b', 300);

        $this->assertOutcome($result, 'duplicate_succeeded');
    }

    public function test_duplicate_claim_restores_the_previously_suppressed_error_state(): void
    {
        $originalWpdb = $GLOBALS['wpdb'];
        $trackingWpdb = new class extends \wpdb {
            /** @var list<bool> */
            public array $suppressionCalls = [];

            public function suppress_errors(bool $suppress = true): bool
            {
                $this->suppressionCalls[] = $suppress;

                return parent::suppress_errors($suppress);
            }
        };
        $GLOBALS['wpdb'] = $trackingWpdb;
        $GLOBALS['_fchub_test_wpdb_suppress_errors'] = true;
        $row = $this->row([
            'state' => 'succeeded',
            'owner_token' => null,
            'lease_expires_at' => null,
            'completed_at' => '2026-03-14 12:29:00',
        ]);

        try {
            $repository = $this->repository();
            $this->installExistingRow($row);

            $result = $repository->claim('event-suppressed-duplicate', $this->context(), 'owner-b', 300);

            $this->assertOutcome($result, 'duplicate_succeeded');
            self::assertSame([true, true], $trackingWpdb->suppressionCalls);
            self::assertTrue($GLOBALS['_fchub_test_wpdb_suppress_errors']);
        } finally {
            $GLOBALS['wpdb'] = $originalWpdb;
            $GLOBALS['_fchub_test_wpdb_suppress_errors'] = false;
        }
    }

    public function test_retryable_failure_can_be_reclaimed_atomically(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'A due retryable failure must be reclaimable through claim().');
        $row = $this->row([
            'state' => 'failed',
            'owner_token' => 'owner-a',
            'retryable' => 1,
            'next_retry_at' => '2026-03-14 12:29:00',
        ]);
        $this->installExistingRow($row, takeoverOwner: 'owner-b');

        $result = $repository->claim('event-failed', $this->context(), 'owner-b', 300);

        $this->assertOutcome($result, 'acquired');
        $evidence = $this->queryEvidence();
        self::assertStringContainsString("state = 'failed'", $evidence);
        self::assertStringContainsString("owner_token = 'owner-a'", $evidence);
        self::assertStringContainsString('attempt_count = 1', $evidence);
        self::assertStringContainsString('retryable', $evidence);
        self::assertStringContainsString("next_retry_at = '2026-03-14 12:29:00'", $evidence);
        self::assertStringContainsString("result = 'processing'", $evidence);
    }

    public function test_expired_processing_lease_can_be_reclaimed_atomically(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'An expired processing lease must be reclaimable through claim().');
        $row = $this->row([
            'state' => 'processing',
            'owner_token' => 'owner-a',
            'lease_expires_at' => '2026-03-14 12:29:59',
        ]);
        $this->installExistingRow($row, takeoverOwner: 'owner-b');

        $result = $repository->claim('event-expired', $this->context(), 'owner-b', 300);

        $this->assertOutcome($result, 'acquired');
        $evidence = $this->queryEvidence();
        self::assertStringContainsString("state = 'processing'", $evidence);
        self::assertStringContainsString('owner-a', $evidence);
        self::assertStringContainsString('attempt_count = 1', $evidence);
        self::assertStringContainsString("lease_expires_at = '2026-03-14 12:29:59'", $evidence);
        self::assertStringContainsString("result = 'processing'", $evidence);
    }

    public function test_previous_owner_cannot_complete_reclaimed_lease(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('succeed', 'Lease completion must be conditional on owner_token.');
        $this->installOwnerConditionalCompletion('owner-b');

        self::assertFalse($repository->succeed('event-reclaimed', 'owner-a'));
        self::assertTrue($repository->succeed('event-reclaimed', 'owner-b'));

        $evidence = $this->queryEvidence();
        self::assertStringContainsString('event-reclaimed', $evidence);
        self::assertStringContainsString('owner-a', $evidence);
        self::assertStringContainsString('owner-b', $evidence);
    }

    public function test_retryable_failure_not_due_reports_retryable_failed_without_takeover(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'A future retry must remain unclaimed.');
        $row = $this->row([
            'state' => 'failed',
            'owner_token' => null,
            'retryable' => 1,
            'next_retry_at' => '2026-03-14 12:31:00',
        ]);
        $this->installExistingRow($row);

        $result = $repository->claim('event-not-due', $this->context(), 'owner-b', 300);

        $this->assertOutcome($result, 'retryable_failed');
        self::assertStringNotContainsString('attempt_count = attempt_count + 1', $this->queryEvidence());
    }

    public function test_retryable_failure_with_null_retry_time_is_not_reclaimed(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'A null retry time must not be guessed as due.');
        $row = $this->row([
            'state' => 'failed',
            'owner_token' => null,
            'retryable' => 1,
            'next_retry_at' => null,
        ]);
        $this->installExistingRow($row);

        $result = $repository->claim('event-null-retry', $this->context(), 'owner-b', 300);

        $this->assertOutcome($result, 'retryable_failed');
        self::assertStringNotContainsString('attempt_count = attempt_count + 1', $this->queryEvidence());
    }

    public function test_terminal_failure_reports_terminal_failed(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'A non-retryable failure must remain terminal.');
        $row = $this->row([
            'state' => 'failed',
            'owner_token' => null,
            'retryable' => 0,
            'next_retry_at' => null,
            'completed_at' => '2026-03-14 12:29:00',
        ]);
        $this->installExistingRow($row);

        $result = $repository->claim('event-terminal', $this->context(), 'owner-b', 300);

        $this->assertOutcome($result, 'terminal_failed');
    }

    public function test_lost_takeover_race_re_reads_and_classifies_current_owner(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'A lost CAS race must be classified from fresh state.');
        $row = $this->row([
            'state' => 'processing',
            'owner_token' => 'owner-a',
            'lease_expires_at' => '2026-03-14 12:29:59',
        ]);
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$row): int {
            if (str_contains($query, 'INSERT')) {
                $wpdb->rows_affected = 0;
                return 0;
            }
            if (str_contains($query, 'UPDATE')) {
                $row['owner_token'] = 'owner-c';
                $row['lease_expires_at'] = '2026-03-14 12:35:00';
                $row['attempt_count'] = 2;
                $wpdb->rows_affected = 0;
                return 0;
            }

            return 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };

        $result = $repository->claim('event-race', $this->context(), 'owner-b', 300);

        $this->assertOutcome($result, 'in_progress');
        self::assertStringContainsString('owner-a', $this->queryEvidence());
    }

    public function test_database_insert_failure_without_existing_row_throws(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'A database failure must not masquerade as a duplicate.');
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to claim event lock');

        $repository->claim('event-db-failure', $this->context(), 'owner-a', 300);
    }

    public function test_succeed_requires_matching_owner_and_active_lease(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('succeed', 'Completion must require an active owned lease.');
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $activeOwner = str_contains($query, "owner_token = 'owner-a'")
                && str_contains($query, "lease_expires_at > '2026-03-14 12:30:00'");
            $wpdb->rows_affected = $activeOwner ? 1 : 0;
            return $wpdb->rows_affected;
        };

        self::assertTrue($repository->succeed('event-active', 'owner-a'));
        self::assertStringContainsString("state = 'succeeded'", $this->queryEvidence());
        self::assertStringContainsString("result = 'success'", $this->queryEvidence());
        self::assertStringContainsString("completed_at = '2026-03-14 12:30:00'", $this->queryEvidence());
    }

    public function test_expired_owner_cannot_complete_or_fail(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('succeed', 'Expired leases must not complete.');
        $this->requireLeaseMethod('fail', 'Expired leases must not fail.');
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->rows_affected = 0;
            return 0;
        };

        self::assertFalse($repository->succeed('event-expired-owner', 'owner-a'));
        self::assertFalse($repository->fail('event-expired-owner', 'owner-a', 'late'));
        self::assertStringContainsString("lease_expires_at > '2026-03-14 12:30:00'", $this->queryEvidence());
    }

    public function test_wrong_owner_cannot_fail_active_lease(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('fail', 'Failure must be conditional on owner_token.');
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $matches = str_contains($query, "owner_token = 'owner-b'");
            $wpdb->rows_affected = $matches ? 1 : 0;
            return $wpdb->rows_affected;
        };

        self::assertFalse($repository->fail('event-owned', 'owner-a', 'wrong owner'));
        self::assertTrue($repository->fail('event-owned', 'owner-b', 'current owner'));
    }

    public function test_retryable_failure_is_due_immediately_and_keeps_legacy_columns_coherent(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('fail', 'Retryable failures need an immediately due retry marker.');
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->rows_affected = 1;
            return 1;
        };

        self::assertTrue($repository->fail('event-retryable', 'owner-a', 'transient', true));

        $evidence = $this->queryEvidence();
        self::assertStringContainsString("state = 'failed'", $evidence);
        self::assertStringContainsString('retryable = 1', $evidence);
        self::assertStringContainsString("next_retry_at = '2026-03-14 12:30:00'", $evidence);
        self::assertStringContainsString("result = 'failed'", $evidence);
        self::assertStringContainsString("error = 'transient'", $evidence);
        self::assertStringContainsString('completed_at = null', $evidence);
    }

    public function test_terminal_failure_records_completion_and_no_retry(): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('fail', 'Terminal failures need a completion timestamp.');
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->rows_affected = 1;
            return 1;
        };

        self::assertTrue($repository->fail('event-terminal', 'owner-a', 'permanent', false));

        $evidence = $this->queryEvidence();
        self::assertStringContainsString('retryable = 0', $evidence);
        self::assertStringContainsString('next_retry_at = null', $evidence);
        self::assertStringContainsString("completed_at = '2026-03-14 12:30:00'", $evidence);
    }

    public function test_retention_deletes_only_old_terminal_rows(): void
    {
        $repository = $this->repository();
        $rows = [
            ['state' => 'processing', 'completed_at' => null],
            ['state' => 'failed', 'retryable' => 1, 'completed_at' => null],
            ['state' => 'failed', 'retryable' => 1, 'completed_at' => '2026-02-01 00:00:00'],
            ['state' => 'succeeded', 'completed_at' => '2026-02-01 00:00:00'],
            ['state' => 'failed', 'retryable' => 0, 'completed_at' => '2026-02-01 00:00:00'],
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$rows): int {
            if (str_contains($query, "(state = 'succeeded' OR (state = 'failed' AND retryable = 0))")
                && str_contains($query, 'completed_at IS NOT NULL')
                && str_contains($query, "completed_at < '2026-02-12 12:30:00'")
            ) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => $row['completed_at'] === null
                        || ((int) ($row['retryable'] ?? 0) === 1 && $row['state'] === 'failed')
                ));
                $wpdb->rows_affected = 2;
                return 2;
            }

            return 0;
        };

        self::assertSame(2, $repository->purgeOlderThan(30));
        self::assertCount(3, $rows);
        self::assertSame(['processing', 'failed', 'failed'], array_column($rows, 'state'));
        self::assertStringNotContainsString('processed_at <', $this->queryEvidence());
    }

    public function test_legacy_acquire_writes_a_complete_terminal_success_row(): void
    {
        $repository = $this->repository();
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->rows_affected = 1;
            return 1;
        };

        self::assertTrue($repository->acquire([
            'event_hash' => 'legacy-success',
            'order_id' => 91,
            'feed_id' => 7,
            'trigger' => 'order_paid',
        ]));

        $evidence = $this->queryEvidence();
        self::assertStringContainsString("'succeeded'", $evidence);
        self::assertStringContainsString("'success'", $evidence);
        self::assertStringContainsString('owner_token', $evidence);
        self::assertStringContainsString('lease_expires_at', $evidence);
        self::assertStringContainsString('retryable', $evidence);
        self::assertStringContainsString('updated_at', $evidence);
        self::assertStringContainsString('completed_at', $evidence);
        self::assertGreaterThanOrEqual(3, substr_count($evidence, '2026-03-14 12:30:00'));
    }

    public function test_legacy_record_failure_converts_terminal_success_to_retryable_due_failure(): void
    {
        $repository = $this->repository();
        $updates = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (
            string $table,
            array $data,
            array $where
        ) use (&$updates): int {
            $updates[] = [$table, $data, $where];
            return 1;
        };

        $repository->recordFailure('legacy-success', 'Broken');

        self::assertSame('failed', $updates[0][1]['state']);
        self::assertSame('failed', $updates[0][1]['result']);
        self::assertSame('Broken', $updates[0][1]['error']);
        self::assertSame('Broken', $updates[0][1]['last_error']);
        self::assertSame(1, $updates[0][1]['retryable']);
        self::assertSame('2026-03-14 12:30:00', $updates[0][1]['next_retry_at']);
        self::assertSame('2026-03-14 12:30:00', $updates[0][1]['updated_at']);
        self::assertNull($updates[0][1]['completed_at']);
        self::assertNull($updates[0][1]['owner_token']);
        self::assertNull($updates[0][1]['lease_expires_at']);
    }

    #[DataProvider('invalidClaimProvider')]
    public function test_claim_rejects_invalid_inputs(string $eventHash, string $ownerToken, int $leaseSeconds): void
    {
        $repository = $this->repository();
        $this->requireLeaseMethod('claim', 'Claim input validation requires the lease API.');
        $this->expectException(\InvalidArgumentException::class);

        $repository->claim($eventHash, $this->context(), $ownerToken, $leaseSeconds);
    }

    public static function invalidClaimProvider(): array
    {
        return [
            'empty hash' => ['', 'owner-a', 300],
            'oversized hash' => [str_repeat('a', 65), 'owner-a', 300],
            'empty owner' => ['event', '', 300],
            'oversized owner' => ['event', str_repeat('a', 65), 300],
            'zero lease' => ['event', 'owner-a', 0],
            'negative lease' => ['event', 'owner-a', -1],
        ];
    }

    public function test_retention_rejects_non_positive_days(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository()->purgeOlderThan(0);
    }

    public function test_claim_rejects_non_numeric_context_ids(): void
    {
        $repository = $this->repository();
        $this->expectException(\InvalidArgumentException::class);

        $repository->claim('event', ['order_id' => 'not-an-id'], 'owner-a', 300);
    }

    #[DataProvider('invalidContextBoundsProvider')]
    public function test_claim_rejects_context_values_that_cannot_be_cast_safely(array $context): void
    {
        $repository = $this->repository();
        $this->expectException(\InvalidArgumentException::class);

        $repository->claim('event', $context, 'owner-a', 300);
    }

    public static function invalidContextBoundsProvider(): array
    {
        return [
            'integer overflow' => [['order_id' => (string) PHP_INT_MAX . '0']],
            'negative integer' => [['subscription_id' => -1]],
            'float' => [['feed_id' => 1.5]],
            'boolean' => [['order_id' => true]],
        ];
    }

    public function test_claim_accepts_storage_boundaries_before_casting(): void
    {
        $repository = $this->repository();
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->rows_affected = 1;
            return 1;
        };

        $result = $repository->claim(
            str_repeat('ą', 64),
            [
                'order_id' => (string) PHP_INT_MAX,
                'subscription_id' => PHP_INT_MAX,
                'feed_id' => '0',
                'trigger' => str_repeat('ż', 100),
            ],
            str_repeat('ę', 64),
            300
        );

        $this->assertOutcome($result, 'acquired');
        self::assertStringContainsString((string) PHP_INT_MAX, $this->queryEvidence());
    }

    public function test_claim_rejects_trigger_over_storage_limit(): void
    {
        $repository = $this->repository();
        $this->expectException(\InvalidArgumentException::class);

        $repository->claim('event', ['trigger' => str_repeat('t', 101)], 'owner-a', 300);
    }

    public function test_event_claim_result_is_immutable_and_explicit(): void
    {
        self::assertTrue(class_exists(EventClaimResult::class));
        self::assertTrue((new \ReflectionClass(EventClaimResult::class))->isReadOnly());
        self::assertSame('acquired', EventClaimResult::acquired()->outcome);
        self::assertSame('duplicate_succeeded', EventClaimResult::duplicateSucceeded()->outcome);
        self::assertSame('in_progress', EventClaimResult::inProgress()->outcome);
        self::assertSame('retryable_failed', EventClaimResult::retryableFailed()->outcome);
        self::assertSame('terminal_failed', EventClaimResult::terminalFailed()->outcome);
    }

    private function repository(): EventLockRepository
    {
        $timezone = new \DateTimeZone('UTC');
        $clock = new Clock(new \DateTimeImmutable('2026-03-14 12:30:00', $timezone), $timezone);

        return new EventLockRepository($clock);
    }

    private function requireLeaseMethod(string $method, string $message): void
    {
        self::assertTrue(method_exists(EventLockRepository::class, $method), $message);
    }

    private function context(): array
    {
        return [
            'order_id' => 91,
            'subscription_id' => null,
            'feed_id' => 7,
            'trigger' => 'order_paid',
        ];
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'event_hash' => 'event',
            'state' => 'processing',
            'owner_token' => 'owner-a',
            'lease_expires_at' => '2026-03-14 12:35:00',
            'attempt_count' => 1,
            'retryable' => 1,
            'next_retry_at' => null,
            'completed_at' => null,
            'last_error' => null,
        ], $overrides);
    }

    private function installFirstClaimWrite(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): int => 1;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->rows_affected = 1;
            return 1;
        };
    }

    private function installExistingRow(array &$row, ?string $takeoverOwner = null): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (
            string $query,
            \wpdb $wpdb
        ) use (&$row, $takeoverOwner): int|false {
            if (str_contains($query, 'INSERT')) {
                $wpdb->rows_affected = 0;
                return false;
            }

            if ($takeoverOwner !== null && str_contains($query, 'UPDATE')) {
                $row['state'] = 'processing';
                $row['owner_token'] = $takeoverOwner;
                $row['lease_expires_at'] = '2026-03-14 12:35:00';
                $row['attempt_count']++;
                $wpdb->rows_affected = 1;
                return 1;
            }

            $wpdb->rows_affected = 0;
            return 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (
            string $table,
            array $data,
            array $where
        ) use (&$row, $takeoverOwner): int {
            if ($takeoverOwner === null) {
                return 0;
            }

            $row = array_replace($row, $data);
            return 1;
        };
    }

    private function installOwnerConditionalCompletion(string $currentOwner): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (
            string $table,
            array $data,
            array $where
        ) use ($currentOwner): int {
            return ($where['event_hash'] ?? null) === 'event-reclaimed'
                && ($where['owner_token'] ?? null) === $currentOwner
                ? 1
                : 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (
            string $query,
            \wpdb $wpdb
        ) use ($currentOwner): int {
            $matchesOwner = str_contains($query, "owner_token = '{$currentOwner}'");
            $wpdb->rows_affected = $matchesOwner ? 1 : 0;
            return $wpdb->rows_affected;
        };
    }

    private function assertOutcome(mixed $result, string $expected): void
    {
        self::assertInstanceOf(EventClaimResult::class, $result);
        self::assertSame($expected, $result->outcome);
    }

    private function queryEvidence(): string
    {
        return strtolower(serialize($GLOBALS['_fchub_test_queries']));
    }
}
