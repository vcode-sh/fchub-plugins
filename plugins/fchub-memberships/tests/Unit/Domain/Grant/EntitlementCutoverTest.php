<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Domain\Entitlement\EntitlementService;
use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\Grant\PlanGrantExecutionService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class EntitlementCutoverTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CutoverAdapter::reset();
        $GLOBALS['_fchub_cutover_events'] = [];
    }

    public function test_grant_persists_full_typed_edge_and_operation_before_any_provider_mutation(): void
    {
        [$creation, $edges, , $worker] = $this->services();

        $result = $creation->grantResource(17, 'test_provider', 'course', '41', $this->context());

        self::assertSame('created', $result['action']);
        self::assertSame(['check', 'edge', 'operation:grant', 'process'], $GLOBALS['_fchub_cutover_events']);
        self::assertSame(0, CutoverAdapter::$grantCalls);
        self::assertSame(0, CutoverAdapter::$revokeCalls);
        self::assertSame([
            17,
            'test_provider',
            'course',
            '41',
            5,
            12,
            'product',
            'order',
            55,
        ], $edges->identityValues(array_values($edges->rows)[0]));
        self::assertSame('grant:order_paid:91', $worker->origins[0]);
    }

    public function test_same_numeric_source_id_across_types_stacked_plans_and_exact_replay_remain_distinct(): void
    {
        [$creation, $edges] = $this->services();

        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        )['action']);
        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context(['source_type' => 'subscription'])
        )['action']);
        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context(['plan_id' => 6])
        )['action']);
        self::assertSame('updated', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        )['action']);

        self::assertCount(3, $edges->rows);
    }

    public function test_successful_fchub_created_grant_replay_preserves_original_provenance(): void
    {
        [$creation, $edges] = $this->services();

        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        )['action']);
        CutoverAdapter::$assigned = true;

        $replay = $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        );

        self::assertSame('updated', $replay['action']);
        self::assertSame('fchub_created', array_values($edges->rows)[0]['assignment_provenance']);
        self::assertSame(1, CutoverAdapter::$checks, 'Exact replay must not reinterpret immutable provenance.');
    }

    public function test_explicit_preexisting_native_assignment_keeps_fchub_edge_and_preexisting_provenance(): void
    {
        CutoverAdapter::$assigned = true;
        [$creation, $edges] = $this->services();

        $result = $creation->grantResource(17, 'test_provider', 'course', '41', $this->context());

        $edge = array_values($edges->rows)[0];
        self::assertSame('created', $result['action']);
        self::assertSame('fchub', $edge['owner']);
        self::assertSame('preexisting', $edge['assignment_provenance']);
    }

    public function test_stacked_edge_inherits_complete_fchub_provider_assignment_provenance(): void
    {
        [$creation, $edges] = $this->services();

        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        )['action']);
        CutoverAdapter::$assigned = true;
        $edges->enforceDurableOperationEvidence = true;

        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context([
                'plan_id' => 6,
                'source_id' => 56,
                'origin_event' => 'grant:order_paid:92',
            ])
        )['action']);

        $stacked = array_values(array_filter(
            $edges->rows,
            static fn(array $edge): bool => (int) $edge['plan_id'] === 6
        ));
        self::assertCount(1, $stacked);
        self::assertSame('fchub_created', $stacked[0]['assignment_provenance']);
    }

    public function test_stacked_already_applied_edge_keeps_resource_lineage_detachable(): void
    {
        [$creation, $edges, , $worker, $revocation] = $this->services();
        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        )['action']);
        CutoverAdapter::$assigned = true;
        $edges->enforceDurableOperationEvidence = true;
        $worker->outcome = ProviderOperationOutcome::alreadyApplied();
        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context([
                'plan_id' => 6,
                'source_id' => 56,
                'origin_event' => 'grant:order_paid:92',
            ])
        )['action']);
        $worker->outcome = ProviderOperationOutcome::applied();

        self::assertSame(1, $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ])['revoked']);
        self::assertSame(1, $revocation->revokePlan(17, 6, [
            'source_type' => 'order',
            'source_id' => 56,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:92',
            'grace_period_days' => 0,
        ])['revoked']);
        self::assertSame(['grant', 'grant', 'revoke'], $worker->actions);
    }

    public function test_stacked_edge_does_not_claim_provider_assignment_when_applied_mutation_evidence_is_missing(): void
    {
        [$creation, $edges, , , , , , , $operations] = $this->services();
        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        )['action']);
        CutoverAdapter::$assigned = true;
        $edges->enforceDurableOperationEvidence = true;
        $operations->operations = [];

        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context([
                'plan_id' => 6,
                'source_id' => 56,
                'origin_event' => 'grant:order_paid:92',
            ])
        )['action']);

        $stacked = array_values(array_filter(
            $edges->rows,
            static fn(array $edge): bool => (int) $edge['plan_id'] === 6
        ));
        self::assertSame('preexisting', $stacked[0]['assignment_provenance']);
    }

    public function test_historical_grant_superseded_by_revoke_cannot_claim_a_later_external_assignment(): void
    {
        [$creation, $edges, , $worker, $revocation] = $this->services();
        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        )['action']);
        $edges->enforceDurableOperationEvidence = true;
        self::assertSame(1, $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ])['revoked']);

        CutoverAdapter::$assigned = false;
        $worker->assignBeforeProcess = true;
        $worker->outcome = ProviderOperationOutcome::alreadyApplied();
        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context([
                'plan_id' => 6,
                'source_id' => 56,
                'origin_event' => 'grant:order_paid:92',
            ])
        )['action']);

        self::assertSame(1, $revocation->revokePlan(17, 6, [
            'source_type' => 'order',
            'source_id' => 56,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:92',
            'grace_period_days' => 0,
        ])['revoked']);
        self::assertSame(['grant', 'revoke', 'grant'], $worker->actions);
    }

    public function test_assignment_appearing_between_creation_precheck_and_worker_is_never_detached(): void
    {
        [$creation, $edges, , $worker, $revocation] = $this->services();
        $worker->assignBeforeProcess = true;
        $worker->outcome = ProviderOperationOutcome::alreadyApplied();

        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        )['action']);
        self::assertSame('fchub_created', array_values($edges->rows)[0]['assignment_provenance']);
        self::assertSame(0, CutoverAdapter::$grantCalls);
        $edges->enforceDurableOperationEvidence = true;

        $result = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ]);

        self::assertSame(1, $result['revoked']);
        self::assertSame(['grant'], $worker->actions);
        self::assertSame(0, CutoverAdapter::$revokeCalls);
    }

    public function test_absent_feed_scope_uses_external_unknown_but_invalid_explicit_scope_is_rejected(): void
    {
        [$creation, $edges] = $this->services();
        $context = $this->context();
        unset($context['feed_scope']);

        $creation->grantResource(17, 'test_provider', 'course', '41', $context);
        self::assertSame('external_unknown', array_values($edges->rows)[0]['feed_scope']);

        $this->expectException(\InvalidArgumentException::class);
        $creation->grantResource(
            17,
            'test_provider',
            'course',
            '42',
            $this->context(['feed_scope' => 'guessed'])
        );
    }

    public function test_wordpress_is_local_only_and_creates_no_provider_operation(): void
    {
        [$creation, $edges, , $worker] = $this->services();

        $result = $creation->grantResource(17, 'wordpress_core', 'post', '41', $this->context());

        self::assertSame('created', $result['action']);
        self::assertCount(1, $edges->rows);
        self::assertSame([], $worker->actions);
        self::assertSame(0, CutoverAdapter::$checks);
    }

    public function test_wordpress_local_projection_deduplicates_origin_and_advances_once_for_a_new_origin(): void
    {
        [$creation, , , $worker, , $grants, , , $operations] = $this->services();
        $renewals = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_renewed'] = [
            static function (array $grant, int $count) use (&$renewals): void {
                $renewals[] = [$grant['id'], $count];
            },
        ];
        $initial = $this->context(['origin_event' => 'grant:order_paid:91']);

        self::assertSame('created', $creation->grantResource(17, 'wordpress_core', 'post', '41', $initial)['action']);
        self::assertSame('updated', $creation->grantResource(17, 'wordpress_core', 'post', '41', $initial)['action']);
        self::assertSame('updated', $creation->grantResource(17, 'wordpress_core', 'post', '41', $initial)['action']);
        self::assertSame(0, $grants->grant['renewal_count']);
        self::assertSame([], $renewals);

        $renewal = $this->context(['origin_event' => 'grant:renewal_order_paid:92']);
        self::assertSame('updated', $creation->grantResource(17, 'wordpress_core', 'post', '41', $renewal)['action']);
        self::assertSame('updated', $creation->grantResource(17, 'wordpress_core', 'post', '41', $renewal)['action']);
        self::assertSame('updated', $creation->grantResource(17, 'wordpress_core', 'post', '41', $initial)['action']);

        self::assertSame(1, $grants->grant['renewal_count']);
        self::assertSame([[71, 1]], $renewals);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $grants->grant['meta']['local_projection_origin_hash']
        );
        self::assertSame([], $worker->actions);
        self::assertSame([], $operations->operations);
    }

    public function test_wordpress_local_projection_receipts_are_sanitized_deduplicated_and_capped(): void
    {
        [$creation, , , $worker, , $grants, , , $operations] = $this->services();
        for ($index = 0; $index < 40; $index++) {
            $creation->grantResource(17, 'wordpress_core', 'post', '41', $this->context([
                'origin_event' => 'grant:order_paid:' . (100 + $index),
            ]));
        }

        $receipts = $grants->grant['meta']['local_projection_origin_hashes'];
        self::assertCount(32, $receipts);
        self::assertCount(32, array_unique($receipts));
        foreach ($receipts as $receipt) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt);
        }
        self::assertSame(39, $grants->grant['renewal_count']);

        $grants->grant['meta']['local_projection_origin_hashes'] = array_merge(
            $receipts,
            ['not-a-hash', $receipts[0], 123]
        );
        $creation->grantResource(17, 'wordpress_core', 'post', '41', $this->context([
            'origin_event' => 'grant:order_paid:120',
        ]));
        self::assertSame(39, $grants->grant['renewal_count']);
        $sanitized = $grants->grant['meta']['local_projection_origin_hashes'];
        self::assertCount(32, $sanitized);
        self::assertCount(32, array_unique($sanitized));
        self::assertNotContains('not-a-hash', $sanitized);
        self::assertNotContains(123, $sanitized);
        self::assertSame([], $worker->actions);
        self::assertSame([], $operations->operations);
    }

    public function test_provider_retryable_and_deferred_are_pending_while_terminal_is_failed(): void
    {
        foreach ([
            [ProviderOperationOutcome::retryableFailure(), 'pending'],
            [ProviderOperationOutcome::deferred(), 'pending'],
            [ProviderOperationOutcome::terminalFailure(), 'failed'],
        ] as [$outcome, $expected]) {
            [$creation, , , $worker] = $this->services();
            $worker->outcome = $outcome;

            $result = $creation->grantResource(
                17,
                'test_provider',
                'course',
                (string) (40 + count($worker->actions)),
                $this->context()
            );

            self::assertSame($expected, $result['action']);
        }
    }

    public function test_creation_distinguishes_missing_intent_from_persisted_but_unprocessed_intent(): void
    {
        [$creation, , , $worker] = $this->services();
        $worker->throwOnEnqueue = true;

        $missingIntent = $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        );

        self::assertSame('failed', $missingIntent['action']);
        self::assertSame('provider_operation_persistence_failed', $missingIntent['reason']);

        [$creation, , , $worker] = $this->services();
        $worker->throwOnProcess = true;
        $persistedIntent = $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $this->context()
        );

        self::assertSame('pending', $persistedIntent['action']);
        self::assertSame(['grant'], $worker->actions);
    }

    public function test_aggregate_mirror_preserves_context_metadata_and_trial_compatibility_on_replay(): void
    {
        [$creation, , , , , $grants] = $this->services();
        $context = $this->context([
            'is_trial' => true,
            'trial_ends_at' => '2026-07-29 12:00:00',
            'meta' => [
                'billing_anchor' => '2026-07-22 12:00:00',
                'membership_term_ends_at' => '2026-08-22 12:00:00',
            ],
        ]);

        self::assertSame('created', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $context
        )['action']);

        $context['meta']['payment_incident'] = ['subscription_id' => 55, 'failed_at' => '2026-07-22 11:00:00'];
        self::assertSame('updated', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $context
        )['action']);

        self::assertSame('2026-07-29 12:00:00', $grants->grant['trial_ends_at']);
        self::assertSame('2026-07-22 12:00:00', $grants->grant['meta']['billing_anchor']);
        self::assertSame('2026-08-22 12:00:00', $grants->grant['meta']['membership_term_ends_at']);
        self::assertSame(55, $grants->grant['meta']['payment_incident']['subscription_id']);
        self::assertSame('fchub', $grants->grant['meta']['provider_access_owner']);
        self::assertSame(0, $grants->grant['renewal_count']);
    }

    public function test_cutover_trial_and_genuine_renewal_preserve_legacy_metadata_and_hook_semantics(): void
    {
        [$creation, , , , , $grants] = $this->services();
        $renewals = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_renewed'] = [
            static function (array $grant, int $count) use (&$renewals): void {
                $renewals[] = [$grant['id'], $count];
            },
        ];
        $context = $this->context([
            'is_trial' => true,
            'trial_ends_at' => '2026-07-29 12:00:00',
            'origin_event' => 'grant:subscription_created:55',
        ]);

        $creation->grantResource(17, 'test_provider', 'course', '41', $context);
        self::assertSame('2026-07-22 12:00:00', $grants->grant['meta']['trial_started_at']);

        $context['origin_event'] = 'grant:subscription_renewed:56';
        self::assertSame('updated', $creation->grantResource(
            17,
            'test_provider',
            'course',
            '41',
            $context
        )['action']);

        self::assertSame(1, $grants->grant['renewal_count']);
        self::assertSame([[71, 1]], $renewals);
    }

    public function test_exact_origin_replay_never_counts_or_emits_a_second_renewal(): void
    {
        [$creation, , , $worker, , $grants] = $this->services();
        $renewals = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_renewed'] = [
            static function (array $grant, int $count) use (&$renewals): void {
                $renewals[] = [$grant['id'], $count];
            },
        ];
        $initial = $this->context(['origin_event' => 'grant:order_paid:91']);

        self::assertSame('created', $creation->grantResource(17, 'test_provider', 'course', '41', $initial)['action']);
        self::assertSame('updated', $creation->grantResource(17, 'test_provider', 'course', '41', $initial)['action']);
        self::assertSame(0, $grants->grant['renewal_count']);
        self::assertSame([], $renewals);

        $renewal = $this->context(['origin_event' => 'grant:renewal_order_paid:92']);
        $worker->outcome = ProviderOperationOutcome::retryableFailure();
        self::assertSame('pending', $creation->grantResource(17, 'test_provider', 'course', '41', $renewal)['action']);
        self::assertSame(0, $grants->grant['renewal_count']);
        self::assertSame([], $renewals);

        $worker->outcome = ProviderOperationOutcome::applied();
        self::assertSame('updated', $creation->grantResource(17, 'test_provider', 'course', '41', $renewal)['action']);
        self::assertSame(1, $grants->grant['renewal_count']);
        self::assertSame([[71, 1]], $renewals);

        self::assertSame('updated', $creation->grantResource(17, 'test_provider', 'course', '41', $renewal)['action']);
        self::assertSame(1, $grants->grant['renewal_count']);
        self::assertSame([[71, 1]], $renewals);
    }

    public function test_failed_new_origin_enqueue_does_not_advance_the_renewal_projection(): void
    {
        [$creation, , , $worker, , $grants] = $this->services();
        $creation->grantResource(17, 'test_provider', 'course', '41', $this->context());
        $renewals = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_renewed'] = [
            static function () use (&$renewals): void {
                $renewals++;
            },
        ];
        $worker->throwOnEnqueue = true;

        $failed = $creation->grantResource(17, 'test_provider', 'course', '41', $this->context([
            'origin_event' => 'grant:renewal_order_paid:92',
        ]));

        self::assertSame('failed', $failed['action']);
        self::assertSame(0, $grants->grant['renewal_count']);
        self::assertSame(0, $renewals);
    }

    public function test_immediate_revoke_ends_only_exact_typed_source_and_preserves_stacked_edges(): void
    {
        [, $edges, $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());
        $this->seed($entitlements, $this->identity(['source_type' => 'subscription']));
        $this->seed($entitlements, $this->identity(['plan_id' => 6]));

        $result = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ]);

        self::assertSame(1, $result['revoked']);
        self::assertCount(2, $edges->activeRows());
        self::assertSame(['subscription', 'order'], array_column($edges->activeRows(), 'source_type'));
        self::assertSame([], $worker->actions);
        self::assertSame(0, CutoverAdapter::$revokeCalls);
    }

    public function test_revoke_by_source_uses_exact_typed_entitlement_edges(): void
    {
        [, $edges, $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity(['source_type' => 'order']));
        $this->seed($entitlements, $this->identity(['source_type' => 'subscription']));

        $result = $revocation->revokeBySource(55, 'order', [
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
        ]);

        self::assertSame(1, $result['revoked']);
        self::assertCount(1, $edges->activeRows());
        self::assertSame('subscription', $edges->activeRows()[0]['source_type']);
        self::assertSame([], $worker->actions);
    }

    public function test_revoke_by_source_retry_recovers_applied_operation_from_ended_typed_edge(): void
    {
        [, , $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());
        $worker->outcome = ProviderOperationOutcome::retryableFailure();
        $hooks = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_revoked'] = [
            static function () use (&$hooks): void {
                $hooks++;
            },
        ];
        $context = [
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
        ];

        self::assertSame(1, $revocation->revokeBySource(55, 'order', $context)['pending']);
        $worker->outcome = ProviderOperationOutcome::applied();
        $worker->process(1);
        $applied = $revocation->revokeBySource(55, 'order', $context);

        self::assertTrue($applied['success']);
        self::assertSame(1, $applied['revoked']);
        self::assertSame(0, $applied['pending']);
        self::assertSame(1, $hooks);

        $duplicate = $revocation->revokeBySource(55, 'order', $context);
        self::assertTrue($duplicate['success']);
        self::assertSame(1, $duplicate['revoked']);
        self::assertSame(1, $hooks);
    }

    public function test_revoke_by_source_retry_recovers_terminal_operation_from_ended_typed_edge(): void
    {
        [, , $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());
        $worker->outcome = ProviderOperationOutcome::retryableFailure();
        $context = [
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
        ];

        self::assertSame(1, $revocation->revokeBySource(55, 'order', $context)['pending']);
        $worker->outcome = ProviderOperationOutcome::terminalFailure();
        $worker->process(1);
        $terminal = $revocation->revokeBySource(55, 'order', $context);

        self::assertFalse($terminal['success']);
        self::assertSame(1, $terminal['revoked']);
        self::assertSame(1, $terminal['failed']);
    }

    public function test_revoke_by_source_rejects_zero_sentinel_instead_of_matching_manual_edges_globally(): void
    {
        [, $edges, $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity([
            'source_type' => 'manual',
            'source_id' => 0,
        ]));

        $result = $revocation->revokeBySource(0, 'manual', ['reason' => 'invalid sentinel']);

        self::assertSame(0, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertCount(1, $edges->activeRows());
        self::assertSame([], $worker->actions);
    }

    public function test_final_safe_edge_queues_and_processes_revoke_without_direct_adapter_call(): void
    {
        [, , $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());

        $result = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ]);

        self::assertSame(1, $result['revoked']);
        self::assertSame(['revoke'], $worker->actions);
        self::assertSame(0, CutoverAdapter::$revokeCalls);
    }

    public function test_atomic_revoke_schedules_persisted_operation_before_direct_processing(): void
    {
        [, , $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());

        $result = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ]);

        self::assertTrue($result['success']);
        self::assertSame([1], $worker->scheduled);
        self::assertSame(['schedule:1', 'process'], array_slice($GLOBALS['_fchub_cutover_events'], -2));
    }

    public function test_atomic_revoke_keeps_durable_recovery_when_immediate_scheduling_fails(): void
    {
        [, , $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());
        $worker->throwOnSchedule = true;

        $result = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ]);

        self::assertTrue($result['success']);
        self::assertSame([1], $worker->scheduled);
        self::assertSame(['revoke'], $worker->actions);
    }

    public function test_every_unsafe_owner_or_provenance_pair_preserves_provider_access(): void
    {
        foreach ([
            ['preexisting', 'fchub_created'],
            ['external_unknown', 'fchub_created'],
            ['fchub', 'preexisting'],
            ['fchub', 'unknown'],
        ] as [$owner, $provenance]) {
            [, , $entitlements, $worker, $revocation] = $this->services();
            $this->seed($entitlements, $this->identity(), [
                'owner' => $owner,
                'assignment_provenance' => $provenance,
            ]);

            $result = $revocation->revokePlan(17, 5, [
                'reason' => 'manual revoke',
                'grace_period_days' => 0,
            ]);

            self::assertSame(1, $result['revoked']);
            self::assertSame([], $worker->actions);
        }
    }

    public function test_manual_fchub_created_edge_can_detach_because_manual_is_only_a_source_type(): void
    {
        [, , $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity([
            'source_type' => 'manual',
            'source_id' => 0,
        ]));

        $result = $revocation->revokePlan(17, 5, [
            'reason' => 'manual revoke',
            'grace_period_days' => 0,
        ]);

        self::assertSame(1, $result['revoked']);
        self::assertSame(['revoke'], $worker->actions);
    }

    public function test_grace_keeps_exact_edges_active_and_never_queues_detach(): void
    {
        [, $edges, $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());

        $result = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'cancelled',
            'grace_period_days' => 3,
        ]);

        self::assertSame(1, $result['grace_started']);
        self::assertCount(1, $edges->activeRows());
        self::assertSame([], $worker->actions);
    }

    public function test_due_grace_ends_only_the_original_edge_snapshot_and_clears_metadata(): void
    {
        [, $edges, $entitlements, , $revocation, $grants] = $this->services();
        $original = $this->identity(['resource_id' => '41', 'feed_id' => 12]);
        $this->seed($entitlements, $original);

        $started = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'feed_id' => 12,
            'feed_scope' => 'product',
            'reason' => 'cancelled',
            'grace_period_days' => 3,
        ]);
        self::assertSame(1, $started['grace_started']);
        self::assertSame([1], array_column($grants->grant['meta']['entitlement_grace_edges'], 'edge_id'));

        $laterSameFeed = $this->identity(['resource_id' => '42', 'feed_id' => 12]);
        $otherFeed = $this->identity(['resource_id' => '43', 'feed_id' => 13]);
        $this->seed($entitlements, $laterSameFeed);
        $this->seed($entitlements, $otherFeed);
        $grants->grant['meta']['entitlement_grace_edges'][0]['effective_at'] = '2026-07-22 11:00:00';
        $grants->grant['cancellation_effective_at'] = '2026-07-22 11:00:00';
        $grants->dueGrace = true;

        $revoked = $revocation->revokeExpiredGracePeriodGrants();

        self::assertSame(1, $revoked);
        self::assertSame('ended', $edges->findByIdentity($original)['lifecycle']);
        self::assertSame('active', $edges->findByIdentity($laterSameFeed)['lifecycle']);
        self::assertSame('active', $edges->findByIdentity($otherFeed)['lifecycle']);
        self::assertArrayNotHasKey('entitlement_grace_edges', $grants->grant['meta']);
        self::assertNull($grants->grant['cancellation_effective_at']);
    }

    public function test_stacked_grace_snapshots_merge_and_revoke_each_exact_plan_edge_only(): void
    {
        [, $edges, $entitlements, , $revocation, $grants] = $this->services();
        $first = $this->identity(['plan_id' => 5, 'feed_id' => 12, 'source_id' => 55]);
        $second = $this->identity(['plan_id' => 6, 'feed_id' => 13, 'source_id' => 56]);
        $later = $this->identity(['plan_id' => 7, 'feed_id' => 14, 'source_id' => 57]);
        $this->seed($entitlements, $first);
        $this->seed($entitlements, $second);

        $revocation->revokePlan(17, 5, ['edge_ids' => [1], 'reason' => 'first cancelled', 'grace_period_days' => 3]);
        $revocation->revokePlan(17, 6, ['edge_ids' => [2], 'reason' => 'second cancelled', 'grace_period_days' => 5]);

        self::assertSame([1, 2], array_column($grants->grant['meta']['entitlement_grace_edges'], 'edge_id'));
        self::assertSame(
            ['2026-07-25 12:00:00', '2026-07-27 12:00:00'],
            array_column($grants->grant['meta']['entitlement_grace_edges'], 'effective_at')
        );

        $this->seed($entitlements, $later);
        foreach ($grants->grant['meta']['entitlement_grace_edges'] as &$snapshot) {
            $snapshot['effective_at'] = '2026-07-22 11:00:00';
        }
        unset($snapshot);
        $grants->grant['cancellation_effective_at'] = '2026-07-22 11:00:00';
        $grants->dueGrace = true;

        self::assertSame(2, $revocation->revokeExpiredGracePeriodGrants());
        self::assertSame('ended', $edges->findByIdentity($first)['lifecycle']);
        self::assertSame('ended', $edges->findByIdentity($second)['lifecycle']);
        self::assertSame('active', $edges->findByIdentity($later)['lifecycle']);
        self::assertArrayNotHasKey('entitlement_grace_edges', $grants->grant['meta']);
    }

    public function test_grace_completion_clears_only_completed_entries_and_retains_pending_edge(): void
    {
        [, $edges, $entitlements, $worker, $revocation, $grants] = $this->services();
        $first = $this->identity(['plan_id' => 5, 'feed_id' => 12, 'source_id' => 55]);
        $second = $this->identity(['plan_id' => 6, 'feed_id' => 13, 'source_id' => 56]);
        $this->seed($entitlements, $first);
        $this->seed($entitlements, $second);
        $revocation->revokePlan(17, 5, ['edge_ids' => [1], 'reason' => 'first cancelled', 'grace_period_days' => 3]);
        $revocation->revokePlan(17, 6, ['edge_ids' => [2], 'reason' => 'second cancelled', 'grace_period_days' => 3]);
        foreach ($grants->grant['meta']['entitlement_grace_edges'] as &$snapshot) {
            $snapshot['effective_at'] = '2026-07-22 11:00:00';
        }
        unset($snapshot);
        $grants->grant['cancellation_effective_at'] = '2026-07-22 11:00:00';
        $grants->dueGrace = true;
        $worker->outcome = ProviderOperationOutcome::retryableFailure();

        self::assertSame(2, $revocation->revokeExpiredGracePeriodGrants());
        self::assertSame('ended', $edges->findByIdentity($first)['lifecycle']);
        self::assertSame('ended', $edges->findByIdentity($second)['lifecycle']);
        self::assertSame([2], array_column($grants->grant['meta']['entitlement_grace_edges'], 'edge_id'));
        self::assertSame('2026-07-22 11:00:00', $grants->grant['cancellation_effective_at']);
    }

    public function test_grace_completion_cleanup_failure_is_exposed_and_keeps_snapshot_for_retry(): void
    {
        [, $edges, $entitlements, , $revocation, $grants] = $this->services();
        $identity = $this->identity();
        $this->seed($entitlements, $identity);
        $revocation->revokePlan(17, 5, ['edge_ids' => [1], 'reason' => 'cancelled', 'grace_period_days' => 3]);
        $grants->grant['meta']['entitlement_grace_edges'][0]['effective_at'] = '2026-07-22 11:00:00';
        $grants->grant['cancellation_effective_at'] = '2026-07-22 11:00:00';
        $grants->dueGrace = true;
        $grants->failUpdate = true;

        try {
            $revocation->revokeExpiredGracePeriodGrants();
            self::fail('Grace completion metadata failure must be exposed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The entitlement grace completion could not be persisted.', $exception->getMessage());
        }

        self::assertSame('active', $edges->findByIdentity($identity)['lifecycle']);
        self::assertSame([1], array_column($grants->grant['meta']['entitlement_grace_edges'], 'edge_id'));
        self::assertSame('2026-07-22 11:00:00', $grants->grant['cancellation_effective_at']);
    }

    public function test_pending_or_terminal_revoke_suppresses_success_hook_and_notification(): void
    {
        foreach ([
            [ProviderOperationOutcome::retryableFailure(), 1, 0],
            [ProviderOperationOutcome::terminalFailure(), 0, 1],
        ] as [$outcome, $pending, $failed]) {
            [, , $entitlements, $worker, $revocation] = $this->services();
            $this->seed($entitlements, $this->identity());
            $worker->outcome = $outcome;
            $hooks = 0;
            $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_revoked'] = [
                static function () use (&$hooks): void {
                    $hooks++;
                },
            ];

            $result = $revocation->revokePlan(17, 5, [
                'reason' => 'refund',
                'grace_period_days' => 0,
            ]);

            self::assertSame($pending, $result['pending'] ?? 0);
            self::assertSame($failed, $result['failed']);
            self::assertSame(0, $hooks);
            self::assertSame([], $GLOBALS['_fchub_test_mails']);
        }
    }

    public function test_revoke_retry_rehydrates_applied_operation_and_emits_success_once(): void
    {
        [, , $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());
        $worker->outcome = ProviderOperationOutcome::retryableFailure();
        $hooks = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_revoked'] = [
            static function () use (&$hooks): void {
                $hooks++;
            },
        ];
        $user = new \WP_User();
        $user->ID = 17;
        $user->user_email = 'member@example.com';
        $user->display_name = 'Member';
        $GLOBALS['_fchub_test_users'][17] = $user;
        $context = [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ];

        $pending = $revocation->revokePlan(17, 5, $context);
        self::assertSame(1, $pending['pending']);
        self::assertSame(0, $hooks);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);

        $worker->outcome = ProviderOperationOutcome::applied();
        $worker->process(1);
        $applied = $revocation->revokePlan(17, 5, $context);

        self::assertTrue($applied['success']);
        self::assertSame(1, $applied['revoked']);
        self::assertSame(1, $hooks);
        self::assertCount(1, $GLOBALS['_fchub_test_mails']);
    }

    public function test_revoke_retry_rehydrates_terminal_operation_without_reporting_success(): void
    {
        [, , $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());
        $worker->outcome = ProviderOperationOutcome::retryableFailure();
        $context = [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ];
        self::assertSame(1, $revocation->revokePlan(17, 5, $context)['pending']);

        $worker->outcome = ProviderOperationOutcome::terminalFailure();
        $worker->process(1);
        $terminal = $revocation->revokePlan(17, 5, $context);

        self::assertFalse($terminal['success']);
        self::assertSame(1, $terminal['failed']);
        self::assertSame(1, $terminal['revoked']);
    }

    public function test_revocation_sql_failure_is_truthful_and_never_calls_provider(): void
    {
        [, $edges, , $worker, $revocation] = $this->services();
        $edges->failMatchingRead = true;

        $result = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'grace_period_days' => 0,
        ]);

        self::assertSame(1, $result['failed']);
        self::assertSame([], $worker->actions);
        self::assertStringNotContainsString('sensitive', serialize($result));
    }

    public function test_plan_policy_read_failure_cannot_fall_through_to_immediate_revoke(): void
    {
        [, $edges, $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity());
        $database = new class extends \wpdb {
            public string $last_error = 'sensitive plan policy failure';
        };
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): null => null;

        $result = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'cancelled',
        ]);

        self::assertSame(1, $result['failed']);
        self::assertCount(1, $edges->activeRows());
        self::assertSame([], $worker->actions);
        self::assertStringNotContainsString('sensitive', serialize($result));
    }

    public function test_multi_resource_revoke_persists_each_final_intent_before_a_later_edge_failure(): void
    {
        [, $edges, $entitlements, $worker, $revocation] = $this->services();
        $this->seed($entitlements, $this->identity(['resource_id' => '41']));
        $this->seed($entitlements, $this->identity(['resource_id' => '42']));
        $edges->failEndResourceId = '42';

        $result = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ]);

        self::assertFalse($result['success']);
        self::assertTrue($result['partial']);
        self::assertSame(1, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertSame(['revoke'], $worker->actions);
        self::assertSame('ended', $edges->rows[$edges->keyFor($this->identity(['resource_id' => '41']))]['lifecycle']);
        self::assertSame('active', $edges->rows[$edges->keyFor($this->identity(['resource_id' => '42']))]['lifecycle']);
    }

    public function test_final_edge_end_rolls_back_when_durable_revoke_intent_cannot_be_persisted(): void
    {
        [, $edges, $entitlements, $worker, $revocation, , , , $operations] = $this->services();
        $this->seed($entitlements, $this->identity());
        $operations->failCreate = true;

        $failed = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ]);

        self::assertSame(1, $failed['failed']);
        self::assertCount(1, $edges->activeRows(), 'The terminal edge and missing intent must not commit separately.');

        $operations->failCreate = false;
        $retried = $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'origin_event' => 'revoke:order_refunded:91',
            'grace_period_days' => 0,
        ]);

        self::assertSame(1, $retried['revoked']);
        self::assertSame(['revoke'], $worker->actions);
    }

    public function test_plan_execution_forwards_scope_and_origin_and_suppresses_success_for_pending_provider_work(): void
    {
        [$creation, $edges, , $worker, $revocation] = $this->services();
        $worker->outcome = ProviderOperationOutcome::retryableFailure();
        $plans = new class extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'title' => 'Plan',
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => [],
                ];
            }
        };
        $rules = new class extends PlanRuleResolver {
            public function resolveUniqueRules(int $planId): array
            {
                return [[
                    'id' => 81,
                    'provider' => 'test_provider',
                    'resource_type' => 'course',
                    'resource_id' => '41',
                    'drip_type' => 'immediate',
                ]];
            }
        };
        $membershipGrants = new class extends GrantRepository {
            public function __construct()
            {
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [];
            }
        };
        $order = new class {
            public array $logs = [];

            public function addLog(string $title, string $description, string $type, string $module): void
            {
                $this->logs[] = [$title, $description, $type, $module];
            }
        };
        $hooks = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_created'] = [
            static function () use (&$hooks): void {
                $hooks++;
            },
        ];
        $service = new PlanGrantExecutionService(
            $rules,
            new MembershipModeService($membershipGrants, $plans),
            new GrantPlanContextService($plans, $membershipGrants),
            $creation,
            $revocation,
            new GrantNotificationService($plans)
        );

        $result = $service->grantPlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'feed_id' => 12,
            'feed_scope' => 'global',
            'origin_event' => 'grant:order_paid:91',
            'order' => $order,
        ]);

        self::assertSame(1, $result['pending']);
        self::assertSame(0, $result['failed'] ?? 0);
        self::assertSame('global', array_values($edges->rows)[0]['feed_scope']);
        self::assertSame('grant:order_paid:91', $worker->origins[0]);
        self::assertSame(0, $hooks);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
        self::assertSame([], $order->logs);
    }

    public function test_entitlement_service_maintains_typed_source_and_drip_compatibility_mirrors(): void
    {
        [$creation, , , , $revocation, , $sources, $drips] = $this->services();
        $orderContext = $this->context([
            'drip_rule' => [
                'id' => 81,
                'drip_type' => 'delayed',
                'drip_delay_days' => 2,
            ],
        ]);

        $creation->grantResource(17, 'test_provider', 'course', '41', $orderContext);
        $creation->grantResource(17, 'test_provider', 'course', '41', $orderContext);
        $creation->grantResource(17, 'test_provider', 'course', '41', $this->context([
            'source_type' => 'subscription',
        ]));

        self::assertCount(2, $sources->links);
        self::assertCount(1, $drips->scheduled);
        self::assertSame(81, $drips->scheduled[0]['plan_rule_id']);

        $revocation->revokePlan(17, 5, [
            'source_type' => 'order',
            'source_id' => 55,
            'reason' => 'refund',
            'grace_period_days' => 0,
        ]);
        self::assertCount(1, $sources->links);
        self::assertArrayHasKey('71|subscription|55', $sources->links);
        self::assertSame([], $drips->deleted);

        $revocation->revokePlan(17, 5, [
            'source_type' => 'subscription',
            'source_id' => 55,
            'reason' => 'expired',
            'grace_period_days' => 0,
        ]);
        self::assertSame([], $sources->links);
        self::assertSame([71], $drips->deleted);
    }

    private function services(): array
    {
        $edges = new CutoverEdgeRepository();
        $grants = new CutoverGrantRepository();
        $clock = new Clock(
            new \DateTimeImmutable('2026-07-22 12:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );
        $sources = new CutoverSourceRepository();
        $drips = new CutoverDripRepository();
        $operations = new CutoverOperationRepository($edges);
        $edges->operations = $operations;
        $entitlements = new EntitlementService($edges, $grants, $clock, $sources, $drips, $operations);
        $worker = new CutoverProviderWorker($operations);
        $registry = new GrantAdapterRegistry(['test_provider' => CutoverAdapter::class]);
        $creation = new GrantCreationService(
            $grants,
            $sources,
            $drips,
            $registry,
            $clock,
            $entitlements,
            $worker
        );
        $revocation = new GrantRevocationService(
            $grants,
            $sources,
            $drips,
            $registry,
            new GrantNotificationService(new CutoverPlanRepository()),
            $clock,
            $entitlements,
            $worker
        );

        return [
            $creation,
            $edges,
            $entitlements,
            $worker,
            $revocation,
            $grants,
            $sources,
            $drips,
            $operations,
        ];
    }

    private function seed(
        EntitlementService $entitlements,
        array $identity,
        array $ownership = []
    ): void {
        $entitlements->activate($identity, array_merge([
            'owner' => 'fchub',
            'assignment_provenance' => 'fchub_created',
        ], $ownership));
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'plan_id' => 5,
            'feed_id' => 12,
            'feed_scope' => 'product',
            'source_type' => 'order',
            'source_id' => 55,
            'origin_event' => 'grant:order_paid:91',
        ], $overrides);
    }

    private function identity(array $overrides = []): array
    {
        return array_merge([
            'user_id' => 17,
            'provider' => 'test_provider',
            'resource_type' => 'course',
            'resource_id' => '41',
            'plan_id' => 5,
            'feed_id' => 12,
            'feed_scope' => 'product',
            'source_type' => 'order',
            'source_id' => 55,
        ], $overrides);
    }
}

