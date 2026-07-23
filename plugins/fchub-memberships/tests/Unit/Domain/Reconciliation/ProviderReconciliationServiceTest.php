<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Reconciliation;

use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Domain\Reconciliation\Contracts\ProviderHealthExtensionInterface;
use FChubMemberships\Domain\Reconciliation\ProviderHealthCapability;
use FChubMemberships\Domain\Reconciliation\ProviderHealthObservation;
use FChubMemberships\Domain\Reconciliation\ProviderReconciliationService;
use FChubMemberships\Domain\Reconciliation\ProviderResource;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProviderReconciliationServiceTest extends PluginTestCase
{
    public function test_fixed_watermark_keyset_page_classifies_each_stacked_resource_once(): void
    {
        $this->requireContract();
        $edges = new ReconciliationEdgeRepository([
            $this->union(2, [$this->edge(['id' => 2]), $this->edge(['id' => 7, 'plan_id' => 8])]),
            $this->union(9, [$this->edge(['id' => 9, 'resource_id' => '42'])]),
            $this->union(13, [$this->edge(['id' => 13, 'resource_id' => '43'])]),
        ], 13);
        $service = $this->service($edges, states: ['41' => 'present', '42' => 'present', '43' => 'present']);

        $first = $service->scanPage(null, 2);
        $second = $service->scanPage($first['next_cursor'], 2);

        self::assertSame([2, 9], array_column($first['items'], 'cursor_id'));
        self::assertSame(2, $first['items'][0]['edge_count']);
        self::assertSame([13], array_column($second['items'], 'cursor_id'));
        self::assertNull($second['next_cursor']);
        self::assertSame([[0, 13, 3], [9, 13, 3]], $edges->pageCalls);
        self::assertSame(1, $edges->watermarkReads);
    }

    public function test_scan_classifies_health_ownership_provider_boundaries_and_operation_states(): void
    {
        $this->requireContract();
        $unions = [
            $this->union(1, [$this->edge(['id' => 1, 'resource_id' => 'active-absent'])]),
            $this->union(2, [$this->edge(['id' => 2, 'resource_id' => 'ended-present', 'lifecycle' => 'ended'])]),
            $this->union(3, [$this->edge(['id' => 3, 'resource_id' => 'healthy'])]),
            $this->union(4, [$this->edge([
                'id' => 4,
                'resource_id' => 'unsafe',
                'lifecycle' => 'ended',
                'assignment_provenance' => 'preexisting',
            ])]),
            $this->union(5, [$this->edge(['id' => 5, 'resource_id' => 'unknown'])]),
            $this->union(6, [$this->edge([
                'id' => 6,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '61',
            ])]),
            $this->union(7, [$this->edge([
                'id' => 7,
                'provider' => 'learndash',
                'resource_type' => 'ld_course',
                'resource_id' => '71',
            ])]),
            $this->union(8, [$this->edge([
                'id' => 8,
                'provider' => 'unavailable',
                'resource_type' => 'remote_group',
                'resource_id' => '81',
            ])]),
        ];
        $states = [
            'active-absent' => 'absent',
            'ended-present' => 'present',
            'healthy' => 'present',
            'unsafe' => 'present',
            'unknown' => 'unknown',
        ];
        $service = $this->service(new ReconciliationEdgeRepository($unions, 8), states: $states, unavailable: true);

        $items = $service->scanPage(null, 20)['items'];

        self::assertSame([
            'internal_active_provider_absent',
            'internal_ended_provider_present',
            'healthy',
            'unknown_ownership',
            'provider_unknown',
            'local_only',
            'provider_uncertified',
            'provider_unavailable',
        ], array_column($items, 'classification'));
        self::assertSame(['grant', 'revoke', null, null, null, null, null, null], array_column($items, 'repair_action'));
    }

    public function test_scan_surfaces_pending_processing_stale_retryable_and_terminal_operations(): void
    {
        $this->requireContract();
        $resources = [];
        $operations = [];
        foreach (['pending', 'processing', 'stale', 'retryable', 'terminal'] as $index => $name) {
            $id = $index + 1;
            $resources[] = $this->union($id, [$this->edge(['id' => $id, 'resource_id' => $name])]);
        }
        $operations['pending'] = $this->operation(['state' => 'pending']);
        $operations['processing'] = $this->operation([
            'state' => 'processing',
            'lease_expires_at' => '2026-07-22 13:00:00',
        ]);
        $operations['stale'] = $this->operation([
            'state' => 'processing',
            'lease_expires_at' => '2026-07-22 11:00:00',
        ]);
        $operations['retryable'] = $this->operation(['state' => 'failed', 'retryable' => true]);
        $operations['terminal'] = $this->operation(['state' => 'failed', 'retryable' => false]);
        $operationRepo = new ReconciliationOperationRepository($operations);
        $service = $this->service(
            new ReconciliationEdgeRepository($resources, 5),
            $operationRepo,
            states: array_fill_keys(array_keys($operations), 'present')
        );

        $items = $service->scanPage(null, 10)['items'];

        self::assertSame([
            'operation_pending',
            'operation_processing',
            'operation_stale',
            'operation_retryable_failed',
            'operation_terminal_failed',
        ], array_column($items, 'classification'));
    }

    public function test_dry_run_scan_performs_no_operation_schedule_audit_or_provider_mutation(): void
    {
        $this->requireContract();
        $timeline = [];
        $edges = new ReconciliationEdgeRepository([
            $this->union(1, [$this->edge(['id' => 1])]),
        ], 1);
        $operations = new ReconciliationOperationRepository([], $timeline);
        $worker = new ReconciliationWorker($timeline);
        $service = $this->service($edges, $operations, $worker, ['41' => 'absent'], timeline: $timeline);

        $result = $service->scanPage(null, 10);

        self::assertSame('internal_active_provider_absent', $result['items'][0]['classification']);
        self::assertSame([], $operations->created);
        self::assertSame([], $worker->scheduled);
        self::assertSame(['observe:41'], $timeline, 'Dry-run may observe provider state but must not write it.');
    }

    public function test_paused_and_term_ended_intent_never_proposes_a_grant(): void
    {
        $paused = new ReconciliationGrantRepository(['status' => 'paused', 'meta' => []]);
        $pausedService = $this->service(
            new ReconciliationEdgeRepository([
                $this->union(1, [$this->edge(['id' => 1, 'resource_id' => 'paused-absent'])]),
                $this->union(2, [$this->edge(['id' => 2, 'resource_id' => 'paused-present'])]),
            ], 2),
            states: ['paused-absent' => 'absent', 'paused-present' => 'present'],
            grants: $paused
        );

        $pausedItems = $pausedService->scanPage(null, 10)['items'];
        self::assertSame(['healthy', 'internal_paused_provider_present'], array_column($pausedItems, 'classification'));
        self::assertSame([null, 'suspend'], array_column($pausedItems, 'repair_action'));

        $termEnded = $this->service(
            new ReconciliationEdgeRepository([
                $this->union(3, [$this->edge([
                    'id' => 3,
                    'resource_id' => 'term-ended',
                    'policy' => ['membership_term_ends_at' => '2026-07-22 11:59:59'],
                ])]),
            ], 3),
            states: ['term-ended' => 'absent'],
            grants: new ReconciliationGrantRepository(['status' => 'active', 'meta' => []])
        );
        $termItem = $termEnded->scanPage(null, 10)['items'][0];
        self::assertSame('healthy', $termItem['classification']);
        self::assertNull($termItem['repair_action']);
    }

    public function test_paused_repair_revalidates_grant_under_lock_and_only_schedules_suspend_when_present(): void
    {
        $timeline = [];
        $edges = new ReconciliationEdgeRepository([], 7, [$this->edge(['id' => 7])], $timeline);
        $operations = new ReconciliationOperationRepository([], $timeline);
        $worker = new ReconciliationWorker($timeline);
        $grants = new ReconciliationGrantRepository(['status' => 'paused', 'meta' => []]);

        $absent = $this->service(
            $edges,
            $operations,
            $worker,
            ['41' => 'absent'],
            timeline: $timeline,
            grants: $grants
        )->repair($this->resource(), 'paused-absent', 'healthy');
        self::assertFalse($absent['success']);
        self::assertSame('repair_not_available', $absent['code']);
        self::assertSame([], $operations->created);

        $present = $this->service(
            $edges,
            $operations,
            $worker,
            ['41' => 'present'],
            timeline: $timeline,
            grants: $grants
        )->repair($this->resource(), 'paused-present', 'internal_paused_provider_present');
        self::assertTrue($present['success']);
        self::assertSame('suspend', $present['action']);
        self::assertSame('suspend', $operations->created[0]['desired_action']);
        self::assertGreaterThanOrEqual(2, $grants->reads, 'Scan and locked repair must both read aggregate state.');
    }

    public function test_grant_read_and_applied_pause_intent_are_fail_closed(): void
    {
        $throwingGrants = new ReconciliationGrantRepository(null, true);
        $unknown = $this->service(
            new ReconciliationEdgeRepository([$this->union(1, [$this->edge()])], 1),
            states: ['41' => 'absent'],
            grants: $throwingGrants
        )->scanPage(null, 10)['items'][0];
        self::assertSame('provider_unknown', $unknown['classification']);
        self::assertNull($unknown['repair_action']);

        $suspended = $this->service(
            new ReconciliationEdgeRepository([$this->union(1, [$this->edge()])], 1),
            new ReconciliationOperationRepository(['41' => $this->operation(['desired_action' => 'suspend'])]),
            states: ['41' => 'absent'],
            grants: new ReconciliationGrantRepository(null)
        )->scanPage(null, 10)['items'][0];
        self::assertSame('healthy', $suspended['classification']);
        self::assertNull($suspended['repair_action']);

        $resumed = $this->service(
            new ReconciliationEdgeRepository([$this->union(1, [$this->edge()])], 1),
            new ReconciliationOperationRepository(['41' => $this->operation(['desired_action' => 'resume'])]),
            states: ['41' => 'absent'],
            grants: new ReconciliationGrantRepository(null)
        )->scanPage(null, 10)['items'][0];
        self::assertSame('internal_active_provider_absent', $resumed['classification']);
        self::assertSame('resume', $resumed['repair_action']);
    }

    public function test_extension_capability_is_called_once_and_all_extension_failures_become_unknown(): void
    {
        $capabilityCalls = 0;
        $throwingCapability = new class($capabilityCalls) implements ProviderHealthExtensionInterface {
            public function __construct(private int &$calls)
            {
            }
            public function capability(): ProviderHealthCapability
            {
                $this->calls++;
                throw new \RuntimeException('private capability failure');
            }
            public function observe(ProviderResource $resource): ProviderHealthObservation
            {
                throw new \LogicException('Must not observe after capability failure.');
            }
        };
        $timeline = [];
        $service = new ProviderReconciliationService(
            new ReconciliationEdgeRepository([
                $this->union(1, [$this->edge(['id' => 1])]),
                $this->union(2, [$this->edge(['id' => 2, 'resource_id' => '42'])]),
            ], 2),
            new ReconciliationOperationRepository(),
            new ReconciliationWorker($timeline),
            [$throwingCapability],
            new Clock(new \DateTimeImmutable('2026-07-22 12:00:00', new \DateTimeZone('UTC'))),
            null,
            new ReconciliationGrantRepository(null)
        );

        $items = $service->scanPage(null, 10)['items'];
        self::assertSame(['provider_unknown', 'provider_unknown'], array_column($items, 'classification'));
        self::assertSame(1, $capabilityCalls);

        $throwingObserve = new class implements ProviderHealthExtensionInterface {
            public function capability(): ProviderHealthCapability
            {
                return new ProviderHealthCapability('fluentcrm', true, true, ['fluentcrm_tag']);
            }
            public function observe(ProviderResource $resource): ProviderHealthObservation
            {
                throw new \RuntimeException('private observation failure');
            }
        };
        $secondTimeline = [];
        $observed = new ProviderReconciliationService(
            new ReconciliationEdgeRepository([$this->union(1, [$this->edge()])], 1),
            new ReconciliationOperationRepository(),
            new ReconciliationWorker($secondTimeline),
            [$throwingObserve],
            new Clock(new \DateTimeImmutable('2026-07-22 12:00:00', new \DateTimeZone('UTC'))),
            null,
            new ReconciliationGrantRepository(null)
        );
        self::assertSame('provider_unknown', $observed->scanPage(null, 10)['items'][0]['classification']);
    }

    public function test_uncertified_extension_is_never_observed_or_repaired(): void
    {
        $observations = 0;
        $extension = new class($observations) implements ProviderHealthExtensionInterface {
            public function __construct(private int &$observations)
            {
            }
            public function capability(): ProviderHealthCapability
            {
                return new ProviderHealthCapability('fluentcrm', false, true, ['fluentcrm_tag']);
            }
            public function observe(ProviderResource $resource): ProviderHealthObservation
            {
                $this->observations++;
                return new ProviderHealthObservation('present', 'must_not_be_used');
            }
        };
        $timeline = [];
        $service = new ProviderReconciliationService(
            new ReconciliationEdgeRepository([$this->union(1, [$this->edge()])], 1),
            new ReconciliationOperationRepository(['41' => $this->operation(['state' => 'pending'])]),
            new ReconciliationWorker($timeline),
            [$extension],
            new Clock(new \DateTimeImmutable('2026-07-22 12:00:00', new \DateTimeZone('UTC'))),
            null,
            new ReconciliationGrantRepository(null)
        );

        $item = $service->scanPage(null, 10)['items'][0];
        self::assertSame('provider_uncertified', $item['classification']);
        self::assertNull($item['repair_action']);
        self::assertSame(0, $observations);
    }

    public function test_repair_refuses_when_locked_classification_differs_from_the_scan(): void
    {
        $timeline = [];
        $lockedEnded = $this->edge(['id' => 7, 'lifecycle' => 'ended']);
        $edges = new ReconciliationEdgeRepository([], 7, [$lockedEnded], $timeline);
        $operations = new ReconciliationOperationRepository([], $timeline);
        $worker = new ReconciliationWorker($timeline);
        $service = $this->service(
            $edges,
            $operations,
            $worker,
            ['41' => 'present'],
            timeline: $timeline
        );

        $result = $service->repair(
            $this->resource(),
            'classification-flipped',
            'internal_active_provider_absent'
        );

        self::assertFalse($result['success']);
        self::assertSame('reconciliation_state_changed', $result['code']);
        self::assertSame('internal_ended_provider_present', $result['current_classification']);
        self::assertSame([], $operations->created);
        self::assertSame([], $worker->scheduled);
    }

    public function test_repair_reuses_actionable_operations_and_reports_non_actionable_states_truthfully(): void
    {
        $cases = [
            'pending' => [$this->operation(['id' => 81, 'state' => 'pending']), 'operation_pending', true, 'rescheduled'],
            'stale' => [$this->operation([
                'id' => 82,
                'state' => 'processing',
                'lease_expires_at' => '2026-07-22 11:00:00',
            ]), 'operation_stale', true, 'rescheduled'],
            'null-lease-stale' => [$this->operation([
                'id' => 86,
                'state' => 'processing',
                'lease_expires_at' => null,
            ]), 'operation_stale', true, 'rescheduled'],
            'zero-date-stale' => [$this->operation([
                'id' => 87,
                'state' => 'processing',
                'lease_expires_at' => '0000-00-00 00:00:00',
            ]), 'operation_stale', true, 'rescheduled'],
            'retryable' => [$this->operation([
                'id' => 83,
                'state' => 'failed',
                'retryable' => true,
            ]), 'operation_retryable_failed', true, 'rescheduled'],
            'processing' => [$this->operation([
                'id' => 84,
                'state' => 'processing',
                'lease_expires_at' => '2026-07-22 13:00:00',
            ]), 'operation_processing', false, 'in_progress'],
            'terminal' => [$this->operation([
                'id' => 85,
                'state' => 'failed',
                'retryable' => false,
            ]), 'operation_terminal_failed', false, 'terminal'],
        ];

        foreach ($cases as $name => [$operation, $expectedClassification, $scheduled, $status]) {
            $timeline = [];
            $edges = new ReconciliationEdgeRepository([], 7, [$this->edge(['id' => 7])], $timeline);
            $operations = new ReconciliationOperationRepository(['41' => $operation], $timeline);
            $worker = new ReconciliationWorker($timeline);
            $service = $this->service(
                $edges,
                $operations,
                $worker,
                ['41' => 'absent'],
                timeline: $timeline
            );

            $result = $service->repair($this->resource(), 'operation-' . $name, $expectedClassification);

            self::assertSame($status, $result['status'], $name);
            self::assertSame([], $operations->created, $name);
            self::assertSame($scheduled ? [(int) $operation['id']] : [], $worker->scheduled, $name);
            self::assertSame(
                str_contains($name, 'stale') ? [(int) $operation['id']] : [],
                $operations->recovered,
                $name
            );
        }
    }

    public function test_same_request_applied_operation_is_not_reported_as_newly_scheduled(): void
    {
        $timeline = [];
        $edges = new ReconciliationEdgeRepository([], 7, [$this->edge(['id' => 7])], $timeline);
        $operations = new ReconciliationOperationRepository([], $timeline);
        $digest = hash('sha256', 'already-applied');
        $operations->seedExisting(7, 'grant', 'provider_reconcile:' . $digest, $this->operation([
            'id' => 97,
            'edge_id' => 7,
            'desired_action' => 'grant',
            'state' => 'applied',
        ]));
        $worker = new ReconciliationWorker($timeline);
        $service = $this->service($edges, $operations, $worker, ['41' => 'absent'], timeline: $timeline);

        $result = $service->repair(
            $this->resource(),
            'already-applied',
            'internal_active_provider_absent'
        );

        self::assertTrue($result['success']);
        self::assertSame('already_applied', $result['status']);
        self::assertSame(97, $result['operation_id']);
        self::assertSame([], $worker->scheduled);
    }

    public function test_stale_processing_is_not_scheduled_when_recovery_cas_loses(): void
    {
        $timeline = [];
        $operation = $this->operation([
            'id' => 88,
            'state' => 'processing',
            'lease_expires_at' => null,
        ]);
        $operations = new ReconciliationOperationRepository(['41' => $operation], $timeline);
        $operations->recoverResult = false;
        $worker = new ReconciliationWorker($timeline);
        $service = $this->service(
            new ReconciliationEdgeRepository([], 7, [$this->edge(['id' => 7])], $timeline),
            $operations,
            $worker,
            ['41' => 'absent'],
            timeline: $timeline
        );

        $result = $service->repair($this->resource(), 'lost-stale-cas', 'operation_stale');

        self::assertSame('in_progress', $result['status']);
        self::assertSame([88], $operations->recovered);
        self::assertSame([], $worker->scheduled);
    }

    public function test_explicit_repair_revalidates_under_lock_audits_and_only_schedules_durable_operation(): void
    {
        $this->requireContract();
        $timeline = [];
        $edge = $this->edge(['id' => 7]);
        $edges = new ReconciliationEdgeRepository([], 7, [$edge], $timeline);
        $operations = new ReconciliationOperationRepository([], $timeline);
        $worker = new ReconciliationWorker($timeline);
        $service = $this->service($edges, $operations, $worker, ['41' => 'absent'], timeline: $timeline);

        $result = $service->repair($this->resource(), 'repair-001', 'internal_active_provider_absent');

        self::assertTrue($result['success']);
        self::assertSame('scheduled', $result['status']);
        self::assertSame('grant', $result['action']);
        self::assertSame(91, $result['operation_id']);
        $digest = hash('sha256', 'repair-001');
        self::assertSame([
            'lock',
            'read-resource',
            'observe:41',
            'audit:provider_repair_intent',
            'create:grant:provider_reconcile:' . $digest,
            'schedule:91',
            'audit:provider_repair_scheduled',
            'unlock',
        ], $timeline);
        self::assertSame(0, $worker->processCalls);

        $service->repair($this->resource(), 'repair-001', 'internal_active_provider_absent');
        self::assertCount(1, $operations->created, 'The same request ID must reuse one durable operation.');
    }

    public function test_repair_uses_only_a_bounded_request_digest_in_audit_and_outbox(): void
    {
        $timeline = [];
        $audits = [];
        $raw = str_repeat('private-token-', 14) . 'x';
        self::assertSame(197, strlen($raw));
        $raw = substr($raw, 0, 191);
        $edges = new ReconciliationEdgeRepository([], 7, [$this->edge(['id' => 7])], $timeline);
        $operations = new ReconciliationOperationRepository([], $timeline);
        $worker = new ReconciliationWorker($timeline);
        $service = $this->service(
            $edges,
            $operations,
            $worker,
            ['41' => 'absent'],
            timeline: $timeline,
            audits: $audits
        );

        $result = $service->repair($this->resource(), $raw, 'internal_active_provider_absent');

        self::assertTrue($result['success']);
        $expectedDigest = hash('sha256', $raw);
        self::assertSame('provider_reconcile:' . $expectedDigest, $operations->origins[0]);
        self::assertLessThanOrEqual(100, strlen($operations->origins[0]));
        self::assertSame($expectedDigest, $audits[0]['request_digest']);
        self::assertArrayNotHasKey('request_id', $audits[0]);
        self::assertStringNotContainsString($raw, json_encode($audits));

        $service->repair($this->resource(), $raw, 'internal_active_provider_absent');
        self::assertCount(1, $operations->created, 'A 191-character request token must replay the same digest identity.');
    }

    public function test_repair_uses_locked_state_and_refuses_unsafe_detach(): void
    {
        $this->requireContract();
        $timeline = [];
        $ended = $this->edge([
            'id' => 7,
            'lifecycle' => 'ended',
            'owner' => 'preexisting',
            'assignment_provenance' => 'preexisting',
        ]);
        $edges = new ReconciliationEdgeRepository([], 7, [$ended], $timeline, true);
        $operations = new ReconciliationOperationRepository([], $timeline);
        $worker = new ReconciliationWorker($timeline);
        $service = $this->service($edges, $operations, $worker, ['41' => 'present'], timeline: $timeline);

        $result = $service->repair($this->resource(), 'repair-unsafe', 'unknown_ownership');

        self::assertFalse($result['success']);
        self::assertSame('refused', $result['status']);
        self::assertSame('unsafe_provider_detach', $result['code']);
        self::assertSame([], $operations->created);
        self::assertSame([], $worker->scheduled);
    }

    public function test_sql_provider_and_audit_failures_are_generic_and_do_not_schedule(): void
    {
        $this->requireContract();
        foreach (['sql', 'provider', 'audit'] as $failure) {
            $timeline = [];
            $edges = new ReconciliationEdgeRepository([], 7, [$this->edge(['id' => 7])], $timeline);
            $edges->throwOnRead = $failure === 'sql';
            $operations = new ReconciliationOperationRepository([], $timeline);
            $worker = new ReconciliationWorker($timeline);
            $states = $failure === 'provider' ? ['41' => new \RuntimeException('secret')] : ['41' => 'absent'];
            $service = $this->service(
                $edges,
                $operations,
                $worker,
                $states,
                timeline: $timeline,
                throwAudit: $failure === 'audit'
            );

            $result = $service->repair(
                $this->resource(),
                'repair-' . $failure,
                'internal_active_provider_absent'
            );

            self::assertFalse($result['success'], $failure);
            self::assertSame(
                $failure === 'provider' ? 'reconciliation_state_changed' : 'provider_reconciliation_failed',
                $result['code'],
                $failure
            );
            self::assertArrayNotHasKey('message', $result, $failure);
            self::assertSame([], $operations->created, $failure);
            self::assertSame([], $worker->scheduled, $failure);
        }
    }

    private function requireContract(): void
    {
        self::assertTrue(class_exists(ProviderReconciliationService::class), 'Task 8 reconciliation service is missing.');
    }

    private function service(
        ReconciliationEdgeRepository $edges,
        ?ReconciliationOperationRepository $operations = null,
        ?ReconciliationWorker $worker = null,
        array $states = [],
        bool $unavailable = false,
        array &$timeline = [],
        bool $throwAudit = false,
        ?ReconciliationGrantRepository $grants = null,
        array &$audits = []
    ): ProviderReconciliationService {
        $operations ??= new ReconciliationOperationRepository([], $timeline);
        $worker ??= new ReconciliationWorker($timeline);
        $crm = new class(
            $states,
            static function (string $resourceId) use (&$timeline): void {
                $timeline[] = 'observe:' . $resourceId;
            }
        ) implements ProviderHealthExtensionInterface {
            private \Closure $onObserve;

            public function __construct(private array $states, callable $onObserve)
            {
                $this->onObserve = \Closure::fromCallable($onObserve);
            }

            public function capability(): ProviderHealthCapability
            {
                return new ProviderHealthCapability('fluentcrm', true, true, ['fluentcrm_tag']);
            }

            public function observe(ProviderResource $resource): ProviderHealthObservation
            {
                ($this->onObserve)($resource->resourceId);
                $state = $this->states[$resource->resourceId] ?? 'absent';
                if ($state instanceof \Throwable) {
                    throw $state;
                }
                return new ProviderHealthObservation($state, 'test_observation');
            }
        };
        $unavailableExtension = new class implements ProviderHealthExtensionInterface {
            public function capability(): ProviderHealthCapability
            {
                return new ProviderHealthCapability('unavailable', true, false, ['remote_group']);
            }

            public function observe(ProviderResource $resource): ProviderHealthObservation
            {
                throw new \LogicException('Unavailable providers must not be observed.');
            }
        };
        $audit = static function (
            string $entityType,
            int $entityId,
            string $action,
            array $oldValue,
            array $newValue,
            Clock $clock,
            ?string $context
        ) use (&$timeline, $throwAudit, &$audits): void {
            $timeline[] = 'audit:' . $action;
            if ($throwAudit) {
                throw new \RuntimeException('private audit failure');
            }
            self::assertArrayNotHasKey('user_id', $newValue);
            self::assertArrayNotHasKey('error', $newValue);
            $audits[] = $newValue;
        };

        return new ProviderReconciliationService(
            $edges,
            $operations,
            $worker,
            $unavailable ? [$crm, $unavailableExtension] : [$crm],
            new Clock(
                new \DateTimeImmutable('2026-07-22 12:00:00', new \DateTimeZone('UTC')),
                new \DateTimeZone('UTC')
            ),
            $audit,
            $grants ?? new ReconciliationGrantRepository(null)
        );
    }

    private function union(int $cursorId, array $edges): array
    {
        $first = $edges[0];
        return [
            'cursor_id' => $cursorId,
            'resource' => $this->resource([
                'user_id' => $first['user_id'],
                'provider' => $first['provider'],
                'resource_type' => $first['resource_type'],
                'resource_id' => $first['resource_id'],
            ]),
            'edges' => $edges,
        ];
    }

    private function resource(array $overrides = []): array
    {
        return array_replace([
            'user_id' => 17,
            'provider' => 'fluentcrm',
            'resource_type' => 'fluentcrm_tag',
            'resource_id' => '41',
        ], $overrides);
    }

    private function edge(array $overrides = []): array
    {
        return array_replace($this->resource(), [
            'id' => 1,
            'plan_id' => 5,
            'feed_id' => 12,
            'feed_scope' => 'product',
            'source_type' => 'subscription',
            'source_id' => 91,
            'owner' => 'fchub',
            'assignment_provenance' => 'fchub_created',
            'lifecycle' => 'active',
            'starts_at' => '2026-07-01 00:00:00',
            'expires_at' => null,
            'drip_available_at' => null,
            'policy' => [],
        ], $overrides);
    }

    private function operation(array $overrides = []): array
    {
        return array_replace([
            'id' => 81,
            'edge_id' => 1,
            'desired_action' => 'grant',
            'state' => 'applied',
            'attempt_count' => 1,
            'retryable' => false,
            'lease_expires_at' => null,
            'next_retry_at' => null,
            'last_error_code' => 'provider_operation_applied',
        ], $overrides);
    }
}

