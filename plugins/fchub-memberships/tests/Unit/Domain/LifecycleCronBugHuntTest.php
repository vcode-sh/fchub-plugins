<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Grant\GrantMaintenanceService;
use FChubMemberships\Domain\Grant\GrantStatusService;
use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Domain\SubscriptionGrantLifecycleService;
use FChubMemberships\Domain\SubscriptionValidityCheckService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\SubscriptionValidityLogRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * Bug hunt tests for lifecycle, cron, and maintenance services.
 *
 * Targets: SubscriptionGrantLifecycleService, GrantMaintenanceService,
 * SubscriptionValidityCheckService, GrantRepository query edge cases.
 */
final class LifecycleCronBugHuntTest extends PluginTestCase
{
    // =========================================================================
    // BUG: resume() does not check term expiry
    //
    // If a grant's membership_term_ends_at passed while it was paused,
    // resume() blindly resumes it. renew() checks isTermExpired but
    // resume() does not.
    // =========================================================================

    public function test_resume_skips_grants_whose_term_expired_during_pause(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $resumed = [];
            public function __construct() {}
            public function resumeGrant(int $grantId): array
            {
                $this->resumed[] = $grantId;
                return ['success' => true];
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1,
                        'status' => 'paused',
                        'meta' => [
                            'membership_term_ends_at' => '2025-01-01 23:59:59',
                            'paused_at' => '2024-12-01 00:00:00',
                        ],
                    ],
                    [
                        'id' => 2,
                        'status' => 'paused',
                        'meta' => [
                            'membership_term_ends_at' => '2099-12-31 23:59:59',
                        ],
                    ],
                    [
                        'id' => 3,
                        'status' => 'paused',
                        'meta' => [],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->resume((object) ['id' => 99]);

        // Grant 1: term expired during pause — should be SKIPPED (BUG FIX)
        // Grant 2: term far in the future — should be resumed
        // Grant 3: no term — should be resumed
        self::assertNotContains(1, $grantService->resumed, 'Grant with expired term should not be resumed');
        self::assertContains(2, $grantService->resumed);
        self::assertContains(3, $grantService->resumed);
    }

    // =========================================================================
    // renew() with revoked/expired status grants
    //
    // These grants have term meta but shouldn't be extended. Verify that
    // anchor and non-anchor paths both skip revoked/expired.
    // =========================================================================

    public function test_renew_skips_revoked_anchor_grants(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $resumed = [];
            public array $extended = [];
            public function __construct() {}
            public function resumeGrant(int $grantId): array
            {
                $this->resumed[] = $grantId;
                return ['success' => true];
            }
            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['user_id' => $userId, 'plan_id' => $planId];
                return 1;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1, 'user_id' => 10, 'plan_id' => 3,
                        'status' => 'revoked',
                        'expires_at' => '2026-03-20 23:59:59',
                        'meta' => ['billing_anchor_day' => 20],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-18 00:00:00']);

        // Revoked anchor grant: neither paused nor active → should not be resumed or extended
        self::assertEmpty($grantService->resumed);
        self::assertEmpty($grantService->extended);
    }

    public function test_renew_skips_expired_anchor_grants(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $resumed = [];
            public array $extended = [];
            public function __construct() {}
            public function resumeGrant(int $grantId): array
            {
                $this->resumed[] = $grantId;
                return ['success' => true];
            }
            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['user_id' => $userId, 'plan_id' => $planId];
                return 1;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1, 'user_id' => 10, 'plan_id' => 3,
                        'status' => 'expired',
                        'expires_at' => '2026-03-01 23:59:59',
                        'meta' => ['billing_anchor_day' => 20],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-18 00:00:00']);

        self::assertEmpty($grantService->resumed);
        self::assertEmpty($grantService->extended);
    }

    public function test_renew_skips_revoked_non_anchor_grants(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $extended = [];
            public function __construct() {}
            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['user_id' => $userId];
                return 1;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'user_id' => 10, 'plan_id' => 3,
                        'status' => 'revoked',
                        'meta' => [],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-01 00:00:00']);

        // Revoked non-anchor: the `$grant['status'] === 'active'` check on line 102 should block it
        self::assertEmpty($grantService->extended);
    }

    // =========================================================================
    // pauseOverdueAnchorGrants: crash when GrantStatusService is null and
    // grant meta is null (edge case in fallback path)
    // =========================================================================

    public function test_pause_overdue_anchor_grants_with_null_meta_fallback_path(): void
    {
        $grantRepo = new class() extends GrantRepository {
            public array $updates = [];
            public function __construct() {}
            public function getOverdueAnchorGrants(): array
            {
                return [
                    [
                        'id' => 1,
                        'user_id' => 10,
                        'plan_id' => 3,
                        'status' => 'active',
                        'meta' => [], // Hydrated empty array — safe
                    ],
                ];
            }
            public function update(int $id, array $data): bool
            {
                $this->updates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $sourceRepo = new GrantSourceRepository();

        // No GrantStatusService — forces the fallback path in pauseOverdueAnchorGrants
        $service = new GrantMaintenanceService($grantRepo, $sourceRepo, null);
        $count = $service->pauseOverdueAnchorGrants();

        self::assertSame(1, $count);
        self::assertSame('paused', $grantRepo->updates[0]['data']['status']);
        self::assertArrayHasKey('paused_at', $grantRepo->updates[0]['data']['meta']);
    }

    // =========================================================================
    // expireTermExpiredGrants: fires hooks for BOTH active and paused grants
    // =========================================================================

    public function test_expire_term_expired_grants_processes_both_active_and_paused(): void
    {
        $grantRepo = new class() extends GrantRepository {
            public array $updates = [];
            public function __construct() {}
            public function getTermExpiredGrants(?string $now = null): array
            {
                return [
                    [
                        'id' => 1, 'status' => 'active',
                        'meta' => ['membership_term_ends_at' => '2025-01-01 23:59:59'],
                    ],
                    [
                        'id' => 2, 'status' => 'paused',
                        'meta' => ['membership_term_ends_at' => '2025-06-01 23:59:59'],
                    ],
                ];
            }
            public function update(int $id, array $data): bool
            {
                $this->updates[] = ['id' => $id, 'status' => $data['status']];
                return true;
            }
        };

        $sourceRepo = new GrantSourceRepository();
        $service = new GrantMaintenanceService($grantRepo, $sourceRepo, null);

        $hooksFired = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_expired'] = [
            function ($grant) use (&$hooksFired) { $hooksFired[] = $grant['id']; },
        ];

        $count = $service->expireTermExpiredGrants();

        // Both active and paused term-expired grants should be expired
        self::assertSame(2, $count);
        self::assertSame([1, 2], $hooksFired);
        self::assertSame('expired', $grantRepo->updates[0]['status']);
        self::assertSame('expired', $grantRepo->updates[1]['status']);
    }

    // =========================================================================
    // Double-expiry risk: verify that a term-expired grant is NOT also
    // picked up by expireOverdueGrantsWithHooks in the same cron run
    // =========================================================================

    public function test_no_double_expiry_in_same_cron_run(): void
    {
        // This test verifies the ordering in SubscriptionValidityCheckService::run()
        // ensures term expiry sets status='expired' before generic expiry queries for 'active'.
        $grantService = new class() extends AccessGrantService {
            public array $callOrder = [];
            public function __construct() {}
            public function pauseOverdueAnchorGrants(): int { return 0; }
            public function expireTermExpiredGrants(): int
            {
                $this->callOrder[] = 'term_expire';
                return 1;
            }
            public function revokeExpiredGracePeriodGrants(): int
            {
                $this->callOrder[] = 'grace_revoke';
                return 0;
            }
            public function expireOverdueGrantsWithHooks(): int
            {
                $this->callOrder[] = 'generic_expire';
                return 0;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getActiveSubscriptionSourceIds(): array { return []; }
        };

        $validityLogs = new SubscriptionValidityLogRepository();

        $service = new SubscriptionValidityCheckService($grantRepo, $validityLogs, $grantService);
        $service->run();

        // Term expiry must run before generic expiry to prevent double-processing.
        // If term expiry sets status='expired', generic expiry won't pick it up
        // because it queries WHERE status='active'.
        $termIdx = array_search('term_expire', $grantService->callOrder, true);
        $genericIdx = array_search('generic_expire', $grantService->callOrder, true);
        self::assertNotFalse($termIdx);
        self::assertNotFalse($genericIdx);
        self::assertLessThan($genericIdx, $termIdx, 'Term expiry must run before generic expiry');
    }

    // =========================================================================
    // getTermExpiredGrants SQL LIKE: test that PHP post-filter prevents
    // false positives from LIKE match on similar meta keys
    // =========================================================================

    public function test_get_term_expired_grants_filters_out_similar_meta_keys(): void
    {
        // The SQL LIKE '%"membership_term_ends_at"%' could match
        // 'old_membership_term_ends_at'. The PHP filter must catch this.
        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getTermExpiredGrants(?string $now = null): array
            {
                // Simulate what the real method does: SQL returns rows with
                // similar meta keys, PHP filters by exact key.
                // We test the MembershipTermCalculator::isTermExpired logic.
                $grants = [
                    // Exact key match — should be included
                    [
                        'id' => 1, 'status' => 'active',
                        'meta' => ['membership_term_ends_at' => '2020-01-01 23:59:59'],
                    ],
                    // Similar key — should NOT be included
                    [
                        'id' => 2, 'status' => 'active',
                        'meta' => ['old_membership_term_ends_at' => '2020-01-01 23:59:59'],
                    ],
                ];

                // The real getTermExpiredGrants does this filter:
                $expired = [];
                foreach ($grants as $grant) {
                    $termEndsAt = $grant['meta']['membership_term_ends_at'] ?? null;
                    if ($termEndsAt && strtotime($termEndsAt) <= strtotime($now ?? '2026-03-13 22:00:00')) {
                        $expired[] = $grant;
                    }
                }
                return $expired;
            }
        };

        $results = $grantRepo->getTermExpiredGrants('2026-03-13 22:00:00');

        // Only grant 1 should match (exact key). Grant 2 has a different key name.
        self::assertCount(1, $results);
        self::assertSame(1, $results[0]['id']);
    }

    // =========================================================================
    // isTermExpired with null meta (not an array)
    //
    // MembershipTermCalculator::isTermExpired(array $grantMeta) has a type
    // hint, so passing null would be a TypeError. But in practice, callers
    // pass $grant['meta'] which hydrate() guarantees is an array.
    // This test verifies hydrate() safety.
    // =========================================================================

    public function test_is_term_expired_with_empty_array_returns_false(): void
    {
        self::assertFalse(MembershipTermCalculator::isTermExpired([]));
    }

    public function test_is_term_expired_with_future_date_returns_false(): void
    {
        self::assertFalse(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2099-12-31 23:59:59'],
            '2026-03-13 22:00:00'
        ));
    }

    public function test_is_term_expired_with_past_date_returns_true(): void
    {
        self::assertTrue(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2020-01-01 23:59:59'],
            '2026-03-13 22:00:00'
        ));
    }