final class CutoverEdgeRepository extends EntitlementEdgeRepository
{
    public array $rows = [];
    public bool $failMatchingRead = false;
    public ?string $failEndResourceId = null;
    public bool $unsafeOperationEvidence = false;
    public bool $enforceDurableOperationEvidence = false;
    public ?CutoverOperationRepository $operations = null;
    private int $nextId = 1;

    public function __construct()
    {
    }

    public function createOrReplay(array $data, ?array $comparisonFields = null): array
    {
        $key = $this->key($data);
        if (isset($this->rows[$key])) {
            return ['action' => 'replayed', 'edge' => $this->rows[$key]];
        }
        $data['id'] = $this->nextId++;
        $this->rows[$key] = $data;
        $GLOBALS['_fchub_cutover_events'][] = 'edge';
        return ['action' => 'created', 'edge' => $data];
    }

    public function findByIdentity(array $identity): ?array
    {
        return $this->rows[$this->key($identity)] ?? null;
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

    public function endByIdentity(array $identity, string $endedAt, string $reason): array
    {
        if ((string) $identity['resource_id'] === $this->failEndResourceId) {
            throw new \RuntimeException('sensitive edge persistence failure');
        }
        $key = $this->key($identity);
        if (!isset($this->rows[$key])) {
            return ['action' => 'not_found', 'edge' => null];
        }
        $this->rows[$key]['lifecycle'] = 'ended';
        $this->rows[$key]['ended_at'] = $endedAt;
        $this->rows[$key]['end_reason'] = $reason;
        return ['action' => 'ended', 'edge' => $this->rows[$key]];
    }

    public function getActiveByResource(int $userId, string $provider, string $resourceType, string $resourceId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => $row['user_id'] === $userId
                && $row['provider'] === $provider
                && $row['resource_type'] === $resourceType
                && $row['resource_id'] === $resourceId
                && $row['lifecycle'] === 'active'
        ));
    }

    public function getActiveMatching(int $userId, int $planId, array $context = []): array
    {
        if ($this->failMatchingRead) {
            throw new \RuntimeException('sensitive database failure');
        }
        return array_values(array_filter($this->activeRows(), static function (array $row) use (
            $userId,
            $planId,
            $context
        ): bool {
            if ($row['user_id'] !== $userId || $row['plan_id'] !== $planId) {
                return false;
            }
            if (isset($context['edge_ids']) && !in_array((int) $row['id'], $context['edge_ids'], true)) {
                return false;
            }
            foreach (['source_type', 'source_id', 'feed_id', 'feed_scope'] as $field) {
                if (array_key_exists($field, $context) && $row[$field] !== $context[$field]) {
                    return false;
                }
            }
            return true;
        }));
    }

    public function getActiveByTypedSource(int $sourceId, string $sourceType): array
    {
        return array_values(array_filter(
            $this->activeRows(),
            static fn(array $row): bool => $row['source_id'] === $sourceId
                && $row['source_type'] === $sourceType
        ));
    }

    public function getEndedByTypedSource(int $sourceId, string $sourceType): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => $row['lifecycle'] === 'ended'
                && $row['source_id'] === $sourceId
                && $row['source_type'] === $sourceType
        ));
    }

    public function getEndedMatching(int $userId, int $planId, array $context = []): array
    {
        return array_values(array_filter($this->rows, static function (array $row) use (
            $userId,
            $planId,
            $context
        ): bool {
            if ($row['lifecycle'] !== 'ended'
                || $row['user_id'] !== $userId
                || $row['plan_id'] !== $planId
            ) {
                return false;
            }
            foreach (['source_type', 'source_id', 'feed_id', 'feed_scope'] as $field) {
                if (array_key_exists($field, $context) && $row[$field] !== $context[$field]) {
                    return false;
                }
            }
            return true;
        }));
    }

    public function hasUnsafeAssignmentEvidence(int $userId, string $provider, string $resourceType, string $resourceId): bool
    {
        if ($this->unsafeOperationEvidence) {
            return true;
        }
        $matchingEdgeIds = [];
        foreach ($this->rows as $row) {
            if ($row['user_id'] === $userId
                && $row['provider'] === $provider
                && $row['resource_type'] === $resourceType
                && $row['resource_id'] === $resourceId
            ) {
                if ($row['owner'] !== 'fchub' || $row['assignment_provenance'] !== 'fchub_created') {
                    return true;
                }
                $matchingEdgeIds[] = (int) $row['id'];
            }
        }
        if (!$this->enforceDurableOperationEvidence || $provider === 'wordpress_core') {
            return false;
        }

        return !$this->operations?->hasCompleteAppliedGrantEvidence($matchingEdgeIds);
    }

    public function resourceTransaction(array $resource, callable $callback): mixed
    {
        $snapshot = $this->rows;
        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->rows = $snapshot;
            throw $exception;
        }
    }

    public function activeRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => $row['lifecycle'] === 'active'
        ));
    }

    public function identityValues(array $row): array
    {
        return array_map(static fn(string $field): mixed => $row[$field], [
            'user_id',
            'provider',
            'resource_type',
            'resource_id',
            'plan_id',
            'feed_id',
            'feed_scope',
            'source_type',
            'source_id',
        ]);
    }

    public function keyFor(array $row): string
    {
        return $this->key($row);
    }

    private function key(array $row): string
    {
        return implode('|', $this->identityValues($row));
    }
}

