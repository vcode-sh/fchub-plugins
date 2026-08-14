<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Entitlement;

use FChubMemberships\Domain\Access\ResourceAccessPolicy;
use FChubMemberships\Domain\Access\ResourceAccessPolicyResolver;
use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Entitlement\EntitlementService;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class EntitlementServiceTest extends PluginTestCase
{
    public function test_activate_preserves_typed_numeric_collisions_stacked_plans_and_scoped_feeds(): void
    {
        [$service, $edges] = $this->service();
        $identities = [
            $this->identity(),
            $this->identity(['source_type' => 'subscription']),
            $this->identity(['plan_id' => 8]),
            $this->identity(['feed_id' => 12]),
            $this->identity(['feed_scope' => 'global']),
        ];

        foreach ($identities as $identity) {
            self::assertSame('created', $service->activate($identity, $this->ownership())['action']);
        }

        self::assertCount(5, $edges->rows);
    }

    public function test_resource_mutations_use_the_resource_scoped_transaction(): void
    {
        [$service, $edges] = $this->service();
        $identity = $this->identity();

        $service->activate($identity, $this->ownership());
        $service->end($identity, 'refund');

        self::assertSame([
            [9, 'wordpress_core', 'post', '42'],
            [9, 'wordpress_core', 'post', '42'],
        ], $edges->resourceLocks);
    }

    public function test_provider_assignment_provenance_is_derived_from_complete_sibling_evidence_inside_the_resource_lock(): void
    {
        [$service, $edges] = $this->service();
        $sibling = $this->identity(['provider' => 'test_provider', 'resource_type' => 'course']);
        $stacked = $this->identity([
            'provider' => 'test_provider',
            'resource_type' => 'course',
            'plan_id' => 8,
            'source_id' => 56,
        ]);
        $service->activate($sibling, $this->ownership());
        $edges->traceProvenanceEvidence = true;

        $result = $service->activateFromProviderObservation(
            $stacked,
            $this->ownership(['assignment_provenance' => 'unknown']),
            true
        );

        self::assertSame('fchub_created', $result['edge']['assignment_provenance']);
        self::assertSame([
            'active_siblings:locked',
            'assignment_evidence:locked',
            'edge_insert:locked',
        ], array_slice($edges->provenanceTrace, 0, 3));
    }

    public function test_provider_assignment_observation_fails_closed_without_complete_safe_sibling_evidence(): void
    {
        [$service, $edges] = $this->service();
        $resource = [
            'provider' => 'test_provider',
            'resource_type' => 'course',
        ];

        $native = $service->activateFromProviderObservation(
            $this->identity($resource),
            $this->ownership(['assignment_provenance' => 'unknown']),
            true
        );
        self::assertSame('preexisting', $native['edge']['assignment_provenance']);

        $unknown = $service->activateFromProviderObservation(
            $this->identity(array_merge($resource, ['resource_id' => '43'])),
            $this->ownership(['assignment_provenance' => 'unknown']),
            null
        );
        self::assertSame('unknown', $unknown['edge']['assignment_provenance']);

        $edges->unsafeAssignmentEvidence = true;
        $mixed = $service->activateFromProviderObservation(
            $this->identity(array_merge($resource, ['plan_id' => 8, 'source_id' => 56])),
            $this->ownership(['assignment_provenance' => 'unknown']),
            true
        );
        self::assertSame('preexisting', $mixed['edge']['assignment_provenance']);
    }

    public function test_unreadable_sibling_evidence_rolls_back_without_claiming_provider_assignment(): void
    {
        [$service, $edges] = $this->service();
        $resource = ['provider' => 'test_provider', 'resource_type' => 'course'];
        $service->activate($this->identity($resource), $this->ownership());
        $edges->throwOnAssignmentEvidence = true;

        try {
            $service->activateFromProviderObservation(
                $this->identity(array_merge($resource, ['plan_id' => 8, 'source_id' => 56])),
                $this->ownership(['assignment_provenance' => 'unknown']),
                true
            );
            self::fail('Unreadable durable assignment evidence must abort edge creation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('assignment evidence unavailable', $exception->getMessage());
        }

        self::assertCount(1, $edges->rows);
        self::assertSame('rollback', $edges->transactionOutcomes[array_key_last($edges->transactionOutcomes)]);
    }

    public function test_manual_trial_order_and_subscription_are_valid_source_types(): void
    {
        [$service, $edges] = $this->service();

        foreach ([
            ['manual', 0],
            ['trial', 901],
            ['order', 55],
            ['subscription', 55],
        ] as [$sourceType, $sourceId]) {
            $service->activate($this->identity([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]), $this->ownership());
        }

        self::assertSame(
            ['manual', 'trial', 'order', 'subscription'],
            array_column($edges->rows, 'source_type')
        );
    }

    public function test_exact_replay_preserves_immutable_owner_and_ended_edge_cannot_reactivate(): void
    {
        [$service] = $this->service();
        $identity = $this->identity();

        self::assertSame('created', $service->activate($identity, $this->ownership())['action']);
        self::assertSame('replayed', $service->activate($identity, $this->ownership())['action']);
        self::assertSame(
            'immutable_conflict',
            $service->activate($identity, $this->ownership(['owner' => 'preexisting']))['action']
        );
        self::assertSame('ended', $service->end($identity, 'refund')['action']);
        self::assertSame('ended_conflict', $service->activate($identity, $this->ownership())['action']);
    }

    public function test_explicit_immutable_edge_attributes_conflict_when_replayed_with_different_values(): void
    {
        [$service] = $this->service();
        $identity = $this->identity();
        $base = $this->ownership([
            'starts_at' => '2026-07-22 10:00:00',
            'expires_at' => '2026-08-22 10:00:00',
            'drip_available_at' => '2026-07-23 10:00:00',
            'policy' => ['cancel_behavior' => 'wait_validity'],
        ]);
        $service->activate($identity, $base);

        foreach ([
            ['starts_at' => '2026-07-22 11:00:00'],
            ['expires_at' => '2026-09-22 10:00:00'],
            ['drip_available_at' => '2026-07-24 10:00:00'],
            ['policy' => ['cancel_behavior' => 'immediate']],
        ] as $change) {
            self::assertSame(
                'immutable_conflict',
                $service->activate($identity, array_merge($base, $change))['action']
            );
        }
    }

    public function test_omitted_default_start_does_not_conflict_on_later_idempotent_replay(): void
    {
        [$service, $edges, $grants] = $this->service();
        $identity = $this->identity();
        $service->activate($identity, $this->ownership());

        $timezone = new \DateTimeZone('Europe/Warsaw');
        $later = new EntitlementService(
            $edges,
            $grants,
            new Clock(new \DateTimeImmutable('2026-07-23 12:00:00', $timezone), $timezone)
        );

        $result = $later->activate($identity, $this->ownership());

        self::assertSame('replayed', $result['action']);
        self::assertSame('2026-07-22 12:00:00', $result['edge']['starts_at']);
    }

    public function test_latest_active_edge_controls_mirror_and_ending_it_restores_the_survivor(): void
    {
        [$service, , $grants] = $this->service();
        $older = $this->identity(['plan_id' => 7, 'source_type' => 'order', 'source_id' => 55]);
        $newer = $this->identity(['plan_id' => 8, 'source_type' => 'subscription', 'source_id' => 55]);

        $service->activate($older, $this->ownership(['starts_at' => '2026-07-20 10:00:00']));
        $service->activate($newer, $this->ownership(['starts_at' => '2026-07-21 10:00:00']));

        self::assertSame(8, $grants->grant['plan_id']);
        self::assertSame('subscription', $grants->grant['source_type']);
        self::assertSame([55], $grants->grant['source_ids']);

        $service->end($newer, 'subscription_cancelled');

        self::assertSame('active', $grants->grant['status']);
        self::assertSame(7, $grants->grant['plan_id']);
        self::assertSame('order', $grants->grant['source_type']);
        self::assertSame([55], $grants->grant['source_ids']);
    }

    public function test_access_status_projects_only_effective_edges_and_pauses_the_aggregate_when_none_survive(): void
    {
        [$service, , $grants] = $this->service();
        self::assertTrue(method_exists($service, 'setAccessStatus'));
        $first = $service->activate(
            $this->identity(['plan_id' => 7, 'source_id' => 55]),
            $this->ownership(['starts_at' => '2026-07-20 10:00:00'])
        )['edge'];
        $second = $service->activate(
            $this->identity(['plan_id' => 8, 'source_id' => 56]),
            $this->ownership(['starts_at' => '2026-07-21 10:00:00'])
        )['edge'];

        self::assertSame(1, $service->setAccessStatus([$second], 'paused'));
        self::assertSame('active', $grants->grant['status']);
        self::assertSame(7, $grants->grant['plan_id']);

        self::assertSame(1, $service->setAccessStatus([$first], 'paused'));
        self::assertSame('paused', $grants->grant['status']);

        self::assertSame(1, $service->setAccessStatus([$first], 'active'));
        self::assertSame('active', $grants->grant['status']);
        self::assertSame(7, $grants->grant['plan_id']);
    }

    public function test_access_status_mutation_clears_the_shared_effective_access_count_cache(): void
    {
        [$service, , $grants] = $this->service();
        $first = $service->activate(
            $this->identity(['plan_id' => 7, 'source_id' => 55]),
            $this->ownership(['starts_at' => '2026-07-20 10:00:00'])
        )['edge'];
        $service->activate(
            $this->identity(['plan_id' => 8, 'source_id' => 56]),
            $this->ownership(['starts_at' => '2026-07-21 10:00:00'])
        );
        $countReads = 0;
        $countingGrants = new class($countReads) extends GrantRepository {
            public function __construct(private int &$countReads)
            {
            }

            public function countDistinctUsersWithResourceAccessBatch(array $policies): array
            {
                $this->countReads++;
                return ['resource' => $this->countReads];
            }
        };
        $policyResolver = new class extends ResourceAccessPolicyResolver {
            public function __construct()
            {
            }

            public function resolve(string $provider, string $resourceType, string $resourceId): ResourceAccessPolicy
            {
                return new ResourceAccessPolicy($provider, $resourceType, $resourceId);
            }

            public function resolveBatch(array $resources): array
            {
                $policies = [];
                foreach ($resources as $key => $resource) {
                    $policies[$key] = $this->resolve(
                        (string) $resource['provider'],
                        (string) $resource['resource_type'],
                        (string) $resource['resource_id']
                    );
                }

                return $policies;
            }
        };
        $evaluator = new AccessEvaluator(
            $countingGrants,
            new PlanRuleResolver(),
            new ProtectionRuleRepository(),
            null,
            $policyResolver
        );
        $resources = [
            'resource' => [
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '42',
            ],
        ];

        self::assertSame(1, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        self::assertSame(1, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        self::assertSame(1, $countReads);

        self::assertSame(1, $service->setAccessStatus([$first], 'paused'));
        self::assertSame('active', $grants->grant['status'], 'The stacked survivor keeps the aggregate active.');
        self::assertSame(2, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        self::assertSame(2, $countReads);
    }

    public function test_representative_tie_breaks_on_highest_edge_id_and_filters_mixed_source_ids(): void
    {
        [$service, , $grants] = $this->service();
        $startsAt = '2026-07-21 10:00:00';

        $service->activate(
            $this->identity(['plan_id' => 7, 'source_type' => 'order', 'source_id' => 71]),
            $this->ownership(['starts_at' => $startsAt])
        );
        $service->activate(
            $this->identity(['plan_id' => 8, 'source_type' => 'subscription', 'source_id' => 55]),
            $this->ownership(['starts_at' => $startsAt])
        );
        $service->activate(
            $this->identity(['plan_id' => 9, 'source_type' => 'subscription', 'source_id' => 56]),
            $this->ownership(['starts_at' => $startsAt])
        );

        self::assertSame(9, $grants->grant['plan_id']);
        self::assertSame('subscription', $grants->grant['source_type']);
        self::assertSame([55, 56], $grants->grant['source_ids']);
    }

    public function test_aggregate_windows_are_the_union_of_bounded_active_edges(): void
    {
        [$service, , $grants] = $this->service();

        $service->activate($this->identity(['plan_id' => 7]), $this->ownership([
            'starts_at' => '2026-07-05 10:00:00',
            'expires_at' => '2026-08-01 10:00:00',
            'drip_available_at' => '2026-07-10 10:00:00',
        ]));
        $service->activate($this->identity(['plan_id' => 8]), $this->ownership([
            'starts_at' => '2026-07-01 10:00:00',
            'expires_at' => '2026-09-01 10:00:00',
            'drip_available_at' => '2026-07-08 10:00:00',
        ]));

        self::assertSame(7, $grants->grant['plan_id'], 'The latest start still owns source display.');
        self::assertSame('2026-07-01 10:00:00', $grants->grant['starts_at']);
        self::assertSame('2026-09-01 10:00:00', $grants->grant['expires_at']);
        self::assertSame('2026-07-08 10:00:00', $grants->grant['drip_available_at']);
    }

    public function test_unbounded_active_edge_keeps_union_windows_unbounded_and_survives_future_edge_end(): void
    {
        [$service, , $grants] = $this->service();
        $accessible = $this->identity(['plan_id' => 7, 'source_id' => 55]);
        $future = $this->identity(['plan_id' => 8, 'source_id' => 56]);

        $service->activate($accessible, $this->ownership([
            'starts_at' => null,
            'expires_at' => null,
            'drip_available_at' => null,
        ]));
        $service->activate($future, $this->ownership([
            'starts_at' => '2026-08-01 10:00:00',
            'expires_at' => '2026-08-31 10:00:00',
            'drip_available_at' => '2026-08-01 10:00:00',
        ]));

        self::assertSame(8, $grants->grant['plan_id'], 'The future edge remains the display representative.');
        self::assertNull($grants->grant['starts_at']);
        self::assertNull($grants->grant['expires_at']);
        self::assertNull($grants->grant['drip_available_at']);

        $service->end($future, 'future_offer_removed');

        self::assertSame(7, $grants->grant['plan_id']);
        self::assertSame('active', $grants->grant['status']);
        self::assertNull($grants->grant['starts_at']);
        self::assertNull($grants->grant['expires_at']);
        self::assertNull($grants->grant['drip_available_at']);
    }

    public function test_provider_access_owner_mirror_is_conservative(): void
    {
        [$service, , $grants] = $this->service();

        $service->activate($this->identity(), $this->ownership());
        self::assertSame('fchub', $grants->grant['meta']['provider_access_owner']);

        $service->activate(
            $this->identity(['plan_id' => 8]),
            $this->ownership(['assignment_provenance' => 'preexisting'])
        );
        self::assertSame('unknown', $grants->grant['meta']['provider_access_owner']);
    }

    public function test_non_fchub_edge_owners_prevent_an_optimistic_fchub_aggregate_owner(): void
    {
        [$service, , $grants] = $this->service();

        foreach (['external_unknown', 'preexisting'] as $index => $owner) {
            $service->activate(
                $this->identity(['plan_id' => 7 + $index]),
                $this->ownership(['owner' => $owner])
            );

            self::assertSame('unknown', $grants->grant['meta']['provider_access_owner']);
        }

        self::assertSame([55], $grants->grant['source_ids']);
    }

    public function test_ending_final_edge_applies_terminal_status_and_clears_sources(): void
    {
        [$service, , $grants] = $this->service();
        $identity = $this->identity();
        $service->activate($identity, $this->ownership());

        $service->end($identity, 'validity_elapsed', 'expired');

        self::assertSame('expired', $grants->grant['status']);
        self::assertSame([], $grants->grant['source_ids']);
        self::assertSame('unknown', $grants->grant['meta']['provider_access_owner']);
    }

    public function test_historical_ended_edge_is_inserted_ended_without_touching_the_legacy_aggregate(): void
    {
        [$service, $edges, $grants] = $this->service();
        $grants->grant = ['id' => 91, 'status' => 'expired', 'meta' => ['provider_access_owner' => 'fchub']];

        $result = $service->recordHistoricalEdge($this->identity(), $this->ownership([
            'lifecycle' => 'ended',
            'starts_at' => '2026-06-01 10:00:00',
            'expires_at' => '2026-07-01 10:00:00',
            'ended_at' => '2026-07-01 10:00:00',
            'end_reason' => 'legacy_expired',
        ]));

        self::assertSame('created', $result['action']);
        self::assertSame('ended', $result['edge']['lifecycle']);
        self::assertSame('2026-07-01 10:00:00', $result['edge']['ended_at']);
        self::assertSame('legacy_expired', $result['edge']['end_reason']);
        self::assertSame('expired', $grants->grant['status']);
        self::assertSame(['commit'], $edges->transactionOutcomes);
    }

    public function test_exact_historical_ended_edge_replays_but_immutable_change_conflicts(): void
    {
        [$service, $edges] = $this->service();
        $attributes = $this->ownership([
            'lifecycle' => 'ended',
            'ended_at' => '2026-07-01 10:00:00',
            'end_reason' => 'legacy_revoked',
        ]);

        self::assertSame('created', $service->recordHistoricalEdge($this->identity(), $attributes)['action']);
        self::assertSame('replayed', $service->recordHistoricalEdge($this->identity(), $attributes)['action']);
        self::assertSame(
            'immutable_conflict',
            $service->recordHistoricalEdge(
                $this->identity(),
                array_merge($attributes, ['owner' => 'external_unknown'])
            )['action']
        );
        self::assertCount(1, $edges->rows);
    }

    public function test_edge_and_mirror_changes_roll_back_together_when_mirror_persistence_fails(): void
    {
        [$service, $edges, $grants] = $this->service();
        $grants->failWrites = true;

        try {
            $service->activate($this->identity(), $this->ownership());
            self::fail('Mirror persistence failure must fail the entitlement transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The entitlement aggregate mirror could not be persisted.', $exception->getMessage());
        }

        self::assertSame([], $edges->rows);
        self::assertSame(['rollback'], $edges->transactionOutcomes);
    }

    public function test_invalid_identity_and_ownership_values_fail_before_persistence(): void
    {
        [$service, $edges] = $this->service();

        foreach ([
            [$this->identity(['feed_scope' => 'site']), $this->ownership()],
            [$this->identity(['user_id' => 0]), $this->ownership()],
            [$this->identity(), $this->ownership(['owner' => 'optimistic'])],
            [$this->identity(), $this->ownership(['assignment_provenance' => 'maybe'])],
        ] as [$identity, $ownership]) {
            try {
                $service->activate($identity, $ownership);
                self::fail('Invalid entitlement input must be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $edges->rows);
    }

    public function test_verified_renewal_extends_active_expiry_monotonically_and_audits_sanitised_values(): void
    {
        [$service] = $this->service();
        $identity = $this->identity(['source_type' => 'subscription', 'source_id' => 55]);
        $created = $service->activate($identity, $this->ownership([
            'expires_at' => '2026-08-22 12:00:00',
            'policy' => ['subscription_id' => 55, 'cancel_behavior' => 'wait_validity'],
        ]));
        $audit = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$audit): int {
            if (str_ends_with($table, 'fchub_membership_audit_log')) {
                $audit[] = $data;
            }
            return 1;
        };

        $result = $service->extendActiveExpiry($created['edge'], '2026-09-22 12:00:00', str_repeat('a', 64));
        $unchanged = $service->extendActiveExpiry($result['edge'], '2026-09-01 12:00:00', str_repeat('b', 64));

        self::assertSame('extended', $result['action']);
        self::assertSame('2026-09-22 12:00:00', $result['edge']['expires_at']);
        self::assertSame('unchanged', $unchanged['action']);
        self::assertCount(1, $audit);
        self::assertSame('entitlement_edge', $audit[0]['entity_type']);
        self::assertSame('renewal_validity_extended', $audit[0]['action']);
        self::assertSame(['expires_at' => '2026-08-22 12:00:00'], json_decode($audit[0]['old_value'], true));
        self::assertSame([
            'expires_at' => '2026-09-22 12:00:00',
            'event_receipt' => str_repeat('a', 64),
        ], json_decode($audit[0]['new_value'], true));
    }

    public function test_same_renewal_receipt_replays_from_required_audit_without_mutating_edge_policy(): void
    {
        [$service] = $this->service();
        $identity = $this->identity(['source_type' => 'subscription', 'source_id' => 55]);
        $created = $service->activate($identity, $this->ownership([
            'expires_at' => '2026-08-22 12:00:00',
            'policy' => ['subscription_id' => 55, 'validity_mode' => 'fixed_duration', 'validity_days' => 31],
        ]));
        $receipt = str_repeat('9', 64);
        $audit = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (&$audit, $receipt): int {
            foreach ($audit as $row) {
                $newValue = json_decode((string) $row['new_value'], true);
                if ((int) $row['entity_id'] === 1
                    && ($newValue['event_receipt'] ?? null) === $receipt
                    && str_contains($query, $receipt)
                ) {
                    return 1;
                }
            }
            return 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$audit): int {
            if (str_ends_with($table, 'fchub_membership_audit_log')) {
                $audit[] = $data;
            }
            return 1;
        };

        $extended = $service->extendActiveExpiry($created['edge'], '2026-09-22 12:00:00', $receipt);
        $replayed = $service->extendActiveExpiry($extended['edge'], '2026-10-22 12:00:00', $receipt);
        $distinct = $service->extendActiveExpiry(
            $replayed['edge'],
            '2026-10-22 12:00:00',
            str_repeat('7', 64)
        );

        self::assertSame('extended', $extended['action']);
        self::assertSame('replayed', $replayed['action']);
        self::assertSame('2026-09-22 12:00:00', $replayed['edge']['expires_at']);
        self::assertSame('extended', $distinct['action']);
        self::assertSame('2026-10-22 12:00:00', $distinct['edge']['expires_at']);
        self::assertCount(2, $audit);
        foreach (array_merge([
            'owner',
            'assignment_provenance',
            'policy',
        ], ['user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id', 'feed_scope', 'source_type', 'source_id']) as $field) {
            self::assertSame($created['edge'][$field], $replayed['edge'][$field], "{$field} must remain immutable.");
            self::assertSame($created['edge'][$field], $distinct['edge'][$field], "{$field} must remain immutable.");
        }
        self::assertNotEmpty(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'get_var'
                && str_contains($query[1], 'renewal_successor_created')
        ));
    }

    public function test_renewal_receipt_audit_read_failure_blocks_extension_fail_closed(): void
    {
        [$service, $edges] = $this->service();
        $identity = $this->identity(['source_type' => 'subscription', 'source_id' => 55]);
        $created = $service->activate($identity, $this->ownership([
            'expires_at' => '2026-08-22 12:00:00',
            'policy' => ['subscription_id' => 55],
        ]));
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query, \wpdb $wpdb): null {
            if (str_contains($query, 'fchub_membership_audit_log')) {
                $wpdb->last_error = 'audit unavailable';
            }
            return null;
        };

        try {
            $service->extendActiveExpiry($created['edge'], '2026-09-22 12:00:00', str_repeat('8', 64));
            self::fail('An unreadable required audit receipt must block renewal mutation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The required lifecycle audit receipt could not be read.', $exception->getMessage());
        }

        self::assertSame('2026-08-22 12:00:00', $edges->findByIdentity($identity)['expires_at']);
    }

    public function test_terminal_renewal_creates_an_audited_successor_from_order_identity(): void
    {
        [$service] = $this->service();
        $identity = $this->identity(['source_type' => 'subscription', 'source_id' => 55]);
        $created = $service->activate($identity, $this->ownership([
            'expires_at' => '2026-08-22 12:00:00',
            'policy' => ['subscription_id' => 55, 'cancel_behavior' => 'immediate'],
        ]));
        $service->end($identity, 'expired', 'expired');
        $audit = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$audit): int {
            if (str_ends_with($table, 'fchub_membership_audit_log')) {
                $audit[] = $data;
            }
            return 1;
        };

        $result = $service->createRenewalSuccessor(
            $created['edge'],
            901,
            55,
            '2026-09-22 12:00:00',
            str_repeat('c', 64)
        );

        self::assertSame('created', $result['action']);
        self::assertSame('order', $result['edge']['source_type']);
        self::assertSame(901, $result['edge']['source_id']);
        self::assertSame(55, $result['edge']['policy']['subscription_id']);
        self::assertSame('immediate', $result['edge']['policy']['cancel_behavior']);
        self::assertCount(1, $audit);
        self::assertSame('renewal_successor_created', $audit[0]['action']);
        self::assertStringNotContainsString('provider_payload', serialize($audit[0]));
    }

    public function test_renewal_successor_refuses_non_positive_order_identity(): void
    {
        [$service] = $this->service();
        $this->expectException(\InvalidArgumentException::class);

        $service->createRenewalSuccessor(
            array_merge($this->identity(), $this->ownership(), [
                'id' => 7,
                'lifecycle' => 'ended',
                'policy' => ['subscription_id' => 55],
            ]),
            0,
            55,
            '2026-09-22 12:00:00',
            str_repeat('d', 64)
        );
    }

    public function test_unbounded_active_edge_is_not_shortened_by_a_renewal_date(): void
    {
        [$service] = $this->service();
        $created = $service->activate(
            $this->identity(['source_type' => 'subscription', 'source_id' => 55]),
            $this->ownership(['expires_at' => null, 'policy' => ['subscription_id' => 55]])
        );

        $result = $service->extendActiveExpiry(
            $created['edge'],
            '2026-09-22 12:00:00',
            str_repeat('e', 64)
        );

        self::assertSame('unchanged', $result['action']);
        self::assertNull($result['edge']['expires_at']);
    }

    public function test_required_lifecycle_audit_failure_rolls_back_expiry_and_successor_mutations(): void
    {
        [$service, $edges] = $this->service();
        $identity = $this->identity(['source_type' => 'subscription', 'source_id' => 55]);
        $created = $service->activate($identity, $this->ownership([
            'expires_at' => '2026-08-22 12:00:00',
            'policy' => ['subscription_id' => 55],
        ]));
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table): int|false {
            return str_ends_with($table, 'fchub_membership_audit_log') ? false : 1;
        };

        try {
            $service->extendActiveExpiry($created['edge'], '2026-09-22 12:00:00', str_repeat('f', 64));
            self::fail('Required audit failure must abort the expiry mutation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The required lifecycle audit entry could not be persisted.', $exception->getMessage());
        }
        self::assertSame('2026-08-22 12:00:00', $edges->findByIdentity($identity)['expires_at']);

        $service->end($identity, 'expired', 'expired');
        try {
            $service->createRenewalSuccessor(
                $created['edge'],
                901,
                55,
                '2026-09-22 12:00:00',
                str_repeat('a', 64)
            );
            self::fail('Required audit failure must abort successor creation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The required lifecycle audit entry could not be persisted.', $exception->getMessage());
        }
        self::assertCount(1, $edges->rows);
    }

    public function test_lifecycle_renewal_reuses_projection_receipts_for_count_and_payment_recovery(): void
    {
        [$service, , $grants] = $this->service();
        $created = $service->activate(
            $this->identity(['source_type' => 'subscription', 'source_id' => 55]),
            $this->ownership(['expires_at' => '2026-08-22 12:00:00', 'policy' => ['subscription_id' => 55]])
        );
        $grants->grant['renewal_count'] = 2;
        $grants->grant['meta']['payment_incident'] = [
            'subscription_id' => 55,
            'failed_at' => '2026-08-01 12:00:00',
            'recovered_at' => null,
        ];

        $first = $service->projectLifecycleRenewal($created['edge'], str_repeat('a', 64));
        $duplicate = $service->projectLifecycleRenewal($created['edge'], str_repeat('a', 64));

        self::assertTrue($first['renewed']);
        self::assertSame(3, $first['renewal_count']);
        self::assertSame(3, $first['grant']['meta']['payment_incident']['recovery_renewal_count']);
        self::assertSame('2026-07-22 12:00:00', $first['grant']['meta']['payment_incident']['recovered_at']);
        self::assertFalse($duplicate['renewed']);
        self::assertSame(3, $duplicate['renewal_count']);
    }

    /** @return array{EntitlementService, EntitlementEdgeRepository&object, GrantRepository&object} */
    public function test_end_refuses_a_terminal_status_outside_expired_and_revoked(): void
    {
        [$service] = $this->service();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Terminal grant status must be expired or revoked.');

        $service->end($this->identity(), 'Owner request', 'paused');
    }

    public function test_end_refuses_a_reason_that_says_nothing(): void
    {
        [$service] = $this->service();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Entitlement end reason must contain 1 to 191 characters.');

        $service->end($this->identity(), '   ');
    }

    public function test_end_refuses_a_reason_longer_than_the_column_holds(): void
    {
        [$service] = $this->service();

        // 191 is the stored width; one more has to be refused rather than
        // silently truncated.
        self::assertSame('not_found', $service->end($this->identity(), str_repeat('x', 191))['action']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Entitlement end reason must contain 1 to 191 characters.');

        $service->end($this->identity(), str_repeat('x', 192));
    }

    public function test_a_lifecycle_receipt_must_be_a_sha256_hash(): void
    {
        [$service] = $this->service();

        // Both halves of the shape matter: the alphabet and the exact width.
        // Checking only a non-hex string would let a short hex value through.
        foreach ([
            'non-hex characters' => 'not-a-hash',
            'right width, wrong alphabet' => str_repeat('z', 64),
            'one character short' => str_repeat('a', 63),
            'one character long' => str_repeat('a', 65),
            'a short hex string' => 'abc123',
            'nothing at all' => '',
        ] as $why => $receipt) {
            try {
                $service->extendActiveExpiry($this->identity(), '2027-01-01 00:00:00', $receipt);
                self::fail("A receipt with {$why} must be refused.");
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Lifecycle event receipt must be a SHA-256 hash.', $exception->getMessage(), $why);
            }
        }
    }

    public function test_a_lifecycle_date_must_use_the_storage_format(): void
    {
        [$service] = $this->service();

        // A date that parses is not the same as a date that is real: the
        // overflowing ones parse and only report warnings.
        foreach ([
            'no time part' => '2027-01-01',
            'an ISO separator' => '2027-01-01T00:00:00',
            'a day that does not exist' => '2027-02-30 00:00:00',
            'an impossible hour' => '2027-01-01 25:00:00',
            'a month that does not exist' => '2027-13-01 00:00:00',
            'words instead of a date' => 'tomorrow',
            'nothing at all' => '',
        ] as $why => $date) {
            try {
                $service->extendActiveExpiry($this->identity(), $date, self::receipt());
                self::fail("A date with {$why} must be refused.");
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Lifecycle expiry must use the storage timestamp format.', $exception->getMessage(), $why);
            }
        }
    }

    public function test_access_status_must_be_one_the_storage_recognises(): void
    {
        [$service, $edges] = $this->service();
        $service->activate($this->identity(), $this->ownership());
        $edge = array_values($edges->rows)[0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Entitlement access status change is invalid.');

        $service->setAccessStatus([$edge], 'nonsense');
    }

    public function test_extending_never_moves_an_expiry_earlier_or_leaves_it_where_it_is(): void
    {
        [$service, $edges] = $this->service();
        $service->activate($this->identity(), $this->ownership(['expires_at' => '2027-06-01 00:00:00']));

        // The same instant is no more an extension than an earlier one, so
        // both sides of the boundary have to be refused.
        foreach ([
            'an earlier expiry' => '2027-01-01 00:00:00',
            'the expiry it already has' => '2027-06-01 00:00:00',
            'one second earlier' => '2027-05-31 23:59:59',
        ] as $why => $candidate) {
            $edges->extendCalls = 0;

            $result = $service->extendActiveExpiry($this->identity(), $candidate, self::receipt());

            self::assertSame('unchanged', $result['action'], $why);
            self::assertSame(0, $edges->extendCalls, "Storage must not be touched for {$why}.");
            self::assertSame('2027-06-01 00:00:00', array_values($edges->rows)[0]['expires_at'], $why);
        }

        // One second later is a real extension, so the guard must not swallow it.
        self::assertSame(
            'extended',
            $service->extendActiveExpiry($this->identity(), '2027-06-01 00:00:01', self::receipt())['action']
        );
    }

    public function test_extending_refuses_an_edge_that_is_no_longer_active(): void
    {
        [$service, $edges] = $this->service();
        $service->activate($this->identity(), $this->ownership(['expires_at' => '2027-06-01 00:00:00']));
        $service->end($this->identity(), 'Owner request');
        $edges->extendCalls = 0;

        $result = $service->extendActiveExpiry($this->identity(), '2028-01-01 00:00:00', self::receipt());

        self::assertSame('not_active', $result['action']);
        self::assertSame(0, $edges->extendCalls, 'An ended edge must not reach the extension write.');
    }

    public function test_a_renewal_successor_requires_a_predecessor_that_has_ended(): void
    {
        [$service, $edges] = $this->service();
        $service->activate($this->identity(), $this->ownership());
        $before = count($edges->rows);

        $result = $service->createRenewalSuccessor(
            $this->identity(),
            77,
            123,
            '2028-01-01 00:00:00',
            self::receipt()
        );

        self::assertSame('predecessor_not_ended', $result['action']);
        self::assertCount($before, $edges->rows, 'A live predecessor must not spawn a successor edge.');
    }

    public function test_ending_something_already_ended_does_not_resync_the_aggregate(): void
    {
        [$service, , $grants] = $this->service();
        $service->activate($this->identity(), $this->ownership());
        $service->end($this->identity(), 'Owner request');
        $lookups = $grants->lookups;

        $result = $service->end($this->identity(), 'Owner request');

        self::assertSame('already_ended', $result['action']);
        self::assertSame($lookups, $grants->lookups, 'A no-op end must not touch the compatibility aggregate.');
    }

    public function test_a_conflicting_activation_projects_no_compatibility(): void
    {
        [$service, , $grants] = $this->service();
        $service->activate($this->identity(), $this->ownership());
        $lookups = $grants->lookups;

        $conflict = $service->activate($this->identity(), $this->ownership(['owner' => 'preexisting']));

        self::assertSame('immutable_conflict', $conflict['action']);
        self::assertSame($lookups, $grants->lookups, 'A rejected activation must project nothing.');
    }

    public function test_an_existing_edge_keeps_the_owner_and_provenance_it_was_recorded_with(): void
    {
        [$service, $edges] = $this->service();
        $service->activate($this->identity(), [
            'owner' => 'preexisting',
            'assignment_provenance' => 'preexisting',
        ]);

        // Re-observing the provider must reuse what is already recorded rather
        // than deriving provenance again, or FCHub starts believing it created
        // access that was already there.
        $result = $service->activateFromProviderObservation($this->identity(), $this->ownership(), true);

        self::assertSame('replayed', $result['action']);
        $edge = array_values($edges->rows)[0];
        self::assertSame('preexisting', $edge['owner']);
        self::assertSame('preexisting', $edge['assignment_provenance']);
    }

    private static function receipt(): string
    {
        return str_repeat('a', 64);
    }

    private function service(): array
    {
        $edges = new class extends EntitlementEdgeRepository {
            public array $rows = [];
            public array $transactionOutcomes = [];
            public array $resourceLocks = [];
            public array $provenanceTrace = [];
            public int $extendCalls = 0;
            public bool $traceProvenanceEvidence = false;
            public bool $unsafeAssignmentEvidence = false;
            public bool $throwOnAssignmentEvidence = false;
            private bool $insideResourceTransaction = false;
            private int $nextId = 1;

            public function findByIdentity(array $identity): ?array
            {
                $key = $this->key($identity);
                return $this->rows[$key] ?? null;
            }

            public function findById(int $id): ?array
            {
                foreach ($this->rows as $row) {
                    if ((int) $row['id'] === $id) {
                        return $row;
                    }
                }
                return null;
            }

            public function createOrReplay(array $data, ?array $comparisonFields = null): array
            {
                if ($this->traceProvenanceEvidence) {
                    $this->provenanceTrace[] = 'edge_insert:'
                        . ($this->insideResourceTransaction ? 'locked' : 'unlocked');
                }
                $key = $this->key($data);
                $existing = $this->rows[$key] ?? null;
                if ($existing) {
                    $comparisonFields ??= [
                        'owner',
                        'assignment_provenance',
                        'starts_at',
                        'expires_at',
                        'drip_available_at',
                        'policy',
                    ];
                    foreach (array_unique(array_merge(
                        ['owner', 'assignment_provenance'],
                        $comparisonFields
                    )) as $field) {
                        if (($existing[$field] ?? null) !== ($data[$field] ?? null)) {
                            return ['action' => 'immutable_conflict', 'edge' => $existing];
                        }
                    }
                    if ($existing['lifecycle'] === 'ended') {
                        return ['action' => 'ended_conflict', 'edge' => $existing];
                    }
                    return ['action' => 'replayed', 'edge' => $existing];
                }

                $data['id'] = $this->nextId++;
                $this->rows[$key] = $data;
                return ['action' => 'created', 'edge' => $data];
            }

            public function endByIdentity(array $identity, string $endedAt, string $reason): array
            {
                $key = $this->key($identity);
                if (!isset($this->rows[$key])) {
                    return ['action' => 'not_found', 'edge' => null];
                }
                if ($this->rows[$key]['lifecycle'] === 'ended') {
                    return ['action' => 'already_ended', 'edge' => $this->rows[$key]];
                }
                $this->rows[$key]['lifecycle'] = 'ended';
                $this->rows[$key]['ended_at'] = $endedAt;
                $this->rows[$key]['end_reason'] = $reason;
                $this->rows[$key]['updated_at'] = $endedAt;
                return ['action' => 'ended', 'edge' => $this->rows[$key]];
            }

            public function extendActiveExpiryById(int $edgeId, ?string $currentExpiry, string $newExpiry, string $updatedAt): array
            {
                // Storage repeats the service's own precondition, so several
                // defects return the same action while still reaching the
                // write. Counting the calls is what separates them.
                $this->extendCalls++;
                foreach ($this->rows as $key => $row) {
                    if ((int) $row['id'] !== $edgeId || $row['lifecycle'] !== 'active') {
                        continue;
                    }
                    if (($row['expires_at'] ?? null) !== $currentExpiry) {
                        return ['action' => 'changed', 'edge' => $row];
                    }
                    if ($currentExpiry !== null && strcmp($newExpiry, $currentExpiry) <= 0) {
                        return ['action' => 'unchanged', 'edge' => $row];
                    }
                    $this->rows[$key]['expires_at'] = $newExpiry;
                    $this->rows[$key]['updated_at'] = $updatedAt;
                    return ['action' => 'extended', 'edge' => $this->rows[$key]];
                }
                return ['action' => 'not_active', 'edge' => null];
            }

            public function setAccessStatusByIds(
                array $edgeIds,
                string $accessStatus,
                string $updatedAt
            ): int {
                $changed = 0;
                foreach ($this->rows as $key => $row) {
                    if (!in_array((int) $row['id'], $edgeIds, true)
                        || $row['lifecycle'] !== 'active'
                        || ($row['access_status'] ?? 'active') === $accessStatus
                    ) {
                        continue;
                    }
                    $this->rows[$key]['access_status'] = $accessStatus;
                    $this->rows[$key]['updated_at'] = $updatedAt;
                    $changed++;
                }

                return $changed;
            }

            public function getActiveByResource(
                int $userId,
                string $provider,
                string $resourceType,
                string $resourceId
            ): array {
                if ($this->traceProvenanceEvidence) {
                    $this->provenanceTrace[] = 'active_siblings:'
                        . ($this->insideResourceTransaction ? 'locked' : 'unlocked');
                }
                return array_values(array_filter(
                    $this->rows,
                    static fn(array $row): bool => $row['user_id'] === $userId
                        && $row['provider'] === $provider
                        && $row['resource_type'] === $resourceType
                        && $row['resource_id'] === $resourceId
                        && $row['lifecycle'] === 'active'
                ));
            }

            public function hasUnsafeAssignmentEvidence(
                int $userId,
                string $provider,
                string $resourceType,
                string $resourceId
            ): bool {
                if ($this->traceProvenanceEvidence) {
                    $this->provenanceTrace[] = 'assignment_evidence:'
                        . ($this->insideResourceTransaction ? 'locked' : 'unlocked');
                }
                if ($this->throwOnAssignmentEvidence) {
                    throw new \RuntimeException('assignment evidence unavailable');
                }

                return $this->unsafeAssignmentEvidence;
            }

            public function transaction(callable $callback): mixed
            {
                $before = $this->rows;
                try {
                    $result = $callback();
                    $this->transactionOutcomes[] = 'commit';
                    return $result;
                } catch (\Throwable $exception) {
                    $this->rows = $before;
                    $this->transactionOutcomes[] = 'rollback';
                    throw $exception;
                }
            }

            public function resourceTransaction(array $resource, callable $callback): mixed
            {
                $this->resourceLocks[] = [
                    (int) $resource['user_id'],
                    (string) $resource['provider'],
                    (string) $resource['resource_type'],
                    (string) $resource['resource_id'],
                ];

                $wasInside = $this->insideResourceTransaction;
                $this->insideResourceTransaction = true;
                try {
                    return $this->transaction($callback);
                } finally {
                    $this->insideResourceTransaction = $wasInside;
                }
            }

            private function key(array $identity): string
            {
                return implode('|', array_map(
                    static fn(string $field): string => (string) $identity[$field],
                    ['user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id', 'feed_scope', 'source_type', 'source_id']
                ));
            }
        };

        $grants = new class extends GrantRepository {
            public ?array $grant = null;
            public bool $failWrites = false;
            /** Every aggregate sync starts by reading the grant, so this counts syncs. */
            public int $lookups = 0;

            public function findByGrantKey(string $grantKey): ?array
            {
                $this->lookups++;
                return $this->grant;
            }

            public function create(array $data): int
            {
                if ($this->failWrites) {
                    return 0;
                }
                $this->grant = array_merge($data, ['id' => 91]);
                return 91;
            }

            public function update(int $id, array $data): bool
            {
                if ($this->failWrites) {
                    return false;
                }
                $this->grant = array_merge($this->grant ?? [], $data);
                return true;
            }
        };

        $timezone = new \DateTimeZone('Europe/Warsaw');
        $clock = new Clock(new \DateTimeImmutable('2026-07-22 12:00:00', $timezone), $timezone);

        return [new EntitlementService($edges, $grants, $clock), $edges, $grants];
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

    private function ownership(array $overrides = []): array
    {
        return array_merge([
            'owner' => 'fchub',
            'assignment_provenance' => 'fchub_created',
        ], $overrides);
    }
}