class ReconciliationEdgeRepository extends EntitlementEdgeRepository
{
    public array $pageCalls = [];
    public int $watermarkReads = 0;
    public bool $throwOnRead = false;

    public function __construct(
        private array $unions,
        private int $watermark,
        private array $lockedEdges = [],
        private array &$timeline = [],
        private bool $unsafe = false
    ) {
    }

    public function maxReconciliationEdgeId(): int
    {
        $this->watermarkReads++;
        return $this->watermark;
    }

    public function getReconciliationResourcePage(int $afterId, int $throughId, int $limit): array
    {
        $this->pageCalls[] = [$afterId, $throughId, $limit];
        return array_values(array_filter(
            $this->unions,
            static fn(array $union): bool => $union['cursor_id'] > $afterId && $union['cursor_id'] <= $throughId
        ));
    }

    public function getByResource(int $userId, string $provider, string $resourceType, string $resourceId): array
    {
        $this->timeline[] = 'read-resource';
        if ($this->throwOnRead) {
            throw new \RuntimeException('private sql failure');
        }
        return $this->lockedEdges;
    }

    public function resourceTransaction(array $resource, callable $callback): mixed
    {
        $this->timeline[] = 'lock';
        try {
            return $callback();
        } finally {
            $this->timeline[] = 'unlock';
        }
    }

