<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class EntitlementEdgeRepositoryTest extends PluginTestCase
{
    public function test_repository_exposes_resource_scoped_transaction_contract(): void
    {
        self::assertTrue(
            method_exists(EntitlementEdgeRepository::class, 'resourceTransaction'),
            'Resource mutations require a resource-scoped advisory-lock transaction.'
        );
    }

    public function test_identity_lookup_uses_all_nine_fields_and_hydrates_policy(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function (string $sql) use (&$query): array {
            $query = $sql;

            return $this->row(['policy' => '{"cancel_behavior":"immediate"}']);
        };

        $edge = (new EntitlementEdgeRepository())->findByIdentity($this->identity());

        foreach (['user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id', 'feed_scope', 'source_type', 'source_id'] as $field) {
            self::assertStringContainsString($field, $query);
        }
        self::assertSame(55, $edge['source_id']);
        self::assertSame(['cancel_behavior' => 'immediate'], $edge['policy']);
    }

    public function test_subscription_correlation_requires_a_canonical_integer_json_value(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;
            return [];
        };

        (new EntitlementEdgeRepository())->getBySubscriptionCorrelation(88, 'active');

        self::assertStringContainsString("JSON_TYPE(JSON_EXTRACT(policy, '$.subscription_id')) = 'INTEGER'", $query);
        self::assertStringContainsString("JSON_UNQUOTE(JSON_EXTRACT(policy, '$.subscription_id')) = '88'", $query);
        self::assertStringNotContainsString('CAST(', $query, 'CAST would accept malformed values such as 88junk.');
    }

    public function test_find_by_id_throws_when_sql_fails_instead_of_reporting_missing(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'edge read failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read entitlement edge');

        (new EntitlementEdgeRepository())->findById(41);
    }

    public function test_identity_lookup_throws_when_sql_fails_instead_of_creating_over_unknown_state(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'identity read failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read entitlement identity');

        (new EntitlementEdgeRepository())->findByIdentity($this->identity());
    }

    public function test_full_identity_separates_typed_ids_stacked_plans_and_scoped_feeds(): void
    {
        $inserted = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): ?array => null;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data,
            \wpdb $wpdb
        ) use (&$inserted): int {
            $wpdb->insert_id = count($inserted) + 1;
            $inserted[] = $data;
            return 1;
        };
        $repository = new EntitlementEdgeRepository();

        $variants = [
            $this->edgeData(),
            $this->edgeData(['source_type' => 'subscription']),
            $this->edgeData(['plan_id' => 8]),
            $this->edgeData(['feed_id' => 12]),
            $this->edgeData(['feed_scope' => 'global']),
        ];
        foreach ($variants as $variant) {
            self::assertSame('created', $repository->createOrReplay($variant)['action']);
        }

        self::assertCount(5, $inserted);
        self::assertSame(
            ['order', 'subscription'],
            [$inserted[0]['source_type'], $inserted[1]['source_type']]
        );
        self::assertSame([7, 8], [$inserted[0]['plan_id'], $inserted[2]['plan_id']]);
        self::assertSame([11, 12], [$inserted[0]['feed_id'], $inserted[3]['feed_id']]);
        self::assertSame(['product', 'global'], [$inserted[0]['feed_scope'], $inserted[4]['feed_scope']]);
    }

    public function test_manual_trial_order_and_subscription_sources_are_persisted_verbatim(): void
    {
        $inserted = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): ?array => null;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data,
            \wpdb $wpdb
        ) use (&$inserted): int {
            $wpdb->insert_id = count($inserted) + 1;
            $inserted[] = $data;
            return 1;
        };
        $repository = new EntitlementEdgeRepository();

        foreach ([
            ['manual', 0],
            ['trial', 901],
            ['order', 55],
            ['subscription', 55],
        ] as [$sourceType, $sourceId]) {
            $repository->createOrReplay($this->edgeData([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]));
        }

        self::assertSame(
            [['manual', 0], ['trial', 901], ['order', 55], ['subscription', 55]],
            array_map(static fn(array $row): array => [$row['source_type'], $row['source_id']], $inserted)
        );
    }

    public function test_exact_active_replay_is_idempotent_and_does_not_write(): void
    {
        $existing = $this->row();
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $existing;

        $result = (new EntitlementEdgeRepository())->createOrReplay($this->edgeData());

        self::assertSame('replayed', $result['action']);
        self::assertSame(41, $result['edge']['id']);
        self::assertSame([], array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $entry): bool => $entry[0] === 'insert' || $entry[0] === 'update'
        )));
    }

    public function test_successful_insert_without_a_positive_id_is_rejected(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): ?array => null;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data,
            \wpdb $wpdb
        ): int {
            $wpdb->insert_id = 0;
            return 1;
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The entitlement edge did not return a valid identifier.');

        (new EntitlementEdgeRepository())->createOrReplay($this->edgeData());
    }

    public function test_owner_and_assignment_provenance_are_immutable_on_replay(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(): array => $this->row();
        $repository = new EntitlementEdgeRepository();

        $ownerConflict = $repository->createOrReplay($this->edgeData(['owner' => 'preexisting']));
        $provenanceConflict = $repository->createOrReplay($this->edgeData([
            'assignment_provenance' => 'preexisting',
        ]));

        self::assertSame('immutable_conflict', $ownerConflict['action']);
        self::assertSame('immutable_conflict', $provenanceConflict['action']);
    }

    public function test_direct_replay_compares_every_immutable_edge_field(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(): array => $this->row();
        $repository = new EntitlementEdgeRepository();

        foreach ([
            ['starts_at' => '2026-07-23 10:00:00'],
            ['expires_at' => '2026-08-01 10:00:00'],
            ['drip_available_at' => '2026-07-24 10:00:00'],
            ['policy' => ['cancel_behavior' => 'immediate']],
        ] as $change) {
            self::assertSame(
                'immutable_conflict',
                $repository->createOrReplay($this->edgeData($change))['action']
            );
        }
    }

    public function test_replay_can_limit_immutable_comparison_to_explicit_fields(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(): array => $this->row();

        $result = (new EntitlementEdgeRepository())->createOrReplay(
            $this->edgeData(['starts_at' => '2026-07-23 10:00:00']),
            ['owner', 'assignment_provenance']
        );

        self::assertSame('replayed', $result['action']);
        self::assertSame('2026-07-22 10:00:00', $result['edge']['starts_at']);
    }

    public function test_ended_identity_cannot_be_reactivated(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(): array => $this->row([
            'lifecycle' => 'ended',
            'ended_at' => '2026-07-22 12:00:00',
        ]);

        $result = (new EntitlementEdgeRepository())->createOrReplay($this->edgeData());

        self::assertSame('ended_conflict', $result['action']);
    }

    public function test_end_updates_only_lifecycle_end_fields_and_is_idempotent(): void
    {
        $updated = null;
        $calls = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function () use (&$calls): array {
            $calls++;
            return $this->row($calls === 1 ? [] : [
                'lifecycle' => 'ended',
                'ended_at' => '2026-07-22 12:00:00',
                'end_reason' => 'refund',
            ]);
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (
            string $table,
            array $data,
            array $where
        ) use (&$updated): int {
            $updated = [$table, $data, $where];
            return 1;
        };

        $result = (new EntitlementEdgeRepository())->endByIdentity(
            $this->identity(),
            '2026-07-22 12:00:00',
            'refund'
        );

        self::assertSame('ended', $result['action']);
        self::assertSame(
            ['lifecycle', 'ended_at', 'end_reason', 'updated_at'],
            array_keys($updated[1])
        );
        self::assertSame(['id' => 41, 'lifecycle' => 'active'], $updated[2]);
    }

    public function test_active_resource_lookup_is_deterministically_ordered(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $sql) use (&$query): array {
            $query = $sql;
            return [$this->row()];
        };

        $rows = (new EntitlementEdgeRepository())->getActiveByResource(9, 'wordpress_core', 'post', '42');

        self::assertCount(1, $rows);
        self::assertStringContainsString("lifecycle = 'active'", $query);
        self::assertStringContainsString('starts_at DESC', $query);
        self::assertStringContainsString('id DESC', $query);
    }

    public function test_access_status_update_is_bounded_to_live_named_edges(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql) use (&$query): int {
            $query = $sql;
            return 2;
        };
        $repository = new EntitlementEdgeRepository();

        self::assertTrue(method_exists($repository, 'setAccessStatusByIds'));
        self::assertSame(
            2,
            $repository->setAccessStatusByIds([41, 42, 41], 'paused', '2026-07-23 12:00:00')
        );
        self::assertStringContainsString('id IN (41, 42)', $query);
        self::assertStringContainsString("lifecycle = 'active'", $query);
        self::assertStringContainsString("access_status <> 'paused'", $query);
        self::assertStringContainsString("SET access_status = 'paused'", $query);
    }

    public function test_active_resource_lookup_throws_when_sql_fails_instead_of_returning_empty(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'resource lookup failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read active entitlement edges');

        (new EntitlementEdgeRepository())->getActiveByResource(9, 'fluentcrm', 'fluentcrm_tag', '42');
    }

    public function test_reconciliation_watermark_and_resource_page_are_fixed_keyset_unions(): void
    {
        $queries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): string => '13';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $query) use (&$queries): array {
            $queries[] = $query;
            return [
                array_merge($this->row(['id' => 2]), ['cursor_id' => '2']),
                array_merge($this->row(['id' => 7, 'plan_id' => 8]), ['cursor_id' => '2']),
                array_merge($this->row(['id' => 9, 'resource_id' => '43']), ['cursor_id' => '9']),
            ];
        };
        $repository = new EntitlementEdgeRepository();

        self::assertSame(13, $repository->maxReconciliationEdgeId());
        $unions = $repository->getReconciliationResourcePage(0, 13, 3);

        self::assertSame([2, 9], array_column($unions, 'cursor_id'));
        self::assertCount(2, $unions[0]['edges']);
        self::assertSame(8, $unions[0]['edges'][1]['plan_id']);
        $evidence = implode("\n", $queries);
        self::assertStringContainsString('MIN(id) AS cursor_id', $evidence);
        self::assertStringContainsString('id <= 13', $evidence);
        self::assertStringContainsString('HAVING MIN(id) > 0', $evidence);
        self::assertStringContainsString('ORDER BY cursor_id ASC LIMIT 3', $evidence);
    }

    public function test_reconciliation_resource_read_includes_every_lifecycle_under_the_resource_lock(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $sql) use (&$query): array {
            $query = $sql;
            return [$this->row(), $this->row(['id' => 42, 'lifecycle' => 'ended'])];
        };

        $rows = (new EntitlementEdgeRepository())->getByResource(9, 'wordpress_core', 'post', '42');

        self::assertSame(['active', 'ended'], array_column($rows, 'lifecycle'));
        self::assertStringNotContainsString('lifecycle =', $query);
        self::assertStringContainsString('ORDER BY id ASC', $query);
    }

    public function test_reconciliation_reads_fail_closed_on_database_errors(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'reconciliation read failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read reconciliation watermark');

        (new EntitlementEdgeRepository())->maxReconciliationEdgeId();
    }

    public function test_resource_assignment_evidence_is_conservative_across_all_edge_lifecycles(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 1;

        self::assertTrue((new EntitlementEdgeRepository())->hasUnsafeAssignmentEvidence(
            9,
            'fluentcrm',
            'fluentcrm_tag',
            '42'
        ));

        $evidence = strtolower(serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString("owner <> 'fchub'", $evidence);
        self::assertStringContainsString("assignment_provenance <> 'fchub_created'", $evidence);
        self::assertStringContainsString('fchub_membership_provider_operations', $evidence);
        self::assertStringContainsString('provider_operation_applied', $evidence);
        self::assertStringNotContainsString("lifecycle = 'active'", $evidence);
    }

    public function test_stacked_assignment_evidence_uses_one_applied_fchub_mutation_across_the_safe_resource_lineage(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $sql) use (&$query): int {
            $query = $sql;
            return 0;
        };

        self::assertFalse((new EntitlementEdgeRepository())->hasUnsafeAssignmentEvidence(
            9,
            'fluent_community',
            'space',
            '42'
        ));

        self::assertStringContainsString('INNER JOIN', $query);
        self::assertStringContainsString('mutation_edge.user_id = 9', $query);
        self::assertStringContainsString("mutation_edge.assignment_provenance = 'fchub_created'", $query);
        self::assertStringContainsString("latest_operation.last_error_code = 'provider_operation_applied'", $query);
        self::assertStringNotContainsString('grant_operation.edge_id = edge_evidence.id', $query);
    }

    public function test_resource_assignment_mutation_evidence_uses_the_latest_applied_actual_operation(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $sql) use (&$query): int {
            $query = $sql;
            return 1;
        };

        self::assertTrue((new EntitlementEdgeRepository())->hasUnsafeAssignmentEvidence(
            9,
            'fluent_community',
            'space',
            '42'
        ));

        self::assertStringContainsString("latest_operation.state = 'applied'", $query);
        self::assertStringContainsString(
            "latest_operation.last_error_code = 'provider_operation_applied'",
            $query
        );
        self::assertStringContainsString(
            "latest_operation.last_error_code = 'provider_operation_finalized'",
            $query
        );
        self::assertStringContainsString('ORDER BY latest_operation.id DESC LIMIT 1', $query);
        self::assertStringContainsString("NOT IN ('grant', 'resume')", $query);
    }

    public function test_resource_assignment_evidence_throws_when_sql_fails(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'assignment evidence failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read provider assignment evidence');

        (new EntitlementEdgeRepository())->hasUnsafeAssignmentEvidence(
            9,
            'fluentcrm',
            'fluentcrm_tag',
            '42'
        );
    }

    public function test_active_revocation_match_uses_plan_typed_source_and_scoped_feed(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(): array => [$this->row()];

        $rows = (new EntitlementEdgeRepository())->getActiveMatching(9, 7, [
            'source_type' => 'order',
            'source_id' => 55,
            'feed_id' => 11,
            'feed_scope' => 'product',
        ]);

        self::assertCount(1, $rows);
        $evidence = strtolower(serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString('plan_id = 7', $evidence);
        self::assertStringContainsString("source_type = 'order'", $evidence);
        self::assertStringContainsString('source_id = 55', $evidence);
        self::assertStringContainsString('feed_id = 11', $evidence);
        self::assertStringContainsString("feed_scope = 'product'", $evidence);
        self::assertStringContainsString("lifecycle = 'active'", $evidence);
    }

    public function test_active_revocation_match_throws_on_sql_failure(): void
    {
        $database = new class extends \wpdb {
            public string $last_error = 'typed edge match failed';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read matching entitlement edges');

        (new EntitlementEdgeRepository())->getActiveMatching(9, 7, [
            'source_type' => 'subscription',
            'source_id' => 55,
        ]);
    }

    public function test_active_typed_source_lookup_never_collapses_equal_numeric_ids(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(): array => [$this->row()];

        self::assertCount(1, (new EntitlementEdgeRepository())->getActiveByTypedSource(55, 'order'));

        $evidence = strtolower(serialize($GLOBALS['_fchub_test_queries']));
        self::assertStringContainsString("source_type = 'order'", $evidence);
        self::assertStringContainsString('source_id = 55', $evidence);
        self::assertStringContainsString("lifecycle = 'active'", $evidence);
    }

    public function test_transaction_commits_success_and_rolls_back_failure(): void
    {
        $repository = new EntitlementEdgeRepository();

        self::assertSame('ok', $repository->transaction(static fn(): string => 'ok'));
        try {
            $repository->transaction(static fn(): never => throw new \RuntimeException('mirror failed'));
            self::fail('The transaction must rethrow callback failures.');
        } catch (\RuntimeException $exception) {
            self::assertSame('mirror failed', $exception->getMessage());
        }

        $queries = array_values(array_map(
            static fn(array $entry): string => $entry[1],
            array_filter($GLOBALS['_fchub_test_queries'], static fn(array $entry): bool => $entry[0] === 'query')
        ));
        self::assertSame(['START TRANSACTION', 'COMMIT', 'START TRANSACTION', 'ROLLBACK'], $queries);
    }

    public function test_resource_transaction_locks_before_start_and_releases_after_commit(): void
    {
        $order = [];
        $lockQuery = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (
            &$order,
            &$lockQuery
        ): int {
            if (str_contains($query, 'GET_LOCK')) {
                $order[] = 'lock';
                $lockQuery = $query;
            } elseif (str_contains($query, 'RELEASE_LOCK')) {
                $order[] = 'release';
            }
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$order): int {
            $order[] = strtolower($query);
            return 1;
        };

        $result = (new EntitlementEdgeRepository())->resourceTransaction([
            'user_id' => 987654,
            'provider' => 'secret-provider@example.test',
            'resource_type' => 'private-resource',
            'resource_id' => 'customer-private-path',
        ], static function () use (&$order): string {
            $order[] = 'callback';
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(['lock', 'start transaction', 'callback', 'commit', 'release'], $order);
        self::assertStringNotContainsString('secret-provider@example.test', $lockQuery);
        self::assertStringNotContainsString('customer-private-path', $lockQuery);
        self::assertMatchesRegularExpression("/GET_LOCK\\('([^']+)'/", $lockQuery);
        preg_match("/GET_LOCK\\('([^']+)'/", $lockQuery, $matches);
        self::assertLessThanOrEqual(64, strlen($matches[1]));
    }

    public function test_resource_transaction_rolls_back_and_releases_after_callback_failure(): void
    {
        $order = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (&$order): int {
            $order[] = str_contains($query, 'GET_LOCK') ? 'lock' : 'release';
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$order): int {
            $order[] = strtolower($query);
            return 1;
        };

        try {
            (new EntitlementEdgeRepository())->resourceTransaction(
                $this->identity(),
                static function () use (&$order): never {
                    $order[] = 'callback';
                    throw new \RuntimeException('mirror failed');
                }
            );
            self::fail('The callback failure must be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('mirror failed', $exception->getMessage());
        }

        self::assertSame(['lock', 'start transaction', 'callback', 'rollback', 'release'], $order);
    }

    public function test_duplicate_insert_observes_the_serialized_winner(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function () use (&$reads): ?array {
            $reads++;
            return $reads === 1 ? null : $this->row();
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;

        $result = (new EntitlementEdgeRepository())->createOrReplay($this->edgeData());

        self::assertSame('replayed', $result['action']);
        self::assertSame(2, $reads);
        self::assertSame(41, $result['edge']['id']);
    }

    private function identity(array $overrides = []): array
    {
        return array_merge([
            'user_id' => 9,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '42',
            'plan_id' => 7,
            'feed_id' => 11,
            'feed_scope' => 'product',
            'source_type' => 'order',
            'source_id' => 55,
        ], $overrides);
    }

    private function edgeData(array $overrides = []): array
    {
        return array_merge($this->identity(), [
            'owner' => 'fchub',
            'assignment_provenance' => 'fchub_created',
            'lifecycle' => 'active',
            'access_status' => 'active',
            'starts_at' => '2026-07-22 10:00:00',
            'expires_at' => null,
            'drip_available_at' => null,
            'ended_at' => null,
            'end_reason' => null,
            'policy' => [],
            'created_at' => '2026-07-22 10:00:00',
            'updated_at' => '2026-07-22 10:00:00',
        ], $overrides);
    }

    private function row(array $overrides = []): array
    {
        return array_merge($this->edgeData(), [
            'id' => 41,
            'policy' => '{}',
        ], $overrides);
    }
}