final class CutoverGrantRepository extends GrantRepository
{
    public ?array $grant = null;
    public bool $dueGrace = false;
    public bool $failUpdate = false;

    public function __construct()
    {
    }

    public function findByGrantKey(string $grantKey): ?array
    {
        return $this->grant;
    }

    public function find(int $id): ?array
    {
        return $this->grant;
    }

    public function create(array $data): int
    {
        $this->grant = array_merge($data, ['id' => 71, 'meta' => $data['meta'] ?? []]);
        return 71;
    }

    public function update(int $id, array $data): bool
    {
        if ($this->failUpdate) {
            return false;
        }
        $this->grant = array_merge($this->grant ?? ['id' => $id], $data);
        return true;
    }

    public function getByUserId(int $userId, array $filters = []): array
    {
        return $this->grant ? [$this->grant] : [];
    }

    public function getDueGracePeriodGrants(int $limit = 100): array
    {
        return $this->dueGrace && $this->grant ? [$this->grant] : [];
    }
}

final class CutoverProviderWorker extends ProviderOperationWorker
{
    public ProviderOperationOutcome $outcome;
    public array $actions = [];
    public array $origins = [];
    private array $recordedOperationIds = [];
    public bool $throwOnEnqueue = false;
    public bool $throwOnProcess = false;
    public bool $throwOnSchedule = false;
    public bool $assignBeforeProcess = false;
    public array $scheduled = [];

