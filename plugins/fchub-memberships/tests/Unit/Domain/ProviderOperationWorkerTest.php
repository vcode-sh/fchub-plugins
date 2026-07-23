<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\ProviderOperationClaimResult;
use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProviderOperationWorkerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestProviderAdapter::reset();
    }

    public function test_enqueue_persists_before_scheduling_the_unique_action(): void
    {
        $events = [];
        $operations = new TestProviderOperationRepository($events);
        $worker = $this->worker(
            $operations,
            [$this->edge()],
            static function (int $operationId) use (&$events): void {
                $events[] = 'schedule:' . $operationId;
            }
        );

        $operation = $worker->enqueue(7, 'grant', 'subscription_activated:91');

        self::assertSame(9, $operation['id']);
        self::assertSame(['persist', 'schedule:9'], $events);
    }

    public function test_future_grant_is_persisted_deferred_without_scheduling_or_adapter_work(): void
    {
        $events = [];
        $operations = new TestProviderOperationRepository($events);
        $worker = $this->worker($operations, [$this->edge()]);

        $operation = $worker->enqueue(
            7,
            'grant',
            'subscription_activated:91',
            new \DateTimeImmutable('2030-01-02 12:00:00', new \DateTimeZone('UTC'))
        );

        self::assertSame('deferred', $operation['state']);
        self::assertSame(['persist'], $events);
        self::assertSame(0, $operations->claimCount);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_drip_unlock_makes_the_resource_grant_operation_eligible_then_uses_the_same_worker(): void
    {
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->operation['state'] = 'deferred';
        $operations->grantOperationIds = [9];

        $outcomes = $worker->unlockDeferredGrant([
            'id' => 71,
            'user_id' => 21,
            'provider' => 'fluentcrm',
            'resource_type' => 'fluentcrm_tag',
            'resource_id' => '31',
        ], ['notify_at' => '2026-03-14 12:30:00']);

        self::assertSame([9], $operations->madeEligibleIds);
        self::assertSame('applied', $outcomes[9]->status);
        self::assertSame(1, TestProviderAdapter::$mutations);
    }

    public function test_persisted_operation_can_be_scheduled_without_creating_or_processing_it_again(): void
    {
        $events = [];
        $operations = new TestProviderOperationRepository($events);
        $worker = $this->worker(
            $operations,
            [$this->edge()],
            static function (int $operationId) use (&$events): void {
                $events[] = 'schedule:' . $operationId;
            }
        );

        self::assertTrue(method_exists($worker, 'schedulePersisted'));
        $worker->schedulePersisted(9);

        self::assertSame(['schedule:9'], $events);
        self::assertSame(0, $operations->claimCount);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_wordpress_local_only_edge_never_creates_a_normal_operation(): void
    {
        $events = [];
        $operations = new TestProviderOperationRepository($events);
        $edge = $this->edge(['provider' => 'wordpress_core', 'resource_type' => 'post']);
        $worker = $this->worker($operations, [$edge]);

        self::assertNull($worker->enqueue(7, 'grant', 'order_paid:91'));
        self::assertSame([], $events);
    }

    public function test_unexpected_stored_wordpress_operation_is_terminal_without_adapter_call(): void
    {
        [$worker, $operations] = $this->processingWorker(
            $this->edge(['provider' => 'wordpress_core', 'resource_type' => 'post'])
        );
        $operations->olderActionable = true;

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('local_only_provider_operation', $outcome->code);
        self::assertSame(0, TestProviderAdapter::$mutations);
        self::assertSame('terminal-failure', $operations->lastOutcome?->status);
    }

    public function test_learndash_intent_is_durable_then_terminal_until_certified(): void
    {
        $events = [];
        $operations = new TestProviderOperationRepository($events);
        $edge = $this->edge(['provider' => 'learndash', 'resource_type' => 'ld_course']);
        $worker = $this->worker($operations, [$edge]);

        self::assertSame(9, $worker->enqueue(7, 'grant', 'order_paid:91')['id']);
        $outcome = $worker->process(9);

        self::assertSame(['persist', 'schedule:9'], $events);
        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('provider_not_certified', $outcome->code);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_learndash_suspend_intent_is_durable_terminal_and_never_reports_success(): void
    {
        $events = [];
        $operations = new TestProviderOperationRepository($events);
        $edge = $this->edge(['provider' => 'learndash', 'resource_type' => 'ld_course']);
        $worker = $this->worker($operations, [$edge]);

        self::assertSame(9, $worker->enqueue(7, 'suspend', 'membership_status:suspend:71')['id']);
        $outcome = $worker->process(9);

        self::assertSame(['persist', 'schedule:9'], $events);
        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('provider_not_certified', $outcome->code);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_grant_checks_explicit_assignment_then_applies_once(): void
    {
        [$worker] = $this->processingWorker($this->edge());

        $outcome = $worker->process(9);

        self::assertSame('applied', $outcome->status);
        self::assertSame(1, TestProviderAdapter::$mutations);
        self::assertTrue(TestProviderAdapter::$assigned);
        self::assertSame(2, TestProviderAdapter::$checks);
    }

    public function test_already_assigned_grant_is_idempotent_after_a_crash(): void
    {
        TestProviderAdapter::$assigned = true;
        [$worker] = $this->processingWorker($this->edge());

        $outcome = $worker->process(9);

        self::assertSame('already-applied', $outcome->status);
        self::assertSame(0, TestProviderAdapter::$mutations);
        self::assertSame(1, TestProviderAdapter::$checks);
    }

    public function test_crash_after_mutation_is_recovered_by_explicit_assignment_check(): void
    {
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->failOutcomeWrites = 1;

        $first = $worker->process(9);
        $second = $worker->process(9);

        self::assertSame('retryable-failure', $first->status);
        self::assertSame('provider_operation_state_not_persisted', $first->code);
        self::assertSame('already-applied', $second->status);
        self::assertSame(1, TestProviderAdapter::$mutations);
    }

    public function test_delayed_revoke_rechecks_current_edges_and_does_not_detach(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge(['lifecycle' => 'ended']);
        [$worker, $operations] = $this->processingWorker($edge, [$this->edge(['id' => 8])], 'revoke');
        $operations->olderActionable = true;

        $outcome = $worker->process(9);

        self::assertSame('already-applied', $outcome->status);
        self::assertSame('desired_action_superseded', $outcome->code);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_revoke_refuses_to_detach_assignment_not_created_by_fchub(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge([
            'lifecycle' => 'ended',
            'assignment_provenance' => 'preexisting',
        ]);
        [$worker] = $this->processingWorker($edge, [], 'revoke');

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('unsafe_provider_detach', $outcome->code);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_revoke_requires_fchub_edge_ownership_as_well_as_created_provenance(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge([
            'lifecycle' => 'ended',
            'owner' => 'preexisting',
            'assignment_provenance' => 'fchub_created',
        ]);
        [$worker] = $this->processingWorker($edge, [], 'revoke');

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('unsafe_provider_detach', $outcome->code);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_resource_wide_unsafe_evidence_blocks_provider_detach(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge(['lifecycle' => 'ended']);
        [$worker, , $edges] = $this->processingWorker($edge, [], 'revoke');
        $edges->unsafeAssignmentEvidence = true;

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('unsafe_provider_detach', $outcome->code);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_unreadable_edge_state_is_retryable_without_detach_or_superseded_noop(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge(['lifecycle' => 'ended']);
        [$worker, $operations, $edges] = $this->processingWorker($edge, [], 'revoke');
        $edges->throwOnStateRead = true;

        $outcome = $worker->process(9);

        self::assertSame('retryable-failure', $outcome->status);
        self::assertSame('provider_state_unreadable', $outcome->code);
        self::assertSame('retryable-failure', $operations->lastOutcome?->status);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_failed_adapter_result_is_mapped_to_sanitised_retryable_outcome(): void
    {
        TestProviderAdapter::$mutationSucceeds = false;
        TestProviderAdapter::$message = 'Token secret-123 and payload should not be stored';
        [$worker, $operations] = $this->processingWorker($this->edge());

        $outcome = $worker->process(9);

        self::assertSame('retryable-failure', $outcome->status);
        self::assertSame('provider_operation_failed', $outcome->code);
        self::assertSame('Provider operation failed.', $outcome->message);
        self::assertSame('Provider operation failed.', $operations->lastOutcome?->message);
    }

    public function test_provider_check_throwable_below_attempt_four_is_generic_and_retryable(): void
    {
        TestProviderAdapter::$throwOnCheck = true;
        [$worker, $operations] = $this->processingWorker($this->edge());

        $outcome = $worker->process(9);

        self::assertSame('retryable-failure', $outcome->status);
        self::assertSame('provider_operation_failed', $outcome->code);
        self::assertSame('Provider operation failed.', $outcome->message);
        self::assertStringNotContainsString('sensitive', $outcome->message);
        self::assertSame('retryable-failure', $operations->lastOutcome?->status);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_attempt_four_returns_and_persists_terminal_failure(): void
    {
        TestProviderAdapter::$mutationSucceeds = false;
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->operation['attempt_count'] = 3;

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('provider_operation_retry_exhausted', $outcome->code);
        self::assertSame('terminal-failure', $operations->lastOutcome?->status);
    }

    public function test_stale_attempt_four_confirms_already_applied_without_beginning_or_mutating(): void
    {
        TestProviderAdapter::$assigned = true;
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->operation['attempt_count'] = 4;

        $outcome = $worker->process(9);

        self::assertSame('already-applied', $outcome->status);
        self::assertSame(0, $operations->beginAttemptCount);
        self::assertSame(1, TestProviderAdapter::$checks);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_stale_attempt_four_absent_assignment_is_terminal_without_fifth_mutation(): void
    {
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->operation['attempt_count'] = 4;

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('provider_operation_retry_exhausted', $outcome->code);
        self::assertSame('terminal-failure', $operations->lastOutcome?->status);
        self::assertSame(0, $operations->beginAttemptCount);
        self::assertSame(1, TestProviderAdapter::$checks);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_stale_attempt_four_check_failure_is_terminal_without_fifth_mutation(): void
    {
        TestProviderAdapter::$throwOnCheck = true;
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->operation['attempt_count'] = 4;

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('provider_operation_retry_exhausted', $outcome->code);
        self::assertSame(0, $operations->beginAttemptCount);
        self::assertSame(1, TestProviderAdapter::$checks);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_stale_attempt_four_unreadable_ledger_is_terminal(): void
    {
        [$worker, $operations, $edges] = $this->processingWorker($this->edge());
        $operations->operation['attempt_count'] = 4;
        $edges->throwOnStateRead = true;

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('provider_operation_retry_exhausted', $outcome->code);
        self::assertSame(0, $operations->beginAttemptCount);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_stale_attempt_four_unreadable_ordering_is_terminal(): void
    {
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->operation['attempt_count'] = 4;
        $operations->throwOnOrderingRead = true;

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('provider_operation_retry_exhausted', $outcome->code);
        self::assertSame(0, $operations->beginAttemptCount);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_newer_assignment_work_is_deferred_behind_older_actionable_operation(): void
    {
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->olderActionable = true;

        $outcome = $worker->process(9);

        self::assertSame('deferred', $outcome->status);
        self::assertSame(0, TestProviderAdapter::$checks);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_repeated_deferred_cycles_do_not_consume_provider_attempts(): void
    {
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->olderActionable = true;

        self::assertSame('deferred', $worker->process(9)->status);
        self::assertSame('deferred', $worker->process(9)->status);

        self::assertSame(0, $operations->operation['attempt_count']);
        self::assertSame(0, $operations->beginAttemptCount);
        self::assertSame(0, TestProviderAdapter::$checks);
    }

    public function test_recovery_processes_at_most_fifty_due_operations_with_the_same_worker(): void
    {
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->recoverableIds = range(1, 50);

        $outcomes = $worker->recoverDue(50);

        self::assertCount(50, $outcomes);
        self::assertSame(50, $operations->claimCount);
        self::assertSame(50, $operations->lastRecoveryLimit);
        self::assertSame(1, $operations->findDueDeferredCalls);
        self::assertSame([], $operations->madeEligibleIds);
    }

    public function test_recovery_makes_each_due_deferred_operation_eligible_exactly_once_before_processing(): void
    {
        [$worker, $operations] = $this->processingWorker($this->edge());
        $operations->dueDeferredIds = [9];
        $operations->recoverableIds = [9];

        $outcomes = $worker->recoverDue(50);

        self::assertSame([9], $operations->madeEligibleIds);
        self::assertCount(1, $outcomes);
        self::assertSame('applied', $outcomes[9]->status);
        self::assertSame(1, TestProviderAdapter::$mutations);
    }

    public function test_suspend_maps_to_ownership_safe_provider_revoke(): void
    {
        TestProviderAdapter::$assigned = true;
        [$worker] = $this->processingWorker($this->edge(), [$this->edge()], 'suspend');

        $outcome = $worker->process(9);

        self::assertSame('applied', $outcome->status);
        self::assertFalse(TestProviderAdapter::$assigned);
        self::assertSame(1, TestProviderAdapter::$mutations);
    }

    public function test_suspend_preserves_preexisting_provider_assignment(): void
    {
        TestProviderAdapter::$assigned = true;
        [$worker] = $this->processingWorker($this->edge([
            'assignment_provenance' => 'preexisting',
        ]), [$this->edge()], 'suspend');

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('unsafe_provider_detach', $outcome->code);
        self::assertTrue(TestProviderAdapter::$assigned);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_suspend_is_superseded_while_another_effective_edge_survives(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge();
        [$worker] = $this->processingWorker($edge, [$edge, $this->edge(['id' => 8])], 'suspend', 'active');

        $outcome = $worker->process(9);

        self::assertSame('already-applied', $outcome->status);
        self::assertSame('desired_action_superseded', $outcome->code);
        self::assertTrue(TestProviderAdapter::$assigned);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_stacked_suspend_aggregate_sql_failure_is_retryable_without_provider_mutation(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge();
        [$worker, $operations] = $this->processingWorker(
            $edge,
            [$edge, $this->edge(['id' => 8])],
            'suspend',
            '__sql_error__'
        );

        $outcome = $worker->process(9);

        self::assertSame('retryable-failure', $outcome->status);
        self::assertSame('provider_state_unreadable', $outcome->code);
        self::assertSame('retryable-failure', $operations->lastOutcome?->status);
        self::assertTrue(TestProviderAdapter::$assigned);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_stacked_suspend_missing_aggregate_is_never_reported_as_success(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge();
        [$worker, $operations] = $this->processingWorker(
            $edge,
            [$edge, $this->edge(['id' => 8])],
            'suspend'
        );

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('provider_operation_grant_missing', $outcome->code);
        self::assertSame('terminal-failure', $operations->lastOutcome?->status);
        self::assertTrue(TestProviderAdapter::$assigned);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_future_stacked_edge_does_not_block_suspend_of_the_only_effective_edge(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge();
        $future = $this->edge([
            'id' => 8,
            'drip_available_at' => '2099-01-01 00:00:00',
        ]);
        [$worker] = $this->processingWorker($edge, [$edge, $future], 'suspend');

        $outcome = $worker->process(9);

        self::assertSame('applied', $outcome->status);
        self::assertFalse(TestProviderAdapter::$assigned);
        self::assertSame(1, TestProviderAdapter::$mutations);
    }

    public function test_paused_aggregate_suspends_once_when_all_stacked_edges_are_targeted(): void
    {
        TestProviderAdapter::$assigned = true;
        $edge = $this->edge();
        [$worker] = $this->processingWorker(
            $edge,
            [$edge, $this->edge(['id' => 8])],
            'suspend',
            'paused'
        );

        $outcome = $worker->process(9);

        self::assertSame('applied', $outcome->status);
        self::assertFalse(TestProviderAdapter::$assigned);
        self::assertSame(1, TestProviderAdapter::$mutations);
    }

    public function test_malformed_lifecycle_dates_fail_closed_for_grant_and_resume(): void
    {
        foreach (['starts_at', 'drip_available_at', 'expires_at'] as $field) {
            foreach (['grant', 'resume'] as $action) {
                TestProviderAdapter::reset();
                $edge = $this->edge([$field => 'not-a-date']);
                [$worker] = $this->processingWorker($edge, [$edge], $action);

                $outcome = $worker->process(9);

                self::assertSame('retryable-failure', $outcome->status, $field . ':' . $action);
                self::assertSame('provider_state_unreadable', $outcome->code, $field . ':' . $action);
                self::assertSame(0, TestProviderAdapter::$mutations, $field . ':' . $action);
            }
        }

        foreach (['grant', 'resume'] as $action) {
            TestProviderAdapter::reset();
            $edge = $this->edge(['policy' => ['membership_term_ends_at' => 'not-a-date']]);
            [$worker] = $this->processingWorker($edge, [$edge], $action);

            $outcome = $worker->process(9);

            self::assertSame('retryable-failure', $outcome->status, 'term:' . $action);
            self::assertSame(0, TestProviderAdapter::$mutations, 'term:' . $action);
        }
    }

    public function test_malformed_lifecycle_dates_preserve_assignments_for_detach_actions(): void
    {
        foreach (['starts_at', 'drip_available_at', 'expires_at'] as $field) {
            TestProviderAdapter::reset();
            TestProviderAdapter::$assigned = true;
            $edge = $this->edge([$field => 'not-a-date']);
            [$worker] = $this->processingWorker($edge, [$edge], 'suspend');

            $outcome = $worker->process(9);

            self::assertSame('terminal-failure', $outcome->status, $field);
            self::assertSame('unsafe_provider_detach', $outcome->code, $field);
            self::assertTrue(TestProviderAdapter::$assigned, $field);
            self::assertSame(0, TestProviderAdapter::$mutations, $field);
        }

        TestProviderAdapter::reset();
        TestProviderAdapter::$assigned = true;
        $target = $this->edge(['lifecycle' => 'ended']);
        $unknown = $this->edge(['id' => 8, 'policy' => ['membership_term_ends_at' => 'not-a-date']]);
        [$worker] = $this->processingWorker($target, [$unknown], 'revoke');

        $outcome = $worker->process(9);

        self::assertSame('terminal-failure', $outcome->status);
        self::assertSame('unsafe_provider_detach', $outcome->code);
        self::assertTrue(TestProviderAdapter::$assigned);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_resume_maps_to_provider_grant_for_a_surviving_active_edge(): void
    {
        [$worker] = $this->processingWorker($this->edge(), [$this->edge()], 'resume');

        $outcome = $worker->process(9);

        self::assertSame('applied', $outcome->status);
        self::assertTrue(TestProviderAdapter::$assigned);
        self::assertSame(1, TestProviderAdapter::$mutations);
    }

    public function test_suspend_resume_matrix_covers_crm_and_community_resource_types(): void
    {
        $resources = [
            ['fluentcrm', 'fluentcrm_tag'],
            ['fluentcrm', 'fluentcrm_list'],
            ['fluent_community', 'fc_space'],
            ['fluent_community', 'fc_course'],
        ];

        foreach ($resources as [$provider, $resourceType]) {
            $edge = $this->edge(['provider' => $provider, 'resource_type' => $resourceType]);
            TestProviderAdapter::reset();
            TestProviderAdapter::$assigned = true;
            [$suspendWorker] = $this->processingWorker($edge, [$edge], 'suspend');
            self::assertSame('applied', $suspendWorker->process(9)->status);
            self::assertFalse(TestProviderAdapter::$assigned);

            [$resumeWorker] = $this->processingWorker($edge, [$edge], 'resume');
            self::assertSame('applied', $resumeWorker->process(9)->status);
            self::assertTrue(TestProviderAdapter::$assigned);
        }
    }

    public function test_expiry_before_deferred_unlock_permanently_supersedes_grant_without_adapter_work(): void
    {
        [$worker] = $this->processingWorker($this->edge(['lifecycle' => 'ended']), [], 'grant');

        $outcome = $worker->process(9);

        self::assertSame('already-applied', $outcome->status);
        self::assertSame('desired_action_superseded', $outcome->code);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    public function test_older_retryable_suspend_is_superseded_after_newer_resume_intent(): void
    {
        TestProviderAdapter::$assigned = true;
        [$worker, $operations] = $this->processingWorker($this->edge(), [$this->edge()], 'suspend');
        $operations->newerAssignmentIntent = true;

        $outcome = $worker->process(9);

        self::assertSame('already-applied', $outcome->status);
        self::assertSame('desired_action_superseded', $outcome->code);
        self::assertTrue(TestProviderAdapter::$assigned);
        self::assertSame(0, TestProviderAdapter::$mutations);
    }

    private function worker(
        TestProviderOperationRepository $operations,
        array $edges,
        ?callable $scheduler = null
    ): ProviderOperationWorker {
        $edgeRepository = new TestEntitlementEdgeRepository($edges);
        $registry = new GrantAdapterRegistry([
            'fluentcrm' => TestProviderAdapter::class,
            'fluent_community' => TestProviderAdapter::class,
        ]);

        return new ProviderOperationWorker(
            $operations,
            $edgeRepository,
            $registry,
            $scheduler ?? static function (int $operationId) use (&$operations): void {
                $operations->events[] = 'schedule:' . $operationId;
            },
            static fn(): string => 'worker-a'
        );
    }

    private function processingWorker(
        array $edge,
        ?array $activeEdges = null,
        string $action = 'grant',
        ?string $aggregateStatus = null
    ): array {
        $events = [];
        $operations = new TestProviderOperationRepository($events);
        $operations->operation = $this->operation(['desired_action' => $action]);
        $edges = new TestEntitlementEdgeRepository([$edge]);
        $edges->activeEdges = $activeEdges ?? ($edge['lifecycle'] === 'active' ? [$edge] : []);
        $registry = new GrantAdapterRegistry([
            'fluentcrm' => TestProviderAdapter::class,
            'fluent_community' => TestProviderAdapter::class,
        ]);

        return [
            new ProviderOperationWorker(
                $operations,
                $edges,
                $registry,
                static function (): void {
                },
                static fn(): string => 'worker-a',
                null,
                new TestProviderGrantRepository($aggregateStatus)
            ),
            $operations,
            $edges,
        ];
    }

    private function edge(array $overrides = []): array
    {
        return array_replace([
            'id' => 7,
            'user_id' => 21,
            'provider' => 'fluentcrm',
            'resource_type' => 'fluentcrm_tag',
            'resource_id' => '31',
            'owner' => 'fchub',
            'assignment_provenance' => 'fchub_created',
            'lifecycle' => 'active',
        ], $overrides);
    }

    private function operation(array $overrides = []): array
    {
        return array_replace([
            'id' => 9,
            'edge_id' => 7,
            'desired_action' => 'grant',
            'origin_event' => 'order_paid:91',
            'state' => 'pending',
            'attempt_count' => 0,
            'retryable' => true,
        ], $overrides);
    }
}

final class TestProviderGrantRepository extends GrantRepository
{
    public function __construct(private ?string $status)
    {
    }

    public function findByGrantKey(string $grantKey): ?array
    {
        if ($this->status === '__sql_error__') {
            $GLOBALS['wpdb']->last_error = 'transient aggregate read failure';
            return null;
        }
        return $this->status === null ? null : ['id' => 71, 'status' => $this->status];
    }
}

final class TestProviderOperationRepository extends ProviderOperationRepository
{
    public array $events;
    public array $operation;
    public bool $olderActionable = false;
    public ?ProviderOperationOutcome $lastOutcome = null;
    public array $recoverableIds = [];
    public int $lastRecoveryLimit = 0;
    public int $claimCount = 0;
    public int $releaseDeferredCalls = 0;
    public int $findDueDeferredCalls = 0;
    public array $dueDeferredIds = [];
    public array $madeEligibleIds = [];
    public array $grantOperationIds = [];
    public int $failOutcomeWrites = 0;
    public int $beginAttemptCount = 0;
    public bool $throwOnOrderingRead = false;
    public bool $newerAssignmentIntent = false;

    public function __construct(array &$events)
    {
        $this->events =& $events;
        $this->operation = [
            'id' => 9,
            'edge_id' => 7,
            'desired_action' => 'grant',
            'origin_event' => 'order_paid:91',
            'state' => 'pending',
            'attempt_count' => 0,
            'retryable' => true,
        ];
    }

    public function createOrFind(int $edgeId, string $desiredAction, string $originEvent, ?\DateTimeImmutable $eligibleAt = null): array
    {
        $this->events[] = 'persist';
        $this->operation = array_replace($this->operation, [
            'edge_id' => $edgeId,
            'desired_action' => $desiredAction,
            'origin_event' => $originEvent,
            'state' => $eligibleAt !== null ? 'deferred' : 'pending',
        ]);
        return $this->operation;
    }

    public function findById(int $id): ?array
    {
        return $this->operation;
    }

    public function claim(int $id, string $owner, int $leaseSeconds = 300): ProviderOperationClaimResult
    {
        $this->claimCount++;
        $this->operation['state'] = 'processing';
        return ProviderOperationClaimResult::acquired($this->operation);
    }

    public function beginAttempt(int $id, string $owner, int $attemptCount): ?int
    {
        $this->beginAttemptCount++;
        $this->operation['attempt_count']++;
        return $this->operation['attempt_count'];
    }

    public function hasOlderActionableAssignment(array $operation): bool
    {
        if ($this->throwOnOrderingRead) {
            throw new \RuntimeException('sensitive ordering failure');
        }
        return $this->olderActionable;
    }

    public function hasNewerAssignmentIntent(array $operation): bool
    {
        return $this->newerAssignmentIntent;
    }

    public function recordOutcome(int $id, string $owner, ProviderOperationOutcome $outcome): bool
    {
        $this->lastOutcome = $outcome;
        if ($this->failOutcomeWrites > 0) {
            $this->failOutcomeWrites--;
            return false;
        }
        return true;
    }

    public function findRecoverableIds(int $limit = 50): array
    {
        $this->lastRecoveryLimit = $limit;
        return array_slice($this->recoverableIds, 0, $limit);
    }

    public function releaseDeferred(int $limit = 50): int
    {
        $this->releaseDeferredCalls++;
        return 0;
    }

    public function findDueDeferredIds(int $limit = 50): array
    {
        $this->findDueDeferredCalls++;
        return array_slice($this->dueDeferredIds, 0, $limit);
    }

    public function makeEligible(int $id): bool
    {
        if (in_array($id, $this->madeEligibleIds, true)) {
            return false;
        }
        $this->madeEligibleIds[] = $id;
        $this->operation['state'] = 'pending';
        return true;
    }

    public function findGrantOperationIdsForResource(
        array $grant,
        string $eligibleAt,
        int $limit = 50
    ): array
    {
        return array_slice($this->grantOperationIds, 0, $limit);
    }
}

final class TestEntitlementEdgeRepository extends EntitlementEdgeRepository
{
    public array $activeEdges = [];
    public bool $unsafeAssignmentEvidence = false;
    public bool $throwOnStateRead = false;

    public function __construct(private array $edges)
    {
    }

    public function findById(int $id): ?array
    {
        foreach ($this->edges as $edge) {
            if ((int) $edge['id'] === $id) {
                return $edge;
            }
        }
        return null;
    }

    public function getActiveByResource(int $userId, string $provider, string $resourceType, string $resourceId): array
    {
        if ($this->throwOnStateRead) {
            throw new \RuntimeException('sensitive database failure');
        }
        return $this->activeEdges;
    }

    public function hasUnsafeAssignmentEvidence(
        int $userId,
        string $provider,
        string $resourceType,
        string $resourceId
    ): bool {
        if ($this->throwOnStateRead) {
            throw new \RuntimeException('sensitive database failure');
        }
        return $this->unsafeAssignmentEvidence;
    }
}

final class TestProviderAdapter implements AccessAdapterInterface
{
    public static bool $assigned = false;
    public static bool $mutationSucceeds = true;
    public static string $message = '';
    public static int $checks = 0;
    public static int $mutations = 0;
    public static bool $throwOnCheck = false;

    public static function reset(): void
    {
        self::$assigned = false;
        self::$mutationSucceeds = true;
        self::$message = '';
        self::$checks = 0;
        self::$mutations = 0;
        self::$throwOnCheck = false;
    }

    public function supports(string $resourceType): bool
    {
        return in_array($resourceType, ['fluentcrm_tag', 'fluentcrm_list', 'fc_space', 'fc_course'], true);
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$mutations++;
        if (self::$mutationSucceeds) {
            self::$assigned = true;
        }
        return ['success' => self::$mutationSucceeds, 'message' => self::$message];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$mutations++;
        if (self::$mutationSucceeds) {
            self::$assigned = false;
        }
        return ['success' => self::$mutationSucceeds, 'message' => self::$message];
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        self::$checks++;
        if (self::$throwOnCheck) {
            throw new \RuntimeException('sensitive provider check failure');
        }
        return self::$assigned;
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        return $resourceId;
    }

    public function searchResources(string $query, string $resourceType, int $limit = 20): array
    {
        return [];
    }

    public function getResourceTypes(): array
    {
        return [];
    }
}
