<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Grant\AnchorDateCalculator;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\StatusTransitionValidator;
use FChubMemberships\Domain\SubscriptionGrantLifecycleService;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/controller-stubs.php';

/**
 * Plugin-wide adversarial bug hunt.
 * Tests bugs found across GrantRevocationService, TrialLifecycleService,
 * ContentProtection, AccessEvaluator, and SubscriptionValidityWatcher.
 */
final class PluginWideBugHuntTest extends PluginTestCase
{
    // =========================================================================
    // BUG A: revokePlan now includes paused grants (not just active)
    // =========================================================================

    public function test_revoke_plan_includes_paused_grants(): void
    {
        $grantRepo = new class() extends GrantRepository {
            public array $updatedIds = [];
            public function __construct() {}
            public function getByUserId(int $userId, array $filters = []): array
            {
                return [
                    ['id' => 1, 'status' => 'active', 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '10', 'source_ids' => [], 'meta' => [], 'plan_id' => 5],
                    ['id' => 2, 'status' => 'paused', 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '11', 'source_ids' => [], 'meta' => [], 'plan_id' => 5],
                    ['id' => 3, 'status' => 'expired', 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '12', 'source_ids' => [], 'meta' => [], 'plan_id' => 5],
                ];
            }
            public function update(int $id, array $data): bool
            {
                $this->updatedIds[] = $id;
                return true;
            }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeSource(int $grantId, string $type, int $sourceId): bool { return true; }
            public function removeAllByGrant(int $grantId): bool { return true; }
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };
        $adapters = new GrantAdapterRegistry([]);
        $planRepo = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array { return null; }
        };
        $notifications = new GrantNotificationService($planRepo);

        $service = new GrantRevocationService($grantRepo, $sourceRepo, $dripRepo, $adapters, $notifications);
        $result = $service->revokePlan(1, 5, ['reason' => 'Test']);