    public function __construct(private CutoverOperationRepository $operations)
    {
        $this->outcome = ProviderOperationOutcome::applied();
    }

    public function enqueue(
        int $edgeId,
        string $desiredAction,
        string $originEvent,
        ?\DateTimeImmutable $eligibleAt = null
    ): ?array
    {
        if ($this->throwOnEnqueue) {
            throw new \RuntimeException('operation persistence failed');
        }
        $operation = $this->operations->createOrFind($edgeId, $desiredAction, $originEvent, $eligibleAt);
        $this->recordAction($operation);
        $GLOBALS['_fchub_cutover_events'][] = 'operation:' . $desiredAction;
        return $operation;
    }

    public function process(int $operationId): ProviderOperationOutcome
    {
        if ($this->throwOnProcess) {
            throw new \RuntimeException('worker died after persistence');
        }
        if ($this->assignBeforeProcess) {
            CutoverAdapter::$assigned = true;
        }
        $operation = $this->operations->findById($operationId);
        if ($operation !== null) {
            $this->recordAction($operation);
            $this->operations->recordTestOutcome($operationId, $this->outcome);
        }
        $GLOBALS['_fchub_cutover_events'][] = 'process';
        return $this->outcome;
    }

    public function schedulePersisted(int $operationId): void
    {
        $this->scheduled[] = $operationId;
        $GLOBALS['_fchub_cutover_events'][] = 'schedule:' . $operationId;
        if ($this->throwOnSchedule) {
            throw new \RuntimeException('scheduler unavailable');
        }
    }

