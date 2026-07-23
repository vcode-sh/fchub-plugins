<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Entitlement;

use FChubMemberships\Domain\Entitlement\EntitlementBackfillService;
use FChubMemberships\Domain\Entitlement\EntitlementService;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class EntitlementBackfillServiceTest extends PluginTestCase
{
    public function test_preview_fixes_watermark_uses_keyset_and_reports_only_sanitised_classifications(): void
    {
        [$service, $grants, , $edges] = $this->service([
            $this->grant(['id' => 1]),
            $this->grant([
                'id' => 2,
                'user_id' => 82,
                'feed_id' => 52,
                'meta' => [],
            ]),
        ], [
            1 => [['source_type' => 'order', 'source_id' => 123]],
        ], [
            50 => ['scope' => 'product', 'plan_id' => 7],
            52 => ['scope' => 'product', 'plan_id' => 99],
        ]);

        $report = $service->previewBatch();

        self::assertSame(2, $report['through_grant_id']);
        self::assertSame(2, $report['next_cursor']);
        self::assertTrue($report['complete']);
        self::assertSame([
            'deterministic' => 1,
            'external_unknown' => 1,
            'refused' => 0,
            'already_migrated' => 0,
        ], $report['counts']);
        self::assertSame('deterministic', $report['items'][0]['classification']);
        self::assertSame('external_unknown', $report['items'][1]['classification']);
        self::assertSame(52, $report['items'][1]['proposed_edges'][0]['feed_id']);
        self::assertSame('external_unknown', $report['items'][1]['proposed_edges'][0]['feed_scope']);
        self::assertSame('external_unknown', $report['items'][1]['proposed_edges'][0]['source_type']);
        self::assertSame(0, $report['items'][1]['proposed_edges'][0]['source_id']);
        self::assertSame('external_unknown', $report['items'][1]['proposed_edges'][0]['owner']);
        self::assertSame('unknown', $report['items'][1]['proposed_edges'][0]['assignment_provenance']);
        self::assertContains('feed_relation_stale', $report['items'][1]['reason_codes']);
        self::assertContains('typed_sources_missing', $report['items'][1]['reason_codes']);
        self::assertContains('ownership_marker_missing', $report['items'][1]['reason_codes']);
        self::assertArrayNotHasKey('user_id', $report['items'][0]['proposed_edges'][0]);
        self::assertArrayNotHasKey('meta', $report['items'][0]);
        self::assertSame([], $edges->rows, 'Preview must not persist an edge.');

        $grants->rows[] = $this->grant(['id' => 3]);
        $next = $service->previewBatch(2, 100, $report['through_grant_id']);

        self::assertSame([], $next['items']);
        self::assertSame(2, $next['through_grant_id']);
        self::assertTrue($next['complete']);
        self::assertSame([[0, 2, 100], [2, 2, 100]], $grants->batchCalls);
    }

    public function test_apply_records_active_and_ended_edges_without_mutating_grants_and_reruns_idempotently(): void
    {
        [$service, $grants, , $edges, $audits] = $this->service([
            $this->grant(['id' => 1, 'status' => 'paused']),
            $this->grant([
                'id' => 2,
                'status' => 'expired',
                'updated_at' => '2026-07-01 10:00:00',
            ]),
        ], [
            1 => [['source_type' => 'order', 'source_id' => 123]],
            2 => [['source_type' => 'subscription', 'source_id' => 456]],
        ], [50 => ['scope' => 'global', 'plan_id' => 7]]);
        $before = $grants->rows;

        $first = $service->applyBatch(0, 100, 2);

        self::assertSame(2, $first['next_cursor']);
        self::assertTrue($first['complete']);
        self::assertSame('active', array_values($edges->rows)[0]['lifecycle']);
        self::assertSame('ended', array_values($edges->rows)[1]['lifecycle']);
        self::assertArrayHasKey('access_status', array_values($edges->rows)[0]);
        self::assertArrayHasKey('access_status', array_values($edges->rows)[1]);
        self::assertSame('paused', array_values($edges->rows)[0]['access_status']);
        self::assertSame('active', array_values($edges->rows)[1]['access_status']);
        self::assertSame('2026-07-01 10:00:00', array_values($edges->rows)[1]['ended_at']);
        self::assertSame('legacy_expired', array_values($edges->rows)[1]['end_reason']);
        self::assertSame($before, $grants->rows, 'Historical recording must preserve every legacy grant column.');
        self::assertSame(['entitlement_backfill_apply_intent', 'entitlement_backfill_apply_outcome'], array_column($audits->rows, 'action'));
        self::assertArrayNotHasKey('user_id', $audits->rows[1]['data']);

        $second = $service->applyBatch(0, 100, 2);

        self::assertCount(2, $edges->rows);
        self::assertSame(2, $second['counts']['already_migrated']);
        self::assertSame(['already_migrated', 'already_migrated'], array_column($second['items'], 'classification'));
    }

    public function test_absent_plan_uses_zero_sentinel_without_claiming_a_deterministic_edge(): void
    {
        [$service] = $this->service(
            [$this->grant(['plan_id' => null])],
            [1 => [['source_type' => 'order', 'source_id' => 123]]],
            [50 => ['scope' => 'product', 'plan_id' => 0]]
        );

        $item = $service->previewBatch()['items'][0];

        self::assertSame('external_unknown', $item['classification']);
        self::assertSame(0, $item['proposed_edges'][0]['plan_id']);
        self::assertSame('external_unknown', $item['proposed_edges'][0]['feed_scope']);
        self::assertContains('plan_id_missing', $item['reason_codes']);
        self::assertNotContains('feed_relation_exact', $item['reason_codes']);
    }

    public function test_malformed_typed_source_refuses_the_whole_grant_instead_of_dropping_one_edge(): void
    {
        [$service] = $this->service(
            [$this->grant()],
            [1 => [
                ['source_type' => 'order', 'source_id' => 123],
                ['source_type' => '', 'source_id' => 456],
            ]],
            [50 => ['scope' => 'product', 'plan_id' => 7]]
        );

        $item = $service->previewBatch()['items'][0];

        self::assertSame('refused', $item['classification']);
        self::assertSame([], $item['proposed_edges']);
        self::assertSame(['typed_source_malformed'], $item['reason_codes']);
    }

    public function test_grant_batch_repository_surfaces_database_read_errors(): void
    {
        $GLOBALS['wpdb'] = new class extends \wpdb {
            public string $last_error = '';
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (
            string $query,
            string $output,
            \wpdb $database
        ): array {
            $database->last_error = 'Backfill grant read failed.';
            return [];
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The entitlement backfill grants could not be read.');

        (new GrantRepository())->getEntitlementBackfillBatch(0, 10, 100);
    }

    public function test_typed_source_repository_surfaces_database_read_errors(): void
    {
        $GLOBALS['wpdb'] = new class extends \wpdb {
            public string $last_error = '';
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (
            string $query,
            string $output,
            \wpdb $database
        ): array {
            $database->last_error = 'Backfill source read failed.';
            return [];
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The entitlement backfill sources could not be read.');

        (new GrantSourceRepository())->getSourcesByGrant(1);
    }

    public function test_watermark_repository_surfaces_database_read_errors(): void
    {
        $GLOBALS['wpdb'] = new class extends \wpdb {
            public string $last_error = '';
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (
            string $query,
            \wpdb $database
        ): mixed {
            $database->last_error = 'Backfill watermark read failed.';
            return null;
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The entitlement backfill watermark could not be read.');

        (new GrantRepository())->getEntitlementBackfillWatermark();
    }

    public function test_current_feed_relation_database_error_is_not_reported_as_external_unknown(): void
    {
        $GLOBALS['wpdb'] = new class extends \wpdb {
            public string $last_error = '';
        };
        $rawGrant = array_merge($this->grant(), [
            'source_type' => 'order',
            'source_id' => 123,
            'source_ids' => '[123]',
            'meta' => '{"provider_access_owner":"fchub"}',
        ]);
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (
            string $query,
            string $output,
            \wpdb $database
        ) use ($rawGrant): array {
            if (str_contains($query, 'fchub_membership_grants')) {
                return [$rawGrant];
            }
            if (str_contains($query, 'fchub_membership_grant_sources')) {
                return [['source_type' => 'order', 'source_id' => '123']];
            }

            $database->last_error = 'FluentCart feed relation read failed.';
            return [];
        };
        $grants = new GrantRepository();
        $sources = new GrantSourceRepository();
        $edges = new EntitlementEdgeRepository();
        $service = new EntitlementBackfillService(
            $grants,
            $sources,
            $edges,
            new EntitlementService($edges, $grants),
            null,
            static function (): void {
            }
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The current FluentCart feed relation could not be read.');

        $service->previewBatch(0, 100, 1);
    }

    public function test_malformed_legacy_timestamps_are_refused_before_an_edge_is_proposed(): void
    {
        foreach ([
            ['created_at' => 'not-a-storage-time'],
            ['updated_at' => '2026-02-30 10:00:00'],
            ['starts_at' => 'tomorrow'],
            ['expires_at' => '2026-07-22'],
            ['drip_available_at' => '2026-07-22 25:00:00'],
        ] as $override) {
            [$service] = $this->service(
                [$this->grant($override)],
                [1 => [['source_type' => 'order', 'source_id' => 123]]],
                [50 => ['scope' => 'product', 'plan_id' => 7]]
            );

            $item = $service->previewBatch()['items'][0];

            self::assertSame('refused', $item['classification']);
            self::assertSame([], $item['proposed_edges']);
            self::assertSame(['malformed_grant_timestamps'], $item['reason_codes']);
        }
    }

    public function test_apply_stops_at_failed_row_and_does_not_advance_cursor_past_it(): void
    {
        [$service, , , $edges] = $this->service([
            $this->grant(['id' => 1]),
            $this->grant(['id' => 2, 'resource_id' => 'fail']),
            $this->grant(['id' => 3]),
        ], [
            1 => [['source_type' => 'order', 'source_id' => 101]],
            2 => [['source_type' => 'order', 'source_id' => 102]],
            3 => [['source_type' => 'order', 'source_id' => 103]],
        ], [50 => ['scope' => 'product', 'plan_id' => 7]]);
        $edges->failResourceId = 'fail';

        $report = $service->applyBatch(0, 100, 3);

        self::assertSame(1, $report['next_cursor']);
        self::assertFalse($report['complete']);
        self::assertSame([1, 2], array_column($report['items'], 'grant_id'));
        self::assertSame('refused', $report['items'][1]['classification']);
        self::assertSame(['edge_persistence_failed'], $report['items'][1]['reason_codes']);
        self::assertCount(1, $edges->rows);
    }

    public function test_invalid_apply_controls_are_audited_as_one_sanitised_intent_outcome_pair(): void
    {
        [$service, , , , $audits] = $this->service([], [], []);

        try {
            $service->applyBatch(-1, 100, 10);
            self::fail('Invalid apply controls must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Entitlement backfill cursor cannot be negative.', $exception->getMessage());
        }

        self::assertSame(
            ['entitlement_backfill_apply_intent', 'entitlement_backfill_apply_outcome'],
            array_column($audits->rows, 'action')
        );
        self::assertSame([
            'after' => -1,
            'limit' => 100,
            'through_grant_id' => 10,
        ], $audits->rows[0]['data']);
        self::assertSame('failed', $audits->rows[1]['data']['status']);
        self::assertSame('invalid_arguments', $audits->rows[1]['data']['reason_code']);
        self::assertSame(0, $audits->rows[1]['data']['next_cursor']);
        self::assertSame(10, $audits->rows[1]['data']['through_grant_id']);
        self::assertFalse($audits->rows[1]['data']['complete']);
    }

    public function test_watermark_read_failure_is_audited_once_and_rethrows_the_original_exception(): void
    {
        [$service, $grants, , , $audits] = $this->service([], [], []);
        $grants->failWatermark = true;

        try {
            $service->applyBatch();
            self::fail('Watermark failure must stop apply.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Sensitive watermark database detail.', $exception->getMessage());
        }

        self::assertSame(
            ['entitlement_backfill_apply_intent', 'entitlement_backfill_apply_outcome'],
            array_column($audits->rows, 'action')
        );
        self::assertSame('failed', $audits->rows[1]['data']['status']);
        self::assertSame('watermark_read_failed', $audits->rows[1]['data']['reason_code']);
        self::assertNull($audits->rows[1]['data']['through_grant_id']);
        self::assertStringNotContainsString('Sensitive', json_encode($audits->rows, JSON_THROW_ON_ERROR));
    }

    public function test_post_intent_batch_failure_is_audited_once_without_skipping_or_leaking(): void
    {
        [$service, $grants, , , $audits] = $this->service([], [], []);
        $grants->failBatch = true;

        try {
            $service->applyBatch(0, 100, 5);
            self::fail('Batch read failure must stop apply.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Private grant query failure.', $exception->getMessage());
        }

        self::assertSame(
            ['entitlement_backfill_apply_intent', 'entitlement_backfill_apply_outcome'],
            array_column($audits->rows, 'action')
        );
        self::assertSame('failed', $audits->rows[1]['data']['status']);
        self::assertSame('grant_batch_read_failed', $audits->rows[1]['data']['reason_code']);
        self::assertSame(0, $audits->rows[1]['data']['next_cursor']);
        self::assertSame(5, $audits->rows[1]['data']['through_grant_id']);
        self::assertFalse($audits->rows[1]['data']['complete']);
        self::assertStringNotContainsString('Private', json_encode($audits->rows, JSON_THROW_ON_ERROR));
    }

    /** @return array{EntitlementBackfillService, object, object, object, array<int, array<string, mixed>>} */
    private function service(array $grantRows, array $sourceRows, array $feedRelations): array
    {
        $grants = new class($grantRows) extends GrantRepository {
            public array $batchCalls = [];
            public bool $failWatermark = false;
            public bool $failBatch = false;

            public function __construct(public array $rows)
            {
            }

            public function getEntitlementBackfillWatermark(): int
            {
                if ($this->failWatermark) {
                    throw new \RuntimeException('Sensitive watermark database detail.');
                }
                return max(array_column($this->rows, 'id') ?: [0]);
            }

            public function getEntitlementBackfillBatch(int $after, int $through, int $limit): array
            {
                if ($this->failBatch) {
                    throw new \RuntimeException('Private grant query failure.');
                }
                $this->batchCalls[] = [$after, $through, $limit];
                return array_slice(array_values(array_filter(
                    $this->rows,
                    static fn(array $row): bool => $row['id'] > $after && $row['id'] <= $through
                )), 0, $limit);
            }

            public function findByGrantKey(string $grantKey): ?array
            {
                return null;
            }
        };
        $sources = new class($sourceRows) extends GrantSourceRepository {
            public function __construct(private array $rows)
            {
            }

            public function getSourcesByGrant(int $grantId): array
            {
                return $this->rows[$grantId] ?? [];
            }
        };
        $edges = new class extends EntitlementEdgeRepository {
            public array $rows = [];
            public ?string $failResourceId = null;
            private int $nextId = 1;

            public function findByIdentity(array $identity): ?array
            {
                return $this->rows[$this->key($identity)] ?? null;
            }

            public function createOrReplay(array $data, ?array $comparisonFields = null): array
            {
                if ($data['resource_id'] === $this->failResourceId) {
                    throw new \RuntimeException('Private provider failure payload.');
                }
                $key = $this->key($data);
                if (isset($this->rows[$key])) {
                    return ['action' => 'ended_conflict', 'edge' => $this->rows[$key]];
                }
                $data['id'] = $this->nextId++;
                $this->rows[$key] = $data;
                return ['action' => 'created', 'edge' => $data];
            }

            public function resourceTransaction(array $resource, callable $callback): mixed
            {
                return $callback();
            }

            private function key(array $identity): string
            {
                return implode('|', array_map(
                    static fn(string $field): string => (string) $identity[$field],
                    ['user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id', 'feed_scope', 'source_type', 'source_id']
                ));
            }
        };
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $entitlements = new EntitlementService(
            $edges,
            $grants,
            new Clock(new \DateTimeImmutable('2026-07-22 12:00:00', $timezone), $timezone)
        );
        $audits = new class {
            public array $rows = [];
        };
        $service = new EntitlementBackfillService(
            $grants,
            $sources,
            $edges,
            $entitlements,
            static fn(int $feedId, int $planId): ?array => $feedRelations[$feedId] ?? null,
            static function (string $action, array $data) use ($audits): void {
                $audits->rows[] = ['action' => $action, 'data' => $data];
            }
        );

        return [$service, $grants, $sources, $edges, $audits];
    }

    private function grant(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'user_id' => 81,
            'plan_id' => 7,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '42',
            'feed_id' => 50,
            'status' => 'active',
            'starts_at' => '2026-06-01 10:00:00',
            'expires_at' => null,
            'drip_available_at' => null,
            'meta' => ['provider_access_owner' => 'fchub'],
            'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-07-22 10:00:00',
        ], $overrides);
    }
}