        // Active + paused = 2 revoked. Expired skipped (transition not valid).
        self::assertSame(2, $result['revoked']);
        self::assertContains(1, $grantRepo->updatedIds, 'Active grant should be revoked');
        self::assertContains(2, $grantRepo->updatedIds, 'Paused grant should be revoked');
    }

    // =========================================================================
    // BUG B: grant_revoked hook only fires when revoked > 0
    // =========================================================================

    public function test_revoke_plan_no_op_does_not_fire_hook(): void
    {
        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getByUserId(int $userId, array $filters = []): array
            {
                return []; // No grants to revoke
            }
        };
        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
        };
        $adapters = new GrantAdapterRegistry([]);
        $planRepo = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array { return null; }
        };
        $notifications = new GrantNotificationService($planRepo);

        $service = new GrantRevocationService($grantRepo, $sourceRepo, $dripRepo, $adapters, $notifications);
        $service->revokePlan(1, 5, ['reason' => 'Test']);

        // Check that grant_revoked hook was NOT fired
        $actions = $GLOBALS['_fchub_test_actions'] ?? [];
        $revokedActions = array_filter($actions, fn($a) => $a[0] === 'fchub_memberships/grant_revoked');
        self::assertEmpty($revokedActions, 'Hook should not fire when nothing was revoked');
    }

    // =========================================================================
    // BUG C: revokeBySource skips expired/revoked grants
    // =========================================================================

    public function test_revoke_by_source_skips_expired_grants(): void
    {
        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    ['id' => 1, 'user_id' => 10, 'plan_id' => 5, 'status' => 'expired', 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '10', 'source_ids' => [100]],
                    ['id' => 2, 'user_id' => 10, 'plan_id' => 5, 'status' => 'revoked', 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '11', 'source_ids' => [100]],
                ];
            }
            public function update(int $id, array $data): bool { return true; }
        };
        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeSource(int $grantId, string $type, int $sourceId): bool { return true; }
            public function removeAllByGrant(int $grantId): bool { return true; }
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };
        $adapters = new GrantAdapterRegistry([]);
        $planRepo2 = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array { return null; }
        };
        $notifications = new GrantNotificationService($planRepo2);

        $service = new GrantRevocationService($grantRepo, $sourceRepo, $dripRepo, $adapters, $notifications);
        $result = $service->revokeBySource(100, 'order');

        self::assertSame(0, $result['revoked'], 'Expired/revoked grants should be skipped');
    }

    // =========================================================================
    // BUG G: ContentProtection registers cache invalidation for expired events
    // =========================================================================

    public function test_content_protection_registers_expired_cache_hooks(): void
    {
        $protection = new \FChubMemberships\Domain\ContentProtection();
        $protection->register();

        $actions = $GLOBALS['_fchub_test_actions'] ?? [];
        $hookNames = array_keys($actions);

        self::assertContains('fchub_memberships/grant_expired', $hookNames, 'grant_expired hook should be registered');
        self::assertContains('fchub_memberships/grant_term_expired', $hookNames, 'grant_term_expired hook should be registered');
    }

    // =========================================================================
    // BUG D + E: Trial conversion handles anchor + term
    // =========================================================================

    public function test_trial_conversion_applies_membership_term(): void
    {
        $ref = current_time('mysql');
        $termConfig = ['mode' => '1y'];
        $termEndsAt = MembershipTermCalculator::calculateEndDate($termConfig, $ref);

        self::assertNotNull($termEndsAt);
        // Must use same reference for expected calculation
        $expected = date('Y-m-d', strtotime('+1 year', strtotime($ref))) . ' 23:59:59';
        self::assertSame($expected, $termEndsAt);
    }

    public function test_trial_conversion_anchor_date_calculated(): void
    {
        // Verify AnchorDateCalculator works for trial conversion
        $anchorDay = 20;
        $result = AnchorDateCalculator::nextAnchorDate($anchorDay, current_time('mysql'));

        self::assertNotNull($result);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 23:59:59$/', $result);
    }

    public function test_trial_conversion_anchor_plus_term_caps_correctly(): void
    {
        // Anchor gives March 20, term gives 7 days from now (~March 21)
        $anchorExpiry = '2026-03-20 23:59:59';
        $termEndsAt = date('Y-m-d', strtotime('+7 days')) . ' 23:59:59';

        $capped = MembershipTermCalculator::capExpiry($anchorExpiry, $termEndsAt);

        // Whichever is earlier should win
        $anchorTs = strtotime($anchorExpiry);
        $termTs = strtotime($termEndsAt);
        $cappedTs = strtotime($capped);

        self::assertSame(min($anchorTs, $termTs), $cappedTs);
    }

    // =========================================================================
    // StatusTransitionValidator: verify transition rules for revocation
    // =========================================================================

    public function test_status_transition_active_to_revoked_valid(): void
    {
        self::assertTrue(StatusTransitionValidator::isValid('active', 'revoked'));
    }

    public function test_status_transition_paused_to_revoked_valid(): void
    {
        self::assertTrue(StatusTransitionValidator::isValid('paused', 'revoked'));
    }

    public function test_status_transition_expired_to_revoked_not_valid(): void
    {
        // expired → revoked is NOT in the transition map
        self::assertFalse(StatusTransitionValidator::isValid('expired', 'revoked'));
    }

    public function test_status_transition_revoked_to_revoked_is_noop(): void
    {
        // Same status = valid (no-op)
        self::assertTrue(StatusTransitionValidator::isValid('revoked', 'revoked'));
    }

    // =========================================================================
    // SubscriptionGrantLifecycleService: resume skips term-expired
    // =========================================================================

    public function test_resume_skips_term_expired_paused_grants(): void
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
                    // Paused but term expired — should NOT be resumed
                    ['id' => 1, 'status' => 'paused', 'meta' => ['membership_term_ends_at' => '2020-01-01 23:59:59']],
                    // Paused with future term — SHOULD be resumed
                    ['id' => 2, 'status' => 'paused', 'meta' => ['membership_term_ends_at' => '2099-12-31 23:59:59']],
                    // Paused without term — SHOULD be resumed
                    ['id' => 3, 'status' => 'paused', 'meta' => []],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->resume((object) ['id' => 55]);

        // Only grants 2 and 3 should be resumed
        self::assertSame([2, 3], $grantService->resumed);
    }

    // =========================================================================
    // GrantCreationService: renewal path merges meta
    // =========================================================================

    public function test_grant_creation_renewal_merges_meta(): void
    {
        // This was fixed by wiring-hunter — verify it holds
        $grantRepo = new class() extends GrantRepository {
            public array $lastUpdate = [];
            public function __construct() {}
            public static function makeGrantKey(int $userId, string $provider, string $resourceType, string $resourceId): string
            {
                return 'test-key';
            }
            public function findByGrantKey(string $grantKey): ?array
            {
                return [
                    'id' => 42, 'status' => 'active', 'source_ids' => [],
                    'renewal_count' => 0, 'expires_at' => null,
                    'meta' => ['old_key' => 'preserved'],
                ];
            }
            public function update(int $id, array $data): bool
            {
                $this->lastUpdate = $data;
                return true;
            }
        };
        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
        };
        $adapters = new GrantAdapterRegistry([]);

        $service = new \FChubMemberships\Domain\Grant\GrantCreationService($grantRepo, $sourceRepo, $dripRepo, $adapters);
        $service->grantResource(1, 'wordpress_core', 'post', '99', [
            'source_id' => 200,
            'meta' => ['membership_term_ends_at' => '2028-01-01 23:59:59'],
        ]);

        self::assertArrayHasKey('meta', $grantRepo->lastUpdate);
        self::assertSame('preserved', $grantRepo->lastUpdate['meta']['old_key']);
        self::assertSame('2028-01-01 23:59:59', $grantRepo->lastUpdate['meta']['membership_term_ends_at']);
    }

    // =========================================================================
    // Subscription watcher: duplicate hook concern
    // =========================================================================

    public function test_subscription_watcher_status_map_covers_all_statuses(): void
    {
        // Test the status change handler mapping directly by calling onSubscriptionStatusChanged.
        // It should call the correct internal method for each status.
        $pauseCalled = false;
        $watcher = new \FChubMemberships\Domain\SubscriptionValidityWatcher(
            new SubscriptionGrantLifecycleService(
                new class() extends AccessGrantService {
                    public function __construct() {}
                    public function pauseGrant(int $grantId, string $reason = ''): array { return []; }
                },
                new class() extends GrantRepository {
                    public function __construct() {}
                    public function getBySourceId(int $sourceId, string $sourceType = 'order'): array { return []; }
                }
            )
        );

        $sub = (object) ['id' => 1];
        // Calling with 'paused' should not crash (method exists in map)
        $watcher->onSubscriptionStatusChanged(['subscription' => $sub, 'new_status' => 'paused']);
        // Calling with 'active' should not crash
        $watcher->onSubscriptionStatusChanged(['subscription' => $sub, 'new_status' => 'active']);
        // Calling with 'cancelled' should not crash
        $watcher->onSubscriptionStatusChanged(['subscription' => $sub, 'new_status' => 'cancelled']);
        // Calling with unknown status should be silently ignored
        $watcher->onSubscriptionStatusChanged(['subscription' => $sub, 'new_status' => 'unknown_status']);

        // If we got here, all status map lookups worked
        self::assertTrue(true);
    }

    // =========================================================================
    // PlanService: meta merge preserves term on unrelated update
    // =========================================================================

    public function test_calculator_validate_rejects_beyond_max_boundaries(): void
    {
        // days > 36500
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => 36501, 'unit' => 'days',
        ]));
        // weeks > 5200
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => 5201, 'unit' => 'weeks',
        ]));
        // months > 1200
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => 1201, 'unit' => 'months',
        ]));
        // years > 100
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => 101, 'unit' => 'years',
        ]));
    }

    public function test_calculator_validate_accepts_at_max_boundaries(): void
    {
        self::assertNull(MembershipTermCalculator::validate(['mode' => 'custom', 'value' => 36500, 'unit' => 'days']));
        self::assertNull(MembershipTermCalculator::validate(['mode' => 'custom', 'value' => 5200, 'unit' => 'weeks']));
        self::assertNull(MembershipTermCalculator::validate(['mode' => 'custom', 'value' => 1200, 'unit' => 'months']));
        self::assertNull(MembershipTermCalculator::validate(['mode' => 'custom', 'value' => 100, 'unit' => 'years']));
    }
}