    private function recordAction(array $operation): void
    {
        $id = (int) $operation['id'];
        if (isset($this->recordedOperationIds[$id])) {
            return;
        }
        $this->recordedOperationIds[$id] = true;
        $this->actions[] = (string) $operation['desired_action'];
        $this->origins[] = (string) $operation['origin_event'];
    }
}

final class CutoverOperationRepository extends ProviderOperationRepository
{
    public array $operations = [];
    public bool $failCreate = false;

    public function __construct(private CutoverEdgeRepository $edges)
    {
    }

    public function createOrFind(
        int $edgeId,
        string $desiredAction,
        string $originEvent,
        ?\DateTimeImmutable $eligibleAt = null
    ): array {
        if ($this->failCreate) {
            throw new \RuntimeException('operation persistence failed');
        }
        $key = $this->operationKey($edgeId, $desiredAction, $originEvent);
        foreach ($this->operations as $operation) {
            if ($operation['operation_key'] === $key) {
                return $operation;
            }
        }
        $id = count($this->operations) + 1;
        return $this->operations[$id] = [
            'id' => $id,
            'edge_id' => $edgeId,
            'operation_key' => $key,
            'desired_action' => $desiredAction,
            'origin_event' => $originEvent,
            'state' => 'pending',
            'retryable' => true,
            'last_error_code' => null,
        ];
    }

