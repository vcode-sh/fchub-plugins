<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Lifecycle;

use FChubMemberships\Domain\Lifecycle\MembershipLifecycleCoordinator;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Entitlement\EntitlementService;
use FChubMemberships\Domain\Event\EventClaimResult;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MembershipLifecycleCoordinatorTest extends PluginTestCase
{
    #[DataProvider('lifecycleEntryPointProvider')]
    public function test_one_coordinator_exposes_each_canonical_lifecycle_entry_point(string $method): void
    {
        self::assertTrue(method_exists(MembershipLifecycleCoordinator::class, $method));
    }

    public static function lifecycleEntryPointProvider(): array
    {
        return [
            'paid' => ['paid'],
            'renewal' => ['renew'],
            'pause' => ['pause'],
            'resume' => ['resume'],
            'immediate or wait cancellation' => ['cancel'],
            'refund' => ['refund'],
            'end of term' => ['endOfTerm'],
            'expiry' => ['expire'],
            'reactivation' => ['reactivate'],
            'grace completion and maintenance' => ['checkValidity'],
        ];
    }

    public function test_paid_and_exact_feed_refund_delegate_once_through_the_coordinator(): void
    {
        [$coordinator, $access] = $this->coordinator();
        $context = ['source_type' => 'order', 'source_id' => 91, 'feed_id' => 7, 'feed_scope' => 'product'];

        $coordinator->paid(17, 5, $context);
        $coordinator->refund(17, 5, $context);

        self::assertSame([[17, 5, $context]], $access->paid);
        self::assertSame([[17, 5, $context]], $access->revoked);
    }

    public function test_verified_distinct_renewal_extends_once_and_duplicate_receipt_does_not_mutate(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge(['expires_at' => '2026-09-01 00:00:00'])];
        $payload = $this->renewalPayload();
        $renewedHooks = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_renewed'] = [
            static function (array $grant, int $renewalCount) use (&$renewedHooks): void {
                $renewedHooks[] = [$grant, $renewalCount];
            },
        ];

        $first = $coordinator->renew($payload);
        $access->claim = EventClaimResult::duplicateSucceeded();
        $second = $coordinator->renew($payload);

        self::assertSame('processed', $first['status']);
        self::assertSame('duplicate', $second['status']);
        self::assertCount(1, $entitlements->extended);
        self::assertSame('2026-09-01 00:00:00', $entitlements->extended[0][1]);
        self::assertCount(1, $entitlements->projections);
        self::assertCount(1, $access->succeeded);
        self::assertCount(1, $renewedHooks);
        self::assertSame(4, $renewedHooks[0][1]);
    }

    public function test_terminal_renewal_uses_positive_order_successor_and_unverified_reactivation_fails_closed(): void
    {
        [$coordinator, , $entitlements] = $this->coordinator();
        $entitlements->ended = [$this->edge(['lifecycle' => 'ended'])];

        $renewed = $coordinator->renew($this->renewalPayload());
        $unverified = $coordinator->reactivate(['subscription' => (object) ['id' => 88]]);

        self::assertSame('processed', $renewed['status']);
        self::assertSame(1201, $entitlements->successors[0][1]);
        self::assertSame(88, $entitlements->successors[0][2]);
        self::assertSame('unverified', $unverified['status']);
        self::assertSame('missing_positive_renewal_order', $unverified['reason']);
    }

    public function test_renewal_and_resume_apply_each_edge_policy_and_absolute_term_cap(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [
            $this->edge([
                'id' => 1,
                'resource_id' => '41',
                'policy' => [
                    'subscription_id' => 88,
                    'validity_mode' => 'mirror_subscription',
                    'membership_term_ends_at' => '2026-09-15 00:00:00',
                ],
            ]),
            $this->edge([
                'id' => 2,
                'resource_id' => '42',
                'expires_at' => '2026-08-10 23:59:59',
                'policy' => [
                    'subscription_id' => 88,
                    'validity_mode' => 'anchor_billing',
                    'billing_anchor_day' => 10,
                ],
            ]),
            $this->edge([
                'id' => 3,
                'resource_id' => '43',
                'expires_at' => null,
                'policy' => ['subscription_id' => 88, 'validity_mode' => 'lifetime'],
            ]),
            $this->edge([
                'id' => 4,
                'resource_id' => '44',
                'expires_at' => '2026-08-01 00:00:00',
                'policy' => [
                    'subscription_id' => 88,
                    'validity_mode' => 'fixed_duration',
                    'validity_days' => 14,
                ],
            ]),
        ];
        $payload = $this->renewalPayload();
        $payload['subscription']->next_billing_date = '2026-10-01 00:00:00';

        $coordinator->renew($payload);

        self::assertSame([
            '2026-09-15 00:00:00',
            '2026-09-10 23:59:59',
            '2026-08-15 00:00:00',
        ], array_column($entitlements->extended, 1));

        $entitlements->active = [$this->edge([
            'policy' => [
                'subscription_id' => 88,
                'membership_term_ends_at' => '2026-07-31 23:59:59',
            ],
        ])];
        $coordinator->resume((object) ['id' => 88]);
        self::assertSame([], $access->resumed, 'An absolute term that has ended must not resume.');
    }

    public function test_edge_policy_controls_wait_immediate_and_grace_cancellation(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [
            $this->edge(['id' => 1, 'policy' => ['subscription_id' => 88, 'cancel_behavior' => 'wait_validity']]),
            $this->edge([
                'id' => 2,
                'resource_id' => '43',
                'policy' => ['subscription_id' => 88, 'cancel_behavior' => 'immediate', 'grace_period_days' => 7],
            ]),
        ];

        $result = $coordinator->cancel((object) ['id' => 88]);

        self::assertSame('deferred', $result['results'][0]['status']);
        self::assertCount(1, $access->revoked);
        self::assertSame(7, $access->revoked[0][2]['grace_period_days']);
        self::assertSame('subscription', $access->revoked[0][2]['source_type']);
        self::assertSame(7, $access->revoked[0][2]['feed_id']);
        self::assertSame('product', $access->revoked[0][2]['feed_scope']);
    }

    public function test_lifecycle_failures_expose_and_persist_only_stable_generic_codes(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge()];
        $entitlements->failure = new \RuntimeException('secret provider payload and customer@example.test');

        $result = $coordinator->renew($this->renewalPayload());

        self::assertSame('failed', $result['status']);
        self::assertSame('lifecycle_processing_failed', $result['reason']);
        self::assertSame('lifecycle_processing_failed', $access->failed[0][2]);
        self::assertStringNotContainsString('secret', serialize([$result, $access->failed]));
        self::assertStringNotContainsString('customer@example.test', serialize([$result, $access->failed]));
    }

    public function test_present_malformed_policy_fails_receipt_without_projection_count_or_hook(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge([
            'policy' => [
                'subscription_id' => 88,
                'validity_mode' => 'mirror_subscription',
                'membership_term_ends_at' => '2026-99-99 25:61:61',
            ],
        ])];
        $hooks = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_renewed'] = [
            static function () use (&$hooks): void { $hooks++; },
        ];

        $result = $coordinator->renew($this->renewalPayload());

        self::assertSame('failed', $result['status']);
        self::assertSame('invalid_lifecycle_policy', $result['reason']);
        self::assertFalse($access->failed[0][3]);
        self::assertSame([], $access->succeeded);
        self::assertSame([], $entitlements->projections);
        self::assertSame(0, $hooks);
    }

    #[DataProvider('malformedRenewalOrderProvider')]
    public function test_renewal_prevalidates_the_complete_batch_before_any_mutation(bool $malformedFirst): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $valid = $this->edge(['id' => 1, 'resource_id' => '41']);
        $malformed = $this->edge([
            'id' => 2,
            'resource_id' => '42',
            'policy' => ['subscription_id' => 88, 'validity_mode' => 'fixed_duration', 'validity_days' => 0],
        ]);
        $entitlements->active = $malformedFirst ? [$malformed, $valid] : [$valid, $malformed];

        $result = $coordinator->renew($this->renewalPayload());

        self::assertSame('failed', $result['status']);
        self::assertSame('invalid_lifecycle_policy', $result['reason']);
        self::assertSame([], $entitlements->extended);
        self::assertSame([], $entitlements->successors);
        self::assertSame([], $entitlements->projections);
        self::assertCount(1, $access->failed);
    }

    public static function malformedRenewalOrderProvider(): array
    {
        return ['valid then malformed' => [false], 'malformed then valid' => [true]];
    }

    public function test_renewal_extends_active_and_creates_unrepresented_ended_successor_in_one_batch(): void
    {
        [$coordinator, , $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge(['id' => 1, 'resource_id' => '41'])];
        $entitlements->ended = [
            $this->edge(['id' => 2, 'resource_id' => '42', 'lifecycle' => 'ended']),
            $this->edge(['id' => 3, 'resource_id' => '41', 'lifecycle' => 'ended']),
        ];

        $result = $coordinator->renew($this->renewalPayload());

        self::assertSame('processed', $result['status']);
        self::assertCount(1, $entitlements->extended);
        self::assertCount(1, $entitlements->successors);
        self::assertSame('42', $entitlements->successors[0][0]['resource_id']);
    }

    #[DataProvider('representedEndedHistoryProvider')]
    public function test_active_current_generation_blocks_resurrection_and_irrelevant_ended_validation(array $endedPolicy): void
    {
        [$coordinator, , $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge([
            'id' => 10,
            'policy' => [
                'subscription_id' => 88,
                'validity_mode' => 'fixed_duration',
                'validity_days' => 14,
                'membership_term_ends_at' => '2026-07-31 23:59:59',
            ],
        ])];
        $entitlements->ended = [$this->edge([
            'id' => 9,
            'lifecycle' => 'ended',
            'starts_at' => '2026-06-01 00:00:00',
            'policy' => $endedPolicy,
        ])];

        $result = $coordinator->renew($this->renewalPayload());

        self::assertSame('processed', $result['status']);
        self::assertSame([], $entitlements->extended);
        self::assertSame([], $entitlements->successors);
        self::assertSame([], $entitlements->projections);
    }

    public static function representedEndedHistoryProvider(): array
    {
        return [
            'eligible ended predecessor' => [[
                'subscription_id' => 88,
                'validity_mode' => 'fixed_duration',
                'validity_days' => 14,
            ]],
            'malformed irrelevant history' => [[
                'subscription_id' => 88,
                'validity_mode' => 'fixed_duration',
                'validity_days' => 0,
            ]],
        ];
    }

    #[DataProvider('receiptRetryPolicyProvider')]
    public function test_same_receipt_retry_never_extends_validity_twice(array $edge, string $firstExpiry): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge($edge)];
        $access->succeedResults = [false, true];

        $first = $coordinator->renew($this->renewalPayload());
        $second = $coordinator->renew($this->renewalPayload());

        self::assertSame('failed', $first['status']);
        self::assertSame('processed', $second['status']);
        self::assertSame([$firstExpiry], array_column($entitlements->extended, 1));
    }

    public static function receiptRetryPolicyProvider(): array
    {
        return [
            'fixed duration' => [[
                'expires_at' => '2026-08-01 00:00:00',
                'policy' => ['subscription_id' => 88, 'validity_mode' => 'fixed_duration', 'validity_days' => 14],
            ], '2026-08-15 00:00:00'],
            'anchor billing' => [[
                'expires_at' => '2026-08-10 23:59:59',
                'policy' => ['subscription_id' => 88, 'validity_mode' => 'anchor_billing', 'billing_anchor_day' => 10],
            ], '2026-09-10 23:59:59'],
        ];
    }

    #[DataProvider('successorReceiptRetryPolicyProvider')]
    public function test_same_receipt_retry_never_extends_a_created_successor(array $edge): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->ended = [$this->edge(array_merge($edge, ['id' => 9, 'lifecycle' => 'ended']))];
        $access->succeedResults = [false, true];

        $first = $coordinator->renew($this->renewalPayload());
        $second = $coordinator->renew($this->renewalPayload());

        self::assertSame('failed', $first['status']);
        self::assertSame('processed', $second['status']);
        self::assertCount(1, $entitlements->successors);
        self::assertSame([], $entitlements->extended);
    }

    public static function successorReceiptRetryPolicyProvider(): array
    {
        return [
            'fixed duration' => [[
                'expires_at' => '2026-08-01 00:00:00',
                'policy' => ['subscription_id' => 88, 'validity_mode' => 'fixed_duration', 'validity_days' => 14],
            ]],
            'anchor billing' => [[
                'expires_at' => '2026-08-10 23:59:59',
                'policy' => ['subscription_id' => 88, 'validity_mode' => 'anchor_billing', 'billing_anchor_day' => 10],
            ]],
        ];
    }

    #[DataProvider('latestEndedPolicyProvider')]
    public function test_renewal_chooses_latest_ended_generation_per_lineage(array $latestPolicy): void
    {
        [$coordinator, , $entitlements] = $this->coordinator();
        $entitlements->ended = [
            $this->edge([
                'id' => 2,
                'lifecycle' => 'ended',
                'starts_at' => '2026-05-01 00:00:00',
                'policy' => ['subscription_id' => 88, 'validity_mode' => 'fixed_duration', 'validity_days' => 3],
            ]),
            $this->edge([
                'id' => 3,
                'lifecycle' => 'ended',
                'starts_at' => '2026-06-01 00:00:00',
                'policy' => $latestPolicy,
            ]),
        ];

        $coordinator->renew($this->renewalPayload());

        self::assertCount(1, $entitlements->successors);
        self::assertSame(3, $entitlements->successors[0][0]['id']);
        self::assertSame($latestPolicy, $entitlements->successors[0][0]['policy']);
    }

    public static function latestEndedPolicyProvider(): array
    {
        return [
            'fixed duration' => [[
                'subscription_id' => 88,
                'validity_mode' => 'fixed_duration',
                'validity_days' => 21,
            ]],
            'anchor billing' => [[
                'subscription_id' => 88,
                'validity_mode' => 'anchor_billing',
                'billing_anchor_day' => 17,
            ]],
        ];
    }

    public function test_throwing_renewal_observer_cannot_reverse_a_succeeded_receipt(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge()];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_renewed'] = [
            static function (): void {
                throw new \RuntimeException('observer failed');
            },
        ];

        $result = $coordinator->renew($this->renewalPayload());

        self::assertSame('processed', $result['status']);
        self::assertCount(1, $access->succeeded);
        self::assertSame([], $access->failed);
    }

    public function test_pause_resume_eot_expiry_grace_completion_and_maintenance_share_the_coordinator(): void
    {
        $grants = new LifecycleGrantRepository();
        [$coordinator, $access, $entitlements] = $this->coordinator($grants);
        $entitlements->active = [$this->edge(['expires_at' => '2026-09-01 00:00:00'])];

        $coordinator->pause((object) ['id' => 88]);
        $coordinator->resume((object) ['id' => 88]);
        $coordinator->endOfTerm((object) ['id' => 88]);
        $coordinator->expire((object) ['id' => 88]);
        $maintenance = $coordinator->checkValidity();

        self::assertSame([91], $access->paused);
        self::assertSame([91], $access->resumed);
        self::assertCount(2, $access->revoked);
        self::assertSame([0, 0], array_column(array_column($access->revoked, 2), 'grace_period_days'));
        self::assertSame([
            'anchor_paused' => 0,
            'term_expired' => 0,
            'grace_completed' => 3,
            'expired' => 0,
        ], $maintenance);
    }

    public function test_automatic_expiry_ends_exact_edge_then_verified_renewal_creates_successor(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $expired = $this->edge(['expires_at' => '2026-07-31 23:59:59']);
        $entitlements->active = [$expired];
        $entitlements->due = [$expired];
        $access->onRevoke = static function () use ($entitlements, $expired): void {
            $entitlements->active = [];
            $entitlements->ended = [array_merge($expired, ['lifecycle' => 'ended'])];
        };

        $maintenance = $coordinator->checkValidity();
        $renewed = $coordinator->renew($this->renewalPayload());

        self::assertSame(1, $maintenance['expired']);
        self::assertSame([1], $access->revoked[0][2]['edge_ids']);
        self::assertSame('expired', $access->revoked[0][2]['terminal_status']);
        self::assertSame('processed', $renewed['status']);
        self::assertSame(1201, $entitlements->successors[0][1]);
    }

    public function test_automatic_expiry_failure_returns_only_the_stable_error_contract(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->due = [$this->edge(['expires_at' => '2026-07-31 23:59:59'])];
        $access->revokeThrowable = new \RuntimeException('secret provider expiry failure');

        $maintenance = $coordinator->checkValidity();

        self::assertSame('lifecycle_processing_failed', $maintenance['error']);
        self::assertSame(0, $maintenance['expired']);
        self::assertStringNotContainsString('secret provider expiry failure', serialize($maintenance));
    }

    public function test_automatic_validity_pauses_recoverable_anchor_and_expires_ordinary_or_absolute_term_edges(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $anchor = $this->edge([
            'id' => 1,
            'resource_id' => '42',
            'expires_at' => '2026-07-31 23:59:59',
            'policy' => ['subscription_id' => 88, 'validity_mode' => 'anchor_billing', 'billing_anchor_day' => 10],
        ]);
        $ordinary = $this->edge(['id' => 2, 'resource_id' => '43', 'expires_at' => '2026-07-31 23:59:59']);
        $absolute = $this->edge([
            'id' => 3,
            'resource_id' => '44',
            'expires_at' => '2026-07-31 23:59:59',
            'policy' => [
                'subscription_id' => 88,
                'validity_mode' => 'anchor_billing',
                'billing_anchor_day' => 10,
                'membership_term_ends_at' => '2026-07-31 23:59:59',
            ],
        ]);
        $entitlements->active = [$anchor, $ordinary, $absolute];
        $entitlements->due = [$anchor, $ordinary, $absolute];

        $maintenance = $coordinator->checkValidity();

        self::assertSame(1, $maintenance['anchor_paused']);
        self::assertSame(2, $maintenance['expired']);
        self::assertSame([91], $access->paused);
        self::assertSame([2, 3], $access->revoked[0][2]['edge_ids']);
        self::assertSame('active', $entitlements->active[0]['lifecycle']);
    }

    public function test_due_anchor_does_not_pause_resource_justified_by_another_active_edge(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $anchor = $this->edge([
            'id' => 1,
            'expires_at' => '2026-07-31 23:59:59',
            'policy' => ['subscription_id' => 88, 'validity_mode' => 'anchor_billing', 'billing_anchor_day' => 10],
        ]);
        $lifetime = $this->edge([
            'id' => 2,
            'plan_id' => 6,
            'feed_id' => 8,
            'source_id' => 89,
            'expires_at' => null,
            'policy' => ['subscription_id' => 89, 'validity_mode' => 'lifetime'],
        ]);
        $entitlements->active = [$anchor, $lifetime];
        $entitlements->due = [$anchor];

        $maintenance = $coordinator->checkValidity();

        self::assertSame(0, $maintenance['anchor_paused']);
        self::assertSame([], $access->paused);
        self::assertSame(0, $maintenance['expired']);
        self::assertSame('active', $entitlements->active[0]['lifecycle']);
    }

    public function test_subscription_pause_does_not_pause_resource_justified_by_another_effective_edge(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $target = $this->edge([
            'id' => 1,
            'expires_at' => '2026-09-01 00:00:00',
            'policy' => ['subscription_id' => 88],
        ]);
        $survivor = $this->edge([
            'id' => 2,
            'plan_id' => 6,
            'feed_id' => 8,
            'source_id' => 89,
            'expires_at' => null,
            'policy' => ['subscription_id' => 89, 'validity_mode' => 'lifetime'],
        ]);
        $entitlements->active = [$target, $survivor];

        $result = $coordinator->pause((object) ['id' => 88]);

        self::assertSame(['status' => 'processed', 'changed' => 1], $result);
        self::assertSame([], $access->paused);
        self::assertSame([[[1], 'paused']], $entitlements->accessStatusChanges);
        self::assertSame('paused', $entitlements->active[0]['access_status']);
        self::assertSame('active', $entitlements->active[1]['access_status']);
    }

    public function test_subscription_resume_restores_only_the_target_lineage_while_survivor_keeps_aggregate_active(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $target = $this->edge([
            'id' => 1,
            'access_status' => 'paused',
            'expires_at' => '2026-09-01 00:00:00',
            'policy' => ['subscription_id' => 88],
        ]);
        $survivor = $this->edge([
            'id' => 2,
            'plan_id' => 6,
            'feed_id' => 8,
            'source_id' => 89,
            'expires_at' => null,
            'policy' => ['subscription_id' => 89, 'validity_mode' => 'lifetime'],
        ]);
        $entitlements->active = [$target, $survivor];

        $result = $coordinator->resume((object) ['id' => 88]);

        self::assertSame(['status' => 'processed', 'changed' => 1], $result);
        self::assertSame([], $access->resumed);
        self::assertSame([[[1], 'active']], $entitlements->accessStatusChanges);
        self::assertSame('active', $entitlements->active[0]['access_status']);
    }

    public function test_subscription_pause_returns_failed_without_persisting_when_transition_is_rejected(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge([
            'expires_at' => '2026-09-01 00:00:00',
            'policy' => ['subscription_id' => 88],
        ])];
        $access->pauseResults[] = ['success' => false, 'pending' => false];

        $result = $coordinator->pause((object) ['id' => 88]);

        self::assertSame(['status' => 'failed', 'reason' => 'provider_transition_failed'], $result);
        self::assertSame([], $entitlements->accessStatusChanges);
        self::assertSame('active', $entitlements->active[0]['access_status']);
    }

    public function test_subscription_pause_persists_pending_intent_without_reporting_verified_success(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge([
            'expires_at' => '2026-09-01 00:00:00',
            'policy' => ['subscription_id' => 88],
        ])];
        $access->pauseResults[] = ['success' => false, 'pending' => true];

        $result = $coordinator->pause((object) ['id' => 88]);

        self::assertSame(['status' => 'pending', 'changed' => 1], $result);
        self::assertSame([[[1], 'paused']], $entitlements->accessStatusChanges);
        self::assertSame('paused', $entitlements->active[0]['access_status']);
    }

    public function test_edge_persistence_failure_compensates_a_verified_pause_in_order(): void
    {
        $GLOBALS['_fchub_lifecycle_transition_trace'] = [];
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $entitlements->active = [$this->edge([
            'expires_at' => '2026-09-01 00:00:00',
            'policy' => ['subscription_id' => 88],
        ])];
        $entitlements->accessStatusFailure = new \RuntimeException('edge write failed');

        $result = $coordinator->pause((object) ['id' => 88]);

        self::assertSame(['status' => 'failed', 'reason' => 'lifecycle_processing_failed'], $result);
        self::assertSame(['pause', 'edge:paused', 'resume'], $GLOBALS['_fchub_lifecycle_transition_trace']);
        self::assertSame([91], $access->paused);
        self::assertSame([91], $access->resumed);
        self::assertSame('active', $entitlements->active[0]['access_status']);
    }

    public function test_subscription_pause_pauses_shared_aggregate_once_when_all_effective_edges_are_targeted(): void
    {
        [$coordinator, $access, $entitlements] = $this->coordinator();
        $first = $this->edge([
            'id' => 1,
            'expires_at' => '2026-09-01 00:00:00',
            'policy' => ['subscription_id' => 88],
        ]);
        $second = $this->edge([
            'id' => 2,
            'plan_id' => 6,
            'feed_id' => 8,
            'expires_at' => '2026-10-01 00:00:00',
            'policy' => ['subscription_id' => 88],
        ]);
        $entitlements->active = [$first, $second];

        $result = $coordinator->pause((object) ['id' => 88]);

        self::assertSame(['status' => 'processed', 'changed' => 1], $result);
        self::assertSame([91], $access->paused);
    }

    private function coordinator(?LifecycleGrantRepository $grants = null): array
    {
        $access = new LifecycleAccessGrantService();
        $entitlements = new LifecycleEntitlementService();
        $grants ??= new LifecycleGrantRepository();
        $timezone = new \DateTimeZone('UTC');
        $clock = new Clock(new \DateTimeImmutable('2026-08-01 00:00:00', $timezone), $timezone);

        return [
            new MembershipLifecycleCoordinator($access, $entitlements, $grants, $clock, static fn(): string => 'owner-a'),
            $access,
            $entitlements,
        ];
    }

    private function renewalPayload(): array
    {
        return [
            'subscription' => (object) ['id' => 88, 'next_billing_date' => '2026-09-01 00:00:00'],
            'order' => (object) ['id' => 1201],
        ];
    }

    private function edge(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'user_id' => 17,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '42',
            'plan_id' => 5,
            'feed_id' => 7,
            'feed_scope' => 'product',
            'source_type' => 'subscription',
            'source_id' => 88,
            'lifecycle' => 'active',
            'access_status' => 'active',
            'expires_at' => '2026-08-01 00:00:00',
            'policy' => ['subscription_id' => 88, 'cancel_behavior' => 'immediate'],
        ], $overrides);
    }
}

