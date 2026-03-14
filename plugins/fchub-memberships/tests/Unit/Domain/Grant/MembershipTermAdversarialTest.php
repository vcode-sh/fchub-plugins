<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Domain\SubscriptionGrantLifecycleService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * Adversarial / edge-case tests for the Membership Term feature.
 *
 * Covers: calculator edge cases, context service wiring, lifecycle service
 * wiring, cron ordering, feed-vs-plan priority, and cross-cutting scenarios
 * that the individual happy-path tests don't reach.
 */
final class MembershipTermAdversarialTest extends PluginTestCase
{
    // =========================================================================
    // CALCULATOR: malformed / adversarial input
    // =========================================================================

    public function test_calculate_custom_with_negative_value_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => -5, 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_calculate_custom_with_string_value_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 'abc', 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        // (int) 'abc' = 0 which is < 1
        self::assertNull($result);
    }

    public function test_calculate_custom_float_value_truncates_to_int(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 2.9, 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        // (int) 2.9 = 2
        self::assertSame('2026-05-14 23:59:59', $result);
    }

    public function test_calculate_custom_huge_value_still_returns_date(): void
    {
        // 1000 years — strtotime should handle it (returns far-future date)
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 1000, 'unit' => 'years'],
            '2026-03-14 10:00:00'
        );
        // strtotime("+1000 years") should work on 64-bit PHP
        self::assertNotNull($result);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 23:59:59$/', $result);
    }

    public function test_calculate_date_with_datetime_string_normalises_to_date(): void
    {
        // Date mode with full datetime — should still return Y-m-d 23:59:59
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '2027-06-15 14:30:00'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2027-06-15 23:59:59', $result);
    }

    public function test_calculate_date_with_relative_string_is_parsed(): void
    {
        // strtotime understands relative strings — this is valid but unexpected
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '+1 year'],
            '2026-03-14 10:00:00'
        );
        // This will resolve relative to "now", not referenceDate
        self::assertNotNull($result);
    }

    public function test_calculate_preset_reference_date_at_midnight(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '2026-01-01 00:00:00'
        );
        self::assertSame('2027-01-01 23:59:59', $result);
    }

    public function test_calculate_preset_reference_date_at_end_of_day(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '2026-01-01 23:59:59'
        );
        self::assertSame('2027-01-01 23:59:59', $result);
    }

    public function test_calculate_empty_string_reference_date(): void
    {
        // strtotime('') returns false on most PHP versions
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            ''
        );
        // strtotime('', strtotime('')) — strtotime('') is false,
        // strtotime('+1 year', false) uses epoch
        // This exercises defensive edge but shouldn't crash
        self::assertIsString($result);
    }

    // =========================================================================
    // CALCULATOR: validate() adversarial input
    // =========================================================================

    public function test_validate_custom_with_negative_value_fails(): void
    {
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => -1, 'unit' => 'months',
        ]));
    }

    public function test_validate_custom_with_string_value_fails(): void
    {
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => 'abc', 'unit' => 'months',
        ]));
    }

    public function test_validate_date_with_empty_string_fails(): void
    {
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'date', 'date' => '',
        ]));
    }

    public function test_validate_date_with_nonsense_string_fails(): void
    {
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'date', 'date' => 'not-a-date',
        ]));
    }

    public function test_validate_custom_with_empty_string_unit_fails(): void
    {
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => 6, 'unit' => '',
        ]));
    }

    // =========================================================================
    // CALCULATOR: isTermExpired edge cases
    // =========================================================================

    public function test_is_term_expired_with_empty_string_meta_value(): void
    {
        // '' is falsy, should return false
        self::assertFalse(MembershipTermCalculator::isTermExpired([
            'membership_term_ends_at' => '',
        ]));
    }

    public function test_is_term_expired_with_zero_string(): void
    {
        // '0' is falsy in PHP
        self::assertFalse(MembershipTermCalculator::isTermExpired([
            'membership_term_ends_at' => '0',
        ]));
    }

    public function test_is_term_expired_with_malformed_date(): void
    {
        // strtotime('garbage') returns false
        // false <= strtotime(valid) is true, so this would return true
        $result = MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => 'garbage-date'],
            '2026-03-14 10:00:00'
        );
        // strtotime('garbage-date') returns false (0 on some PHP versions)
        // 0 <= strtotime('2026-03-14 10:00:00') is true
        self::assertTrue($result);
    }

    public function test_is_term_expired_with_other_meta_keys_present(): void
    {
        self::assertFalse(MembershipTermCalculator::isTermExpired([
            'billing_anchor_day' => 20,
            'some_other_key' => 'value',
        ]));
    }

    // =========================================================================
    // CALCULATOR: capExpiry edge cases
    // =========================================================================

    public function test_cap_expiry_both_identical_timestamps(): void
    {
        $date = '2027-03-14 23:59:59';
        self::assertSame($date, MembershipTermCalculator::capExpiry($date, $date));
    }

    public function test_cap_expiry_proposed_one_second_before_term(): void
    {
        self::assertSame(
            '2027-03-14 23:59:58',
            MembershipTermCalculator::capExpiry('2027-03-14 23:59:58', '2027-03-14 23:59:59')
        );
    }

    public function test_cap_expiry_proposed_one_second_after_term(): void
    {
        self::assertSame(
            '2027-03-14 23:59:59',
            MembershipTermCalculator::capExpiry('2027-03-15 00:00:00', '2027-03-14 23:59:59')
        );
    }

    // =========================================================================
    // CONTEXT SERVICE: wiring & edge cases
    // =========================================================================

    public function test_context_subscription_mirror_with_term_no_expires_at(): void
    {
        // subscription_mirror doesn't set expires_at in the duration block.
        // The term block must handle missing expires_at gracefully.
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'subscription_mirror',
                    'meta' => ['membership_term' => ['mode' => '1y']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        // No expires_at in context — subscription_mirror doesn't set one
        $result = $service->resolve(3, 11, []);

        // Term should set expires_at for us
        self::assertNotNull($result['context']['expires_at']);
        self::assertArrayHasKey('membership_term_ends_at', $result['context']['meta']);
    }

    public function test_context_subscription_mirror_with_preexisting_expires_at(): void
    {
        // Integration pre-sets expires_at from next billing date.
        // Term should cap it, not replace it.
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'subscription_mirror',
                    'meta' => ['membership_term' => ['mode' => 'custom', 'value' => 30, 'unit' => 'days']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        // Integration already set expires_at from subscription's next billing
        $result = $service->resolve(3, 11, ['expires_at' => '2099-01-01 00:00:00']);

        // Should be capped at ~30 days from now, NOT 2099
        self::assertNotSame('2099-01-01 00:00:00', $result['context']['expires_at']);
        self::assertSame(
            $result['context']['meta']['membership_term_ends_at'],
            $result['context']['expires_at']
        );
    }

    public function test_context_fixed_anchor_with_term_merges_both_meta_keys(): void
    {
        // fixed_anchor injects billing_anchor_day into meta.
        // Term should merge membership_term_ends_at alongside it.
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'fixed_anchor',
                    'meta' => [
                        'billing_anchor_day' => 20,
                        'membership_term' => ['mode' => '1y'],
                    ],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        // Both meta keys must coexist
        self::assertArrayHasKey('billing_anchor_day', $result['context']['meta']);
        self::assertArrayHasKey('membership_term_ends_at', $result['context']['meta']);
        self::assertSame(20, $result['context']['meta']['billing_anchor_day']);
    }

    public function test_context_feed_level_term_takes_precedence_over_plan_term(): void
    {
        // If the feed already injected membership_term_ends_at, the plan-level
        // term in resolve() must NOT overwrite it.
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => '3y']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        // Feed-level override already set a 6-month term
        $feedTermEnd = '2026-09-14 23:59:59';
        $result = $service->resolve(3, 11, [
            'expires_at' => $feedTermEnd,
            'meta' => ['membership_term_ends_at' => $feedTermEnd],
        ]);

        // Feed's 6-month term must be preserved, not overwritten by plan's 3y
        self::assertSame($feedTermEnd, $result['context']['meta']['membership_term_ends_at']);
        self::assertSame($feedTermEnd, $result['context']['expires_at']);
    }

    public function test_context_plan_not_found_skips_term(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return null;
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 999, []);

        self::assertNull($result['plan']);
        self::assertArrayNotHasKey('meta', $result['context']);
    }

    public function test_context_plan_without_meta_key_at_all(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    // No 'meta' key at all
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        // Should not crash — no term to inject
        self::assertNull($result['context']['expires_at']);
    }

    public function test_context_term_with_invalid_mode_does_nothing(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => 'infinite_power']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        // calculateEndDate returns null for unknown mode, so no term applied
        self::assertNull($result['context']['expires_at']);
    }

    public function test_context_fixed_days_shorter_than_term_keeps_fixed_days(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'fixed_days',
                    'duration_days' => 7,
                    'meta' => ['membership_term' => ['mode' => '3y']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        // 7 days << 3 years, so fixed_days should win
        $sevenDays = date('Y-m-d H:i:s', strtotime('+7 days'));
        self::assertSame($sevenDays, $result['context']['expires_at']);
        // But term_ends_at should still be set in meta
        self::assertArrayHasKey('membership_term_ends_at', $result['context']['meta']);
    }

    public function test_context_trial_plus_term_both_applied(): void
    {
        // Trial detection and term injection should both run
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 7,
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => '1y']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
            public function getByUserId(int $userId, array $filters = []): array
            {
                return []; // No existing grants — eligible for trial
            }
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        self::assertTrue($result['context']['is_trial']);
        self::assertArrayHasKey('trial_ends_at', $result['context']);
        // Term should also be applied
        self::assertArrayHasKey('membership_term_ends_at', $result['context']['meta']);
        self::assertNotNull($result['context']['expires_at']);
    }

    // =========================================================================
    // LIFECYCLE SERVICE: renewal with term edge cases
    // =========================================================================

    public function test_renew_anchor_paused_with_term_caps_correctly(): void
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
                $this->extended[] = ['expires_at' => $newExpiresAt];
                return 1;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 7, 'user_id' => 10, 'plan_id' => 3,
                        'status' => 'paused',
                        'expires_at' => '2027-02-10 23:59:59',
                        'meta' => [
                            'billing_anchor_day' => 10,
                            'membership_term_ends_at' => '2027-03-01 23:59:59',
                        ],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2027-03-13 00:00:00']);

        // Resumed the paused grant
        self::assertSame([7], $grantService->resumed);
        // nextAnchorDate(10, now) would give March 10 or April 10 depending on "now"
        // But term ends March 1, so capExpiry should return March 1
        self::assertCount(1, $grantService->extended);
        $expiry = $grantService->extended[0]['expires_at'];
        // The expiry must be at or before term end
        self::assertLessThanOrEqual(
            strtotime('2027-03-01 23:59:59'),
            strtotime($expiry)
        );
    }

    public function test_renew_mixed_grants_term_only_affects_those_with_term(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $extended = [];
            public function __construct() {}
            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = [
                    'user_id' => $userId,
                    'plan_id' => $planId,
                    'expires_at' => $newExpiresAt,
                ];
                return 1;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    // Grant WITH term — should be capped
                    [
                        'user_id' => 10, 'plan_id' => 3, 'status' => 'active',
                        'meta' => ['membership_term_ends_at' => '2026-04-01 23:59:59'],
                    ],
                    // Grant WITHOUT term — should use raw next_billing
                    [
                        'user_id' => 11, 'plan_id' => 4, 'status' => 'active',
                        'meta' => [],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-06-01 00:00:00']);

        self::assertCount(2, $grantService->extended);
        // Grant with term: capped at April 1
        self::assertSame('2026-04-01 23:59:59', $grantService->extended[0]['expires_at']);
        // Grant without term: raw billing date
        self::assertSame('2026-06-01 00:00:00', $grantService->extended[1]['expires_at']);
    }

    public function test_renew_term_expired_anchor_grant_skipped_entirely(): void
    {
        // Even anchor paused grants should be skipped if term expired
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
                $this->extended[] = ['expires_at' => $newExpiresAt];
                return 1;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 7, 'user_id' => 10, 'plan_id' => 3,
                        'status' => 'paused',
                        'expires_at' => '2020-01-10 23:59:59',
                        'meta' => [
                            'billing_anchor_day' => 10,
                            'membership_term_ends_at' => '2020-06-01 23:59:59',
                        ],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-15 00:00:00']);

        // Term expired years ago — should not resume or extend
        self::assertEmpty($grantService->resumed);
        self::assertEmpty($grantService->extended);
    }

    public function test_renew_term_shorter_than_one_billing_cycle(): void
    {
        // Term ends in 5 days but billing is monthly
        $grantService = new class() extends AccessGrantService {
            public array $extended = [];
            public function __construct() {}
            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['expires_at' => $newExpiresAt];
                return 1;
            }
        };

        // Term ends very soon
        $termEnd = date('Y-m-d', strtotime('+5 days')) . ' 23:59:59';
        $grantRepo = new class($termEnd) extends GrantRepository {
            private string $termEnd;
            public function __construct(string $termEnd) { $this->termEnd = $termEnd; }
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'user_id' => 10, 'plan_id' => 3, 'status' => 'active',
                        'meta' => ['membership_term_ends_at' => $this->termEnd],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $nextBilling = date('Y-m-d', strtotime('+30 days')) . ' 00:00:00';
        $service->renew((object) ['id' => 55, 'next_billing_date' => $nextBilling]);

        // Expiry should be capped at term end, not next billing
        self::assertSame($termEnd, $grantService->extended[0]['expires_at']);
    }

    public function test_renew_with_no_grants_is_noop(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $extended = [];
            public function __construct() {}
            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['expires_at' => $newExpiresAt];
                return 1;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-05-01 00:00:00']);

        self::assertEmpty($grantService->extended);
    }

    // =========================================================================
    // CRON ORDERING: maintenance runs regardless of subscription state
    // =========================================================================

    public function test_cron_run_expires_terms_even_without_subscriptions(): void
    {
        // This was BUG 1: if no active subscription IDs, the old code returned
        // early and never called expireTermExpiredGrants().
        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getActiveSubscriptionSourceIds(): array
            {
                return []; // No active subscriptions
            }
        };
        $validityLogs = new \FChubMemberships\Storage\SubscriptionValidityLogRepository();
        $grantService = new class() extends AccessGrantService {
            public bool $termExpireCalled = false;
            public function __construct() {}
            public function pauseOverdueAnchorGrants(): int { return 0; }
            public function expireTermExpiredGrants(): int
            {
                $this->termExpireCalled = true;
                return 2;
            }
            public function revokeExpiredGracePeriodGrants(): int { return 0; }
            public function expireOverdueGrantsWithHooks(): int { return 0; }
        };

        $service = new \FChubMemberships\Domain\SubscriptionValidityCheckService(
            $grantRepo,
            $validityLogs,
            $grantService
        );
        $service->run();

        self::assertTrue($grantService->termExpireCalled, 'expireTermExpiredGrants must be called even with no subscriptions');
    }

    public function test_cron_run_expires_terms_before_generic_expiry(): void
    {
        // Verify the call order: anchor pause → term expire → grace revoke → generic expire
        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getActiveSubscriptionSourceIds(): array
            {
                return [];
            }
        };
        $validityLogs = new \FChubMemberships\Storage\SubscriptionValidityLogRepository();
        $grantService = new class() extends AccessGrantService {
            public array $callOrder = [];
            public function __construct() {}
            public function pauseOverdueAnchorGrants(): int
            {
                $this->callOrder[] = 'anchor_pause';
                return 0;
            }
            public function expireTermExpiredGrants(): int
            {
                $this->callOrder[] = 'term_expire';
                return 0;
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

        $service = new \FChubMemberships\Domain\SubscriptionValidityCheckService(
            $grantRepo,
            $validityLogs,
            $grantService
        );
        $service->run();

        self::assertSame(
            ['anchor_pause', 'term_expire', 'grace_revoke', 'generic_expire'],
            $grantService->callOrder
        );
    }

    // =========================================================================
    // CROSS-CUTTING: anchor + term interaction scenarios
    // =========================================================================

    public function test_context_anchor_plan_term_caps_first_anchor_date(): void
    {
        // Anchor day 20, plan has custom term of 10 days.
        // If today is March 14, next anchor is March 20 (6 days).
        // Term end is March 24 (10 days from now).
        // March 20 < March 24, so anchor wins.
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'fixed_anchor',
                    'meta' => [
                        'billing_anchor_day' => 20,
                        'membership_term' => ['mode' => 'custom', 'value' => 10, 'unit' => 'days'],
                    ],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        // Both should be set
        self::assertSame(20, $result['context']['meta']['billing_anchor_day']);
        self::assertArrayHasKey('membership_term_ends_at', $result['context']['meta']);
        // expires_at should be the earlier of anchor and term
        $expiresAt = strtotime($result['context']['expires_at']);
        $termEndsAt = strtotime($result['context']['meta']['membership_term_ends_at']);
        self::assertLessThanOrEqual($termEndsAt, $expiresAt);
    }

    // =========================================================================
    // EDGE: Plan with term but no rules
    // =========================================================================

    public function test_context_empty_context_array(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => '2y']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        // Empty context should get expires_at from term
        self::assertNotNull($result['context']['expires_at']);
        self::assertSame(
            $result['context']['meta']['membership_term_ends_at'],
            $result['context']['expires_at']
        );
    }

    // =========================================================================
    // EDGE: Renewal with both anchor and term expired simultaneously
    // =========================================================================

    public function test_renew_anchor_active_term_ends_before_next_anchor(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $extended = [];
            public function __construct() {}
            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['expires_at' => $newExpiresAt];
                return 1;
            }
        };

        // Active anchor grant. Current expiry March 20. Term ends March 25.
        // nextAnchorAfter(20, March 20) = April 20. Term caps at March 25.
        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1, 'user_id' => 10, 'plan_id' => 3,
                        'status' => 'active',
                        'expires_at' => '2026-03-20 23:59:59',
                        'meta' => [
                            'billing_anchor_day' => 20,
                            'membership_term_ends_at' => '2026-03-25 23:59:59',
                        ],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-18 00:00:00']);

        // April 20 > March 25, so capped at March 25
        self::assertSame('2026-03-25 23:59:59', $grantService->extended[0]['expires_at']);
    }
}