    public function findById(int $id): ?array
    {
        return $this->operations[$id] ?? null;
    }

    public function findByOperationKey(string $operationKey): ?array
    {
        foreach ($this->operations as $operation) {
            if ($operation['operation_key'] === $operationKey) {
                return $operation;
            }
        }
        return null;
    }

    public function countAppliedGrantOperations(int $edgeId): int
    {
        return count(array_filter(
            $this->operations,
            static fn(array $operation): bool => (int) $operation['edge_id'] === $edgeId
                && $operation['desired_action'] === 'grant'
                && $operation['state'] === 'applied'
        ));
    }

    public function hasCompleteAppliedGrantEvidence(array $edgeIds): bool
    {
        $latest = null;
        foreach ($this->operations as $operation) {
            if (in_array((int) $operation['edge_id'], $edgeIds, true)
                && $operation['state'] === 'applied'
                && (
                    $operation['last_error_code'] === 'provider_operation_applied'
                    || (
                        $operation['desired_action'] === 'revoke'
                        && $operation['last_error_code'] === 'provider_operation_finalized'
                    )
                )
                && ($latest === null || (int) $operation['id'] > (int) $latest['id'])
            ) {
                $latest = $operation;
            }
        }

        return in_array((string) ($latest['desired_action'] ?? ''), ['grant', 'resume'], true);
    }