final class LifecycleEntitlementService extends EntitlementService
{
    public array $active = [];
    public array $ended = [];
    public array $extended = [];
    public array $successors = [];
    public array $projections = [];
    public array $due = [];
    public array $appliedReceipts = [];
    public array $accessStatusChanges = [];
    public ?\Throwable $failure = null;
    public ?\Throwable $accessStatusFailure = null;
    public function __construct() {}
    public function getBySubscriptionCorrelation(int $subscriptionId, string $lifecycle): array
    {
        $edges = $lifecycle === 'active' ? $this->active : $this->ended;
        return array_values(array_filter($edges, static fn(array $edge): bool =>
            (int) (($edge['policy']['subscription_id'] ?? $edge['source_id'] ?? 0)) === $subscriptionId
        ));
    }
    public function getDueActive(string $at): array { return $this->due; }
    public function getActiveByResource(array $edge): array
    {
        return array_values(array_filter($this->active, static fn(array $active): bool =>
            (int) $active['user_id'] === (int) $edge['user_id']
            && (string) $active['provider'] === (string) $edge['provider']
            && (string) $active['resource_type'] === (string) $edge['resource_type']
            && (string) $active['resource_id'] === (string) $edge['resource_id']
            && ($active['lifecycle'] ?? '') === 'active'
        ));
    }
    public function setAccessStatus(array $edges, string $accessStatus): int
    {
        $GLOBALS['_fchub_lifecycle_transition_trace'][] = 'edge:' . $accessStatus;
        if ($this->accessStatusFailure !== null) {
            throw $this->accessStatusFailure;
        }
        $edgeIds = array_map(static fn(array $edge): int => (int) $edge['id'], $edges);
        $this->accessStatusChanges[] = [$edgeIds, $accessStatus];
        $changed = 0;
        foreach ($this->active as $index => $active) {
            if (!in_array((int) $active['id'], $edgeIds, true)
                || ($active['access_status'] ?? 'active') === $accessStatus
            ) {
                continue;
            }
            $this->active[$index]['access_status'] = $accessStatus;
            $changed++;
        }

        return $changed;
    }
    public function extendActiveExpiry(array $edge, string $newExpiry, string $eventReceipt): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $receiptKey = (int) $edge['id'] . ':' . $eventReceipt;
        if (isset($this->appliedReceipts[$receiptKey])) {
            foreach ($this->active as $active) {
                if ((int) $active['id'] === (int) $edge['id']) {
                    return ['action' => 'replayed', 'edge' => $active];
                }
            }
        }
        $this->extended[] = [$edge, $newExpiry, $eventReceipt];
        foreach ($this->active as $index => $active) {
            if ((int) $active['id'] === (int) $edge['id']) {
                $this->active[$index]['expires_at'] = $newExpiry;
                $this->appliedReceipts[$receiptKey] = true;
                $edge = $this->active[$index];
                break;
            }
        }
        return ['action' => 'extended', 'edge' => $edge];
    }
    public function createRenewalSuccessor(array $predecessor, int $renewalOrderId, int $subscriptionId, ?string $expiresAt, string $eventReceipt): array
    {
        $this->successors[] = [$predecessor, $renewalOrderId, $subscriptionId, $expiresAt, $eventReceipt];
        $successor = array_merge($predecessor, [
            'id' => 100 + count($this->successors),
            'source_type' => 'order',
            'source_id' => $renewalOrderId,
            'lifecycle' => 'active',
            'expires_at' => $expiresAt,
        ]);
        $this->active[] = $successor;
        $this->appliedReceipts[(int) $successor['id'] . ':' . $eventReceipt] = true;
        return ['action' => 'created', 'edge' => $successor];
    }
    public function projectLifecycleRenewal(array $edge, string $eventReceipt): array
    {
        $this->projections[] = [$edge, $eventReceipt];
        return ['renewed' => true, 'renewal_count' => 4, 'grant' => ['id' => 91, 'renewal_count' => 4]];
    }
}