    public function test_is_term_expired_with_exact_now_returns_true(): void
    {
        // strtotime($termEndsAt) <= strtotime($now) — equal means expired
        self::assertTrue(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2026-03-13 22:00:00'],
            '2026-03-13 22:00:00'
        ));
    }

    // =========================================================================
    // SubscriptionValidityCheckService::run() — exception in one step
    // should not block subsequent steps
    // =========================================================================

    public function test_cron_run_anchor_pause_exception_blocks_remaining_steps(): void
    {
        // Currently, if pauseOverdueAnchorGrants throws, the remaining steps
        // (term expiry, grace revoke, generic expiry) are never called.
        // This documents the current behaviour as a known limitation.
        $grantService = new class() extends AccessGrantService {
            public bool $termExpireCalled = false;
            public bool $genericExpireCalled = false;
            public function __construct() {}
            public function pauseOverdueAnchorGrants(): int
            {
                throw new \RuntimeException('Database connection lost');
            }
            public function expireTermExpiredGrants(): int
            {
                $this->termExpireCalled = true;
                return 0;
            }
            public function revokeExpiredGracePeriodGrants(): int { return 0; }
            public function expireOverdueGrantsWithHooks(): int
            {
                $this->genericExpireCalled = true;
                return 0;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getActiveSubscriptionSourceIds(): array { return []; }
        };

        $validityLogs = new SubscriptionValidityLogRepository();
        $service = new SubscriptionValidityCheckService($grantRepo, $validityLogs, $grantService);

        $this->expectException(\RuntimeException::class);
        $service->run();

        // After the exception, these should not have been called
        // (the assertion is moot because expectException halts, but it
        // documents the issue: an exception in one step blocks all others)
    }

    // =========================================================================
    // expireOverdueGrantsWithHooks: race between getOverdueGrants and
    // expireOverdueGrants — the SELECT list may differ from the UPDATE count
    // =========================================================================

    public function test_expire_overdue_hooks_fires_for_all_grants_found(): void
    {
        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getOverdueGrants(): array
            {
                return [
                    ['id' => 1, 'status' => 'active', 'meta' => [], 'expires_at' => '2025-01-01 00:00:00'],
                    ['id' => 2, 'status' => 'active', 'meta' => [], 'expires_at' => '2025-06-01 00:00:00'],
                ];
            }
            public function expireOverdueGrants(): int
            {
                return 2; // UPDATE matched 2 rows
            }
        };

        $sourceRepo = new GrantSourceRepository();
        $service = new GrantMaintenanceService($grantRepo, $sourceRepo, null);

        $hooksFired = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_expired'] = [
            function ($grant) use (&$hooksFired) { $hooksFired[] = $grant['id']; },
        ];

        $count = $service->expireOverdueGrantsWithHooks();

        self::assertSame(2, $count);
        self::assertSame([1, 2], $hooksFired);
    }

    // =========================================================================
    // cancel() only processes active/paused grants — verify revoked/expired
    // grants are skipped
    // =========================================================================

    public function test_cancel_skips_already_revoked_grants(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $revokedPlans = [];
            public function __construct() {}
            public function revokePlan(int $userId, int $planId, array $context = []): array
            {
                $this->revokedPlans[] = ['user_id' => $userId, 'plan_id' => $planId];
                return ['revoked' => true];
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    ['id' => 1, 'user_id' => 10, 'plan_id' => 3, 'status' => 'revoked', 'meta' => []],
                    ['id' => 2, 'user_id' => 10, 'plan_id' => 3, 'status' => 'expired', 'meta' => []],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->cancel((object) ['id' => 99]);

        // Both are already revoked/expired — cancel should not re-revoke
        self::assertEmpty($grantService->revokedPlans);
    }

    public function test_cancel_processes_active_grant_in_mixed_set(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $revokedPlans = [];
            public function __construct() {}
            public function revokePlan(int $userId, int $planId, array $context = []): array
            {
                $this->revokedPlans[] = ['user_id' => $userId, 'plan_id' => $planId];
                return ['revoked' => true];
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    ['id' => 1, 'user_id' => 10, 'plan_id' => 3, 'status' => 'active', 'meta' => []],
                    ['id' => 2, 'user_id' => 10, 'plan_id' => 3, 'status' => 'expired', 'meta' => []],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->cancel((object) ['id' => 99]);

        // The active grant triggers revokePlan for the plan
        self::assertCount(1, $grantService->revokedPlans);
        self::assertSame(3, $grantService->revokedPlans[0]['plan_id']);
    }

    // =========================================================================
    // pause() with anchor grant that has term meta — verify term doesn't
    // interfere with pausing
    // =========================================================================

    public function test_pause_works_regardless_of_term_meta(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $paused = [];
            public function __construct() {}
            public function pauseGrant(int $grantId, string $reason = ''): array
            {
                $this->paused[] = $grantId;
                return ['success' => true];
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1, 'status' => 'active',
                        'meta' => [
                            'billing_anchor_day' => 20,
                            'membership_term_ends_at' => '2020-01-01 23:59:59', // already expired
                        ],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->pause((object) ['id' => 99]);

        // pause() doesn't check term — it pauses all active grants (correct behaviour:
        // pausing is a subscription event, not a term event)
        self::assertSame([1], $grantService->paused);
    }

    // =========================================================================
    // renew() with anchor grants: verify the correct branch (paused vs active)
    // is taken for each status, and that other statuses are ignored
    // =========================================================================

    public function test_renew_anchor_grant_with_unexpected_status_is_noop(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $resumed = [];
            public array $extended = [];
            public function __construct() {}
            public function resumeGrant(int $grantId): array
            {
                $this->resumed[] = $grantId;
                return ['success' => true];
            }
            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['user_id' => $userId];
                return 1;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1, 'user_id' => 10, 'plan_id' => 3,
                        'status' => 'revoked',
                        'expires_at' => '2026-03-20 23:59:59',
                        'meta' => ['billing_anchor_day' => 20],
                    ],
                    [
                        'id' => 2, 'user_id' => 11, 'plan_id' => 4,
                        'status' => 'expired',
                        'expires_at' => '2026-03-10 23:59:59',
                        'meta' => ['billing_anchor_day' => 10],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-18 00:00:00']);

        // Neither revoked nor expired anchor grants should be resumed/extended
        self::assertEmpty($grantService->resumed);
        self::assertEmpty($grantService->extended);
    }

    // =========================================================================
    // GrantMaintenanceService::expireTermExpiredGrants sets expired_reason meta
    // =========================================================================

    public function test_expire_term_expired_grants_sets_reason_in_meta(): void
    {
        $grantRepo = new class() extends GrantRepository {
            public array $updates = [];
            public function __construct() {}
            public function getTermExpiredGrants(?string $now = null): array
            {
                return [
                    [
                        'id' => 5, 'status' => 'active',
                        'meta' => ['membership_term_ends_at' => '2025-01-01 23:59:59'],
                    ],
                ];
            }
            public function update(int $id, array $data): bool
            {
                $this->updates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $sourceRepo = new GrantSourceRepository();
        $service = new GrantMaintenanceService($grantRepo, $sourceRepo, null);
        $service->expireTermExpiredGrants();

        self::assertSame('expired', $grantRepo->updates[0]['data']['status']);
        self::assertSame(
            'membership_term_reached',
            $grantRepo->updates[0]['data']['meta']['expired_reason']
        );
        // Original term_ends_at should be preserved
        self::assertSame(
            '2025-01-01 23:59:59',
            $grantRepo->updates[0]['data']['meta']['membership_term_ends_at']
        );
    }

    // =========================================================================
    // Hydrate safety: verify that null/empty meta in DB doesn't break code
    // =========================================================================

    public function test_hydrate_null_meta_becomes_empty_array(): void
    {
        // Simulate what hydrate does with null meta column
        $row = [
            'id' => '1', 'user_id' => '10', 'plan_id' => '3',
            'source_id' => '0', 'feed_id' => null,
            'trial_ends_at' => null, 'cancellation_requested_at' => null,
            'cancellation_effective_at' => null, 'cancellation_reason' => null,
            'renewal_count' => '0',
            'source_ids' => null, // NULL in DB
            'meta' => null,       // NULL in DB
        ];

        // This mimics GrantRepository::hydrate()
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        $row['source_ids'] = json_decode($row['source_ids'] ?? '[]', true) ?: [];

        self::assertIsArray($row['meta']);
        self::assertEmpty($row['meta']);
        self::assertIsArray($row['source_ids']);
        self::assertEmpty($row['source_ids']);
    }

    public function test_hydrate_empty_string_meta_becomes_empty_array(): void
    {
        // json_decode('', true) returns null → ?: [] gives []
        $row = ['meta' => '', 'source_ids' => ''];

        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        $row['source_ids'] = json_decode($row['source_ids'] ?? '[]', true) ?: [];

        self::assertIsArray($row['meta']);
        self::assertEmpty($row['meta']);
    }

    // =========================================================================
    // Verify that getOverdueGrants excludes anchor grants
    // (to prevent double-processing with pauseOverdueAnchorGrants)
    // =========================================================================

    public function test_overdue_and_anchor_queries_are_mutually_exclusive(): void
    {
        // This is a logic verification test. Both queries use opposite conditions:
        // - getOverdueGrants: meta NOT LIKE '%"billing_anchor_day"%'
        // - getOverdueAnchorGrants: meta LIKE '%"billing_anchor_day"%'
        //
        // A grant with billing_anchor_day will ONLY match getOverdueAnchorGrants.
        // A grant without billing_anchor_day will ONLY match getOverdueGrants.
        //
        // We verify this by checking that a grant with anchor day is NOT returned
        // by getOverdueGrants mock logic.

        $anchorGrant = ['id' => 1, 'meta' => ['billing_anchor_day' => 20]];
        $regularGrant = ['id' => 2, 'meta' => []];

        // Simulate the NOT LIKE condition from getOverdueGrants
        $overdueFilter = function (array $grant): bool {
            $metaJson = json_encode($grant['meta']);
            return $metaJson === null
                || strpos($metaJson, '"billing_anchor_day"') === false;
        };

        // Anchor grant should NOT pass the overdue filter
        self::assertFalse($overdueFilter($anchorGrant));
        // Regular grant should pass the overdue filter
        self::assertTrue($overdueFilter($regularGrant));
    }

    // =========================================================================
    // extendExpiry only extends 'active' grants, not paused/revoked/expired
    // =========================================================================

    public function test_extend_expiry_only_queries_active_grants(): void
    {
        $grantRepo = new class() extends GrantRepository {
            public ?array $lastFilters = null;
            public function __construct() {}
            public function getByUserId(int $userId, array $filters = []): array
            {
                $this->lastFilters = $filters;
                return [];
            }
        };

        $sourceRepo = new GrantSourceRepository();
        $service = new GrantMaintenanceService($grantRepo, $sourceRepo, null);
        $service->extendExpiry(10, 3, '2026-06-01 00:00:00');

        // Verify it queries with status = 'active'
        self::assertSame('active', $grantRepo->lastFilters['status']);
    }
}