    public function finalizeAppliedRevoke(int $id): bool
    {
        if (!isset($this->operations[$id])
            || $this->operations[$id]['state'] !== 'applied'
            || $this->operations[$id]['desired_action'] !== 'revoke'
            || $this->operations[$id]['last_error_code'] === 'provider_operation_finalized'
        ) {
            return false;
        }
        $this->operations[$id]['last_error_code'] = 'provider_operation_finalized';
        return true;
    }

    public function recordTestOutcome(int $id, ProviderOperationOutcome $outcome): void
    {
        if (!isset($this->operations[$id])) {
            return;
        }
        $this->operations[$id]['state'] = match ($outcome->status) {
            'applied', 'already-applied' => 'applied',
            'deferred' => 'deferred',
            default => 'failed',
        };
        $this->operations[$id]['retryable'] = $outcome->status === 'retryable-failure';
        $this->operations[$id]['last_error_code'] = $outcome->code;
    }
}

final class CutoverPlanRepository extends PlanRepository
{
    public function __construct()
    {
    }

    public function find(int $id): ?array
    {
        return ['id' => $id, 'title' => 'Plan'];
    }
}

final class CutoverSourceRepository extends GrantSourceRepository
{
    public array $links = [];

    public function __construct()
    {
    }

    public function addSource(int $grantId, string $sourceType, int $sourceId): bool
    {
        $this->links[$grantId . '|' . $sourceType . '|' . $sourceId] = true;
        return true;
    }