final class LifecycleAccessGrantService extends AccessGrantService
{
    public array $paid = [];
    public array $revoked = [];
    public array $paused = [];
    public array $resumed = [];
    public array $succeeded = [];
    public array $failed = [];
    public EventClaimResult $claim;
    public ?\Closure $onRevoke = null;
    public ?\Throwable $revokeThrowable = null;
    public array $succeedResults = [];
    public array $pauseResults = [];
    public array $resumeResults = [];
    public function __construct() { $this->claim = EventClaimResult::acquired(); }
    public function grantPlan(int $userId, int $planId, array $context = []): array { $this->paid[] = [$userId, $planId, $context]; return ['created' => 1]; }
    public function revokePlan(int $userId, int $planId, array $context = []): array { if ($this->revokeThrowable !== null) { throw $this->revokeThrowable; } $this->revoked[] = [$userId, $planId, $context]; if ($this->onRevoke !== null) { ($this->onRevoke)(); } return ['success' => true, 'revoked' => isset($context['edge_ids']) ? count($context['edge_ids']) : 1, 'pending' => 0, 'failed' => 0]; }
    public function subscriptionRenewalEventHash(array $payload): string { return hash('sha256', 'subscription:88|renewal_order:1201|trigger:subscription_renewed'); }
    public function claimSubscriptionRenewalEvent(array $payload, string $ownerToken, int $leaseSeconds = 300): EventClaimResult { return $this->claim; }
    public function succeedEventLock(string $eventHash, string $ownerToken): bool { $this->succeeded[] = [$eventHash, $ownerToken]; return $this->succeedResults !== [] ? (bool) array_shift($this->succeedResults) : true; }
    public function failEventLock(string $eventHash, string $ownerToken, string $error, bool $retryable = true): bool { $this->failed[] = [$eventHash, $ownerToken, $error, $retryable]; return true; }
    public function pauseGrant(int $grantId, string $reason = ''): array { $GLOBALS['_fchub_lifecycle_transition_trace'][] = 'pause'; $this->paused[] = $grantId; return $this->pauseResults !== [] ? array_shift($this->pauseResults) : ['success' => true]; }
    public function resumeGrant(int $grantId): array { $GLOBALS['_fchub_lifecycle_transition_trace'][] = 'resume'; $this->resumed[] = $grantId; return $this->resumeResults !== [] ? array_shift($this->resumeResults) : ['success' => true]; }
    public function pauseOverdueAnchorGrants(): int { return 1; }
    public function expireTermExpiredGrants(): int { return 2; }
    public function revokeExpiredGracePeriodGrants(): int { return 3; }
    public function expireOverdueGrantsWithHooks(): int { return 4; }
}

final class LifecycleGrantRepository extends GrantRepository
{
    public function __construct() {}
    public function findByGrantKey(string $grantKey): ?array
    {
        foreach (['42' => 91, '43' => 92, '44' => 93] as $resourceId => $id) {
            if ($grantKey === self::makeGrantKey(17, 'wordpress_core', 'post', (string) $resourceId)) {
                return ['id' => $id, 'status' => 'active'];
            }
        }
        return null;
    }
}