    public function hasUnsafeAssignmentEvidence(
        int $userId,
        string $provider,
        string $resourceType,
        string $resourceId
    ): bool {
        return $this->unsafe;
    }
}

class ReconciliationOperationRepository extends ProviderOperationRepository
{
    public array $created = [];
    public array $origins = [];
    public array $recovered = [];
    public bool $recoverResult = true;
    private array $createdByKey = [];

    public function __construct(private array $operations = [], private array &$timeline = [])
    {
    }

    public function findLatestForResource(array $resource): ?array
    {
        return $this->operations[(string) $resource['resource_id']] ?? null;
    }

    public function createOrFind(
        int $edgeId,
        string $desiredAction,
        string $originEvent,
        ?\DateTimeImmutable $eligibleAt = null
    ): array {
        $key = $edgeId . '|' . $desiredAction . '|' . $originEvent;
        $this->origins[] = $originEvent;
        $this->timeline[] = 'create:' . $desiredAction . ':' . $originEvent;
        if (isset($this->createdByKey[$key])) {
            return $this->createdByKey[$key];
        }
        $operation = ['id' => 91, 'edge_id' => $edgeId, 'desired_action' => $desiredAction, 'state' => 'pending'];
        $this->created[] = $operation;
        return $this->createdByKey[$key] = $operation;
    }