    public function removeSource(int $grantId, string $sourceType, int $sourceId): bool
    {
        unset($this->links[$grantId . '|' . $sourceType . '|' . $sourceId]);
        return true;
    }
}

final class CutoverDripRepository extends DripScheduleRepository
{
    public array $scheduled = [];
    public array $deleted = [];

    public function __construct()
    {
    }

    public function schedule(array $data): int
    {
        $this->scheduled[] = $data;
        return count($this->scheduled);
    }

    public function deleteByGrantId(int $grantId): int
    {
        $this->deleted[] = $grantId;
        return 1;
    }
}

final class CutoverAdapter implements AccessAdapterInterface
{
    public static bool $assigned = false;
    public static bool $throwOnCheck = false;
    public static int $checks = 0;
    public static int $grantCalls = 0;
    public static int $revokeCalls = 0;

    public static function reset(): void
    {
        self::$assigned = false;
        self::$throwOnCheck = false;
        self::$checks = 0;
        self::$grantCalls = 0;
        self::$revokeCalls = 0;
    }

    public function supports(string $resourceType): bool
    {
        return true;
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$grantCalls++;
        return ['success' => true, 'message' => 'granted'];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$revokeCalls++;
        return ['success' => true, 'message' => 'revoked'];
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        self::$checks++;
        $GLOBALS['_fchub_cutover_events'][] = 'check';
        if (self::$throwOnCheck) {
            throw new \RuntimeException('sensitive provider payload');
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
