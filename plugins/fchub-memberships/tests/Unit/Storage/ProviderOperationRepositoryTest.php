<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Domain\ProviderOperationClaimResult;
use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProviderOperationRepositoryTest extends PluginTestCase
{
    public function test_repository_exposes_only_the_exact_v5_operation_states(): void
    {
        if (!class_exists(ProviderOperationRepository::class)) {
            self::fail('The provider operation repository must own the V5 storage contract.');
        }

        self::assertSame(
            ['pending', 'processing', 'applied', 'failed', 'deferred'],
            ProviderOperationRepository::STATES
        );
    }

    public function test_find_by_operation_key_reads_the_outbox_and_hydrates_counters(): void
    {
        if (!class_exists(ProviderOperationRepository::class)) {
            self::fail('The provider operation repository must provide foundational V5 reads.');
        }

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => [
            'id' => '9',
            'edge_id' => '7',
            'operation_key' => str_repeat('a', 64),
            'attempt_count' => '2',
            'state' => 'failed',
        ];

        $row = (new ProviderOperationRepository())->findByOperationKey(str_repeat('a', 64));

        self::assertSame(9, $row['id']);
        self::assertSame(7, $row['edge_id']);
        self::assertSame(2, $row['attempt_count']);
        self::assertStringContainsString(
            'FROM wp_fchub_membership_provider_operations WHERE operation_key =',
            serialize($GLOBALS['_fchub_test_queries'])
        );
    }

    public function test_find_by_id_throws_when_sql_fails_instead_of_reporting_missing(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'operation read failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read provider operation');

        $this->repository()->findById(9);
    }

    public function test_find_by_key_throws_when_sql_fails_instead_of_reporting_missing(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'operation read failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read provider operation');

        $this->repository()->findByOperationKey(str_repeat('a', 64));
    }

    public function test_latest_resource_operation_read_is_exact_and_deterministic(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function (string $sql) use (&$query): array {
            $query = $sql;
            return $this->row(['id' => 12, 'state' => 'failed', 'retryable' => 1]);
        };

        $operation = $this->repository()->findLatestForResource([
            'user_id' => 17,
            'provider' => 'fluentcrm',
            'resource_type' => 'fluentcrm_tag',
            'resource_id' => '41',
        ]);

        self::assertSame(12, $operation['id']);
        self::assertTrue($operation['retryable']);
        self::assertStringContainsString('INNER JOIN wp_fchub_membership_entitlement_edges', $query);
        self::assertStringContainsString("edge.user_id = 17", $query);
        self::assertStringContainsString("edge.provider = 'fluentcrm'", $query);
        self::assertStringContainsString('ORDER BY operation.id DESC LIMIT 1', $query);
    }

    public function test_latest_resource_operation_read_fails_closed_on_database_error(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'resource operation read failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read provider operation for resource');

        $this->repository()->findLatestForResource([
            'user_id' => 17,
            'provider' => 'fluentcrm',
            'resource_type' => 'fluentcrm_tag',
            'resource_id' => '41',
        ]);
    }

    public function test_latest_operations_for_edge_ids_are_hydrated_keyed_and_read_once(): void
    {
        $queries = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $sql) use (&$queries): array {
            $queries++;
            self::assertStringContainsString('edge_id IN (7, 9)', $sql);
            self::assertStringContainsString('MAX(id)', $sql);

            return [
                $this->row(['id' => '12', 'edge_id' => '7', 'attempt_count' => '2', 'retryable' => '1']),
                $this->row(['id' => '18', 'edge_id' => '9', 'attempt_count' => '1', 'retryable' => '0']),
            ];
        };

        $rows = $this->repository()->findLatestForEdgeIds([9, 7, 9]);

        self::assertSame([7, 9], array_keys($rows));
        self::assertSame(12, $rows[7]['id']);
        self::assertTrue($rows[7]['retryable']);
        self::assertFalse($rows[9]['retryable']);
        self::assertSame(1, $queries);
    }

    public function test_latest_operations_for_edge_ids_has_empty_fast_path_and_rejects_unsafe_bounds(): void
    {
        self::assertSame([], $this->repository()->findLatestForEdgeIds([]));
        self::assertSame([], $GLOBALS['_fchub_test_queries']);

        foreach ([[0], ['7'], range(1, 1001)] as $invalidIds) {
            try {
                $this->repository()->findLatestForEdgeIds($invalidIds);
                self::fail('Invalid edge IDs must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_provider_operation_summary_counts_actionable_states_in_one_query(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql): array {
            self::assertStringContainsString('GROUP BY edge.provider', $sql);
            self::assertStringContainsString("operation.state IN ('pending', 'processing', 'deferred')", $sql);

            return [
                ['provider' => 'fluent_community', 'pending_operations' => '3', 'failed_operations' => '1'],
            ];
        };

        self::assertSame([
            'fluent_community' => [
                'pending_operations' => 3,
                'failed_operations' => 1,
            ],
        ], $this->repository()->summarizeByProvider());
    }

    public function test_operation_key_is_stable_for_edge_action_and_origin(): void
    {
        $repository = $this->repository();

        self::assertSame(
            hash('sha256', '7|grant|subscription_activated:91'),
            $repository->operationKey(7, 'grant', 'subscription_activated:91')
        );
        self::assertNotSame(
            $repository->operationKey(7, 'grant', 'subscription_activated:91'),
            $repository->operationKey(7, 'revoke', 'subscription_activated:91')
        );
    }

    public function test_create_or_find_persists_pending_operation_before_returning(): void
    {
        $rows = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$rows): ?array {
            foreach ($rows as $row) {
                if (str_contains($query, "'{$row['operation_key']}'")) {
                    return $row;
                }
            }

            return null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data,
            \wpdb $wpdb
        ) use (&$rows): int {
            $wpdb->insert_id = 41;
            $rows[] = array_merge(['id' => 41, 'attempt_count' => 0, 'retryable' => 1], $data);
            return 1;
        };

        $operation = $this->repository()->createOrFind(7, 'grant', 'subscription_activated:91');

        self::assertSame(41, $operation['id']);
        self::assertSame('pending', $operation['state']);
        self::assertSame(0, $operation['attempt_count']);
        self::assertStringContainsString('fchub_membership_provider_operations', serialize($GLOBALS['_fchub_test_queries']));
    }

    public function test_future_operation_is_created_in_deferred_state(): void
    {
        $rows = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$rows): ?array {
            foreach ($rows as $row) {
                if (str_contains($query, "'{$row['operation_key']}'")) {
                    return $row;
                }
            }
            return null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data,
            \wpdb $wpdb
        ) use (&$rows): int {
            $wpdb->insert_id = 42;
            $rows[] = array_merge(['id' => 42], $data);
            return 1;
        };

        $operation = $this->repository()->createOrFind(
            7,
            'grant',
            'subscription_activated:91',
            new \DateTimeImmutable('2026-03-15 12:30:00', new \DateTimeZone('UTC'))
        );

        self::assertSame('deferred', $operation['state']);
        self::assertSame('2026-03-15 12:30:00', $operation['eligible_at']);
    }

    public function test_make_eligible_is_an_exact_compare_and_swap_for_one_due_deferred_operation(): void
    {
        $row = $this->row(['state' => 'deferred', 'eligible_at' => '2026-03-14 12:30:00']);
        $calls = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$row, &$calls): int {
            $calls++;
            $matches = $row['state'] === 'deferred'
                && str_contains($query, 'WHERE id = 9')
                && str_contains($query, "state = 'deferred'")
                && str_contains($query, "eligible_at <= '2026-03-14 12:30:00'");
            if ($matches) {
                $row['state'] = 'pending';
            }
            return $wpdb->rows_affected = $matches ? 1 : 0;
        };

        self::assertTrue($this->repository()->makeEligible(9));
        self::assertFalse($this->repository()->makeEligible(9));
        self::assertSame(2, $calls);
    }

    public function test_due_deferred_lookup_is_capped_and_ordered_for_exact_release(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_col'] = static fn(): array => ['9', '10'];

        self::assertSame([9, 10], $this->repository()->findDueDeferredIds(50));

        $evidence = serialize($GLOBALS['_fchub_test_queries']);
        self::assertStringContainsString("state = 'deferred'", $evidence);
        self::assertStringContainsString("eligible_at <= '2026-03-14 12:30:00'", $evidence);
        self::assertStringContainsString('ORDER BY id ASC LIMIT 50', $evidence);
    }

    public function test_newer_assignment_intent_lookup_matches_the_same_resource(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): string => '1';

        self::assertTrue($this->repository()->hasNewerAssignmentIntent($this->row(['id' => 12])));

        $evidence = serialize($GLOBALS['_fchub_test_queries']);
        self::assertStringContainsString('newer.id > 12', $evidence);
        self::assertStringContainsString('newer_edge.user_id = current_edge.user_id', $evidence);
        self::assertStringContainsString("newer.state IN ('pending', 'processing', 'applied', 'failed')", $evidence);
        self::assertStringContainsString("newer.state = 'deferred'", $evidence);
    }

    public function test_grant_operation_lookup_is_resource_scoped_to_active_edges(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_col'] = static fn(): array => ['9'];

        self::assertSame([9], $this->repository()->findGrantOperationIdsForResource(
            [
                'user_id' => 17,
                'provider' => 'fluentcrm',
                'resource_type' => 'fluentcrm_tag',
                'resource_id' => '41',
            ],
            '2026-03-14 12:30:00'
        ));

        $evidence = serialize($GLOBALS['_fchub_test_queries']);
        self::assertStringContainsString("edge.lifecycle = 'active'", $evidence);
        self::assertStringContainsString("operation.desired_action = 'grant'", $evidence);
        self::assertStringContainsString("edge.provider = 'fluentcrm'", $evidence);
        self::assertStringContainsString("edge.resource_id = '41'", $evidence);
        self::assertStringContainsString("edge.drip_available_at = '2026-03-14 12:30:00'", $evidence);
        self::assertStringContainsString("operation.eligible_at = '2026-03-14 12:30:00'", $evidence);
    }

    public function test_claim_acquires_pending_operation_with_a_five_minute_lease(): void
    {
        $row = $this->row();
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$row): int {
            if (str_contains($query, "state = 'processing'") && str_contains($query, "lease_owner = 'worker-a'")) {
                $row['state'] = 'processing';
                $row['lease_owner'] = 'worker-a';
                $row['lease_expires_at'] = '2026-03-14 12:35:00';
                return $wpdb->rows_affected = 1;
            }

            return $wpdb->rows_affected = 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };

        $result = $this->repository()->claim(9, 'worker-a');

        self::assertInstanceOf(ProviderOperationClaimResult::class, $result);
        self::assertSame('acquired', $result->outcome);
        self::assertSame(0, $result->operation['attempt_count']);
        self::assertStringContainsString('2026-03-14 12:35:00', serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString('lease_expires_at IS NULL', serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringNotContainsString('attempt_count = attempt_count + 1', serialize($GLOBALS['_fchub_test_queries']));
    }

    public function test_live_processing_lease_is_not_stolen(): void
    {
        $row = $this->row([
            'state' => 'processing',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => '2026-03-14 12:35:00',
            'attempt_count' => 1,
        ]);
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(): int => 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };

        $result = $this->repository()->claim(9, 'worker-b');

        self::assertSame('in-progress', $result->outcome);
    }

    public function test_expired_processing_lease_is_recovered(): void
    {
        $row = $this->row([
            'state' => 'processing',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => '2026-03-14 12:29:59',
            'attempt_count' => 1,
        ]);
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$row): int {
            if (str_contains($query, "lease_owner = 'worker-b'")) {
                $row['lease_owner'] = 'worker-b';
                $row['lease_expires_at'] = '2026-03-14 12:35:00';
                return $wpdb->rows_affected = 1;
            }

            return $wpdb->rows_affected = 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };

        $result = $this->repository()->claim(9, 'worker-b');

        self::assertSame('acquired', $result->outcome);
        self::assertSame(1, $result->operation['attempt_count']);
    }

    public function test_begin_attempt_increments_only_under_the_live_owned_lease(): void
    {
        $row = $this->row([
            'state' => 'processing',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => '2026-03-14 12:35:00',
        ]);
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$row): int {
            $matches = str_contains($query, "lease_owner = 'worker-a'")
                && str_contains($query, "lease_expires_at > '2026-03-14 12:30:00'");
            if ($matches) {
                $row['attempt_count']++;
            }
            return $wpdb->rows_affected = $matches ? 1 : 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };

        $attempt = $this->repository()->beginAttempt(9, 'worker-a', 0);

        self::assertSame(1, $attempt);
        self::assertStringContainsString('attempt_count = attempt_count + 1', serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString('attempt_count = 0', serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString('attempt_count < 4', serialize($GLOBALS['_fchub_test_queries']));
    }

    public function test_begin_attempt_refuses_to_increment_past_attempt_four(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            return $wpdb->rows_affected = 0;
        };

        self::assertNull($this->repository()->beginAttempt(9, 'worker-a', 4));
        self::assertStringContainsString('attempt_count < 4', serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString('attempt_count = 4', serialize($GLOBALS['_fchub_test_queries']));
    }

    public function test_claim_database_failure_is_not_misreported_as_not_due(): void
    {
        $row = $this->row();
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $row;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to claim provider operation');

        $this->repository()->claim(9, 'worker-a');
    }

    #[DataProvider('retryScheduleProvider')]
    public function test_retry_schedule_uses_bounded_backoff_and_attempt_four_is_terminal(
        int $attempt,
        bool $retryable,
        ?string $nextRetry,
        ?string $completedAt
    ): void {
        $row = $this->row(['state' => 'processing', 'attempt_count' => $attempt]);
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $row;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            return $wpdb->rows_affected = str_contains($query, "lease_owner = 'worker-a'") ? 1 : 0;
        };

        self::assertTrue($this->repository()->recordOutcome(
            9,
            'worker-a',
            ProviderOperationOutcome::retryableFailure('provider_error', 'Provider operation failed.')
        ));

        $evidence = strtolower(serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString('retryable = ' . ($retryable ? '1' : '0'), $evidence);
        self::assertStringContainsString(
            $nextRetry === null ? 'next_retry_at = null' : "next_retry_at = '{$nextRetry}'",
            $evidence
        );
        self::assertStringContainsString(
            $completedAt === null ? 'completed_at = null' : "completed_at = '{$completedAt}'",
            $evidence
        );
    }

    public static function retryScheduleProvider(): array
    {
        return [
            'attempt one' => [1, true, '2026-03-14 12:35:00', null],
            'attempt two' => [2, true, '2026-03-14 13:00:00', null],
            'attempt three' => [3, true, '2026-03-14 14:30:00', null],
            'attempt four' => [4, false, null, '2026-03-14 12:30:00'],
        ];
    }

    public function test_completion_requires_matching_owner_and_live_lease(): void
    {
        $row = $this->row(['state' => 'processing', 'attempt_count' => 1]);
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $row;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $matches = str_contains($query, "lease_owner = 'worker-a'")
                && str_contains($query, "lease_expires_at > '2026-03-14 12:30:00'");
            return $wpdb->rows_affected = $matches ? 1 : 0;
        };

        self::assertTrue($this->repository()->recordOutcome(
            9,
            'worker-a',
            ProviderOperationOutcome::applied()
        ));
        self::assertFalse($this->repository()->recordOutcome(
            9,
            'worker-b',
            ProviderOperationOutcome::applied()
        ));
    }

    public function test_applied_grant_count_is_derived_from_durable_edge_operations(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): string => '2';

        self::assertSame(2, $this->repository()->countAppliedGrantOperations(7));
        $evidence = serialize($GLOBALS['_fchub_test_queries']);
        self::assertStringContainsString('edge_id = 7', $evidence);
        self::assertStringContainsString("desired_action = 'grant'", $evidence);
        self::assertStringContainsString("state = 'applied'", $evidence);
    }

    public function test_applied_revoke_finalization_is_a_single_conditional_transition(): void
    {
        $calls = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$calls): int {
            self::assertStringContainsString("state = 'applied'", $query);
            self::assertStringContainsString("desired_action = 'revoke'", $query);
            self::assertStringContainsString('provider_operation_finalized', $query);
            return $wpdb->rows_affected = $calls++ === 0 ? 1 : 0;
        };

        self::assertTrue($this->repository()->finalizeAppliedRevoke(9));
        self::assertFalse($this->repository()->finalizeAppliedRevoke(9));
    }

    public function test_applied_grant_row_cannot_be_changed_by_revoke_finalization(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            self::assertStringContainsString("desired_action = 'revoke'", $query);
            self::assertStringNotContainsString("desired_action = 'grant'", $query);
            return $wpdb->rows_affected = 0;
        };

        self::assertFalse($this->repository()->finalizeAppliedRevoke(9));
    }

    public function test_recovery_is_capped_and_includes_due_failed_and_expired_processing_rows(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_col'] = static fn(): array => ['1', '2', '3'];

        self::assertSame([1, 2, 3], $this->repository()->findRecoverableIds(50));

        $evidence = strtolower(serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString("state = 'pending'", $evidence);
        self::assertStringContainsString("state = 'failed'", $evidence);
        self::assertStringContainsString("state = 'processing'", $evidence);
        self::assertStringContainsString('lease_expires_at is null', $evidence);
        self::assertStringContainsString('limit 50', $evidence);
    }

    public function test_stale_processing_recovery_is_an_exact_cas_that_preserves_attempts_and_backoff(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql, \wpdb $wpdb) use (&$query): int {
            $query = $sql;
            return $wpdb->rows_affected = 1;
        };

        self::assertTrue($this->repository()->recoverStaleProcessing(9));

        self::assertStringContainsString("SET state = 'pending'", $query);
        self::assertStringContainsString('lease_owner = NULL', $query);
        self::assertStringContainsString('lease_expires_at = NULL', $query);
        self::assertStringContainsString("state = 'processing'", $query);
        self::assertStringContainsString('lease_expires_at IS NULL', $query);
        self::assertStringContainsString("lease_expires_at <= '2026-03-14 12:30:00'", $query);
        self::assertStringNotContainsString('attempt_count', $query);
        self::assertStringNotContainsString('next_retry_at', $query);
    }

    public function test_recoverable_id_lookup_throws_when_sql_fails_instead_of_reporting_empty(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'recovery read failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_col'] = static fn(): array => [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read recoverable provider operations');

        $this->repository()->findRecoverableIds(50);
    }

    public function test_older_actionable_assignment_operation_serialises_newer_work(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 1;

        self::assertTrue($this->repository()->hasOlderActionableAssignment($this->row(['id' => 12])));

        $evidence = strtolower(serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString('join wp_fchub_membership_entitlement_edges', $evidence);
        self::assertStringContainsString('older.id < 12', $evidence);
        self::assertStringContainsString('older_edge.user_id = current_edge.user_id', $evidence);
        self::assertStringContainsString("older.state in ('pending', 'processing')", $evidence);
        self::assertStringContainsString("older.next_retry_at <= '2026-03-14 12:30:00'", $evidence);
        self::assertStringContainsString("older.state = 'deferred'", $evidence);
        self::assertStringContainsString("older.eligible_at <= '2026-03-14 12:30:00'", $evidence);
    }

    public function test_older_assignment_lookup_throws_when_sql_fails_instead_of_reporting_no_blocker(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'ordering read failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read provider operation ordering');

        $this->repository()->hasOlderActionableAssignment($this->row(['id' => 12]));
    }

    public function test_release_deferred_throws_when_sql_fails_instead_of_reporting_zero(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(): false => false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to release deferred provider operations');

        $this->repository()->releaseDeferred(50);
    }

    private function repository(): ProviderOperationRepository
    {
        $timezone = new \DateTimeZone('UTC');

        return new ProviderOperationRepository(new Clock(
            new \DateTimeImmutable('2026-03-14 12:30:00', $timezone),
            $timezone
        ));
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => 9,
            'edge_id' => 7,
            'operation_key' => str_repeat('a', 64),
            'desired_action' => 'grant',
            'origin_event' => 'subscription_activated:91',
            'state' => 'pending',
            'lease_owner' => null,
            'lease_expires_at' => null,
            'attempt_count' => 0,
            'retryable' => 1,
            'next_retry_at' => null,
            'eligible_at' => '2026-03-14 12:30:00',
            'completed_at' => null,
        ], $overrides);
    }
}