    public function seedExisting(
        int $edgeId,
        string $desiredAction,
        string $originEvent,
        array $operation
    ): void {
        $this->createdByKey[$edgeId . '|' . $desiredAction . '|' . $originEvent] = $operation;
    }

    public function recoverStaleProcessing(int $operationId): bool
    {
        $this->recovered[] = $operationId;
        return $this->recoverResult;
    }
}

class ReconciliationGrantRepository extends GrantRepository
{
    public int $reads = 0;

    public function __construct(private ?array $grant, private bool $throw = false)
    {
    }

    public function findByGrantKey(string $grantKey): ?array
    {
        $this->reads++;
        if ($this->throw) {
            throw new \RuntimeException('private aggregate grant failure');
        }

        return $this->grant;
    }
}

class ReconciliationWorker extends ProviderOperationWorker
{
    public array $scheduled = [];
    public int $processCalls = 0;

    public function __construct(private array &$timeline)
    {
    }

    public function schedulePersisted(int $operationId): void
    {
        $this->scheduled[] = $operationId;
        $this->timeline[] = 'schedule:' . $operationId;
    }

    public function process(int $operationId): \FChubMemberships\Domain\ProviderOperationOutcome
    {
        $this->processCalls++;
        throw new \LogicException('Reconciliation must never process providers directly.');
    }
}
