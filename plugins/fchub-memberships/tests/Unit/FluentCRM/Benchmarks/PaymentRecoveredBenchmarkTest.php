<?php

namespace FChubMemberships\Tests\Unit\FluentCRM\Benchmarks;

use PHPUnit\Framework\TestCase;

/**
 * Tests for PaymentRecoveredBenchmark.
 *
 * These tests simulate the benchmark's goal state evaluation logic using
 * in-memory mocks. The benchmark listens on grant_renewed and checks whether
 * the linked subscription is active (recovered from failure).
 */
class PaymentRecoveredBenchmarkTest extends TestCase
{
    /** @var array In-memory grants store */
    private array $grants = [];

    /** @var array In-memory subscriptions store (id => status) */
    private array $subscriptions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grants = [];
        $this->subscriptions = [];
        $GLOBALS['wp_actions_fired'] = [];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createGrant(int $id, int $userId, int $planId, int $sourceId, string $sourceType = 'subscription', string $status = 'active'): array
    {
        $grant = [
            'id'          => $id,
            'user_id'     => $userId,
            'plan_id'     => $planId,
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'status'      => $status,
            'source_ids'  => [$sourceId],
            'meta'        => [],
            'renewal_count' => 1,
        ];
        $this->grants[$id] = $grant;
        return $grant;
    }

    private function createSubscription(int $id, string $status): void
    {
        $this->subscriptions[$id] = $status;
    }

    private function getSubscriptionStatus(int $id): ?string
    {
        return $this->subscriptions[$id] ?? null;
    }

    /**
     * Simulate isSubscriptionActive() logic from PaymentRecoveredBenchmark.
     */
    private function isSubscriptionActive(array $grant): bool
    {
        if ($grant['source_type'] !== 'subscription' || empty($grant['source_id'])) {
            return true; // Non-subscription grants are considered recovered
        }

        $status = $this->getSubscriptionStatus($grant['source_id']);
        if ($status === null) {
            return false; // Subscription not found
        }

        $failingStatuses = ['failing', 'past_due', 'expiring'];
        return !in_array($status, $failingStatuses, true);
    }

    /**
     * Simulate assertCurrentGoalState logic.
     * Returns true if user has active grants with active subscriptions matching plan filter.
     */
    private function simulateAssertGoalState(int $userId, array $benchmarkPlanIds = []): bool
    {
        $activeGrants = array_values(array_filter(
            $this->grants,
            fn($g) => $g['user_id'] === $userId && $g['status'] === 'active'
        ));

        if (empty($activeGrants)) {
            return false;
        }

        foreach ($activeGrants as $grant) {
            // Plan filter
            if (!empty($benchmarkPlanIds)) {
                if (!in_array($grant['plan_id'], array_map('intval', $benchmarkPlanIds), true)) {
                    continue;
                }
            }

            // Check subscription is active
            if ($grant['source_type'] === 'subscription' && $grant['source_id']) {
                if ($this->isSubscriptionActive($grant)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Simulate benchmark handle() plan matching logic.
     */
    private function matchesPlanCondition(int $planId, array $conditionPlanIds): bool
    {
        if (empty($conditionPlanIds)) {
            return true;
        }
        return in_array($planId, array_map('intval', $conditionPlanIds), true);
    }

    // ===================================================================
    // BENCHMARK HANDLE TESTS (event-driven)
    // ===================================================================

    public function test_benchmark_detects_recovery_via_renewal(): void
    {
        $grant = $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'active');

        // grant_renewed fires, subscription is now active -> recovered
        $this->assertTrue($this->isSubscriptionActive($grant));
    }

    public function test_benchmark_not_met_when_still_failing(): void
    {
        $grant = $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'failing');

        // grant_renewed fires but subscription is still failing -> not recovered
        $this->assertFalse($this->isSubscriptionActive($grant));
    }

    public function test_benchmark_not_met_when_past_due(): void
    {
        $grant = $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'past_due');

        $this->assertFalse($this->isSubscriptionActive($grant));
    }

    public function test_benchmark_not_met_when_expiring(): void
    {
        $grant = $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'expiring');

        $this->assertFalse($this->isSubscriptionActive($grant));
    }

    public function test_benchmark_met_when_subscription_trialing(): void
    {
        $grant = $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'trialing');

        $this->assertTrue($this->isSubscriptionActive($grant));
    }

    // ===================================================================
    // ASSERT CURRENT GOAL STATE TESTS (polling)
    // ===================================================================

    public function test_benchmark_assert_current_goal_state_active_subscription(): void
    {
        $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'active');

        $this->assertTrue($this->simulateAssertGoalState(100));
    }

    public function test_benchmark_assert_current_goal_state_failing_subscription(): void
    {
        $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'failing');

        $this->assertFalse($this->simulateAssertGoalState(100));
    }

    public function test_benchmark_assert_goal_state_no_active_grants(): void
    {
        // User has no active grants
        $this->createGrant(1, 100, 10, 42, 'subscription', 'expired');
        $this->createSubscription(42, 'active');

        $this->assertFalse($this->simulateAssertGoalState(100));
    }

    public function test_benchmark_assert_goal_state_subscription_not_found(): void
    {
        $this->createGrant(1, 100, 10, 999, 'subscription');
        // No subscription created for id 999

        $this->assertFalse($this->simulateAssertGoalState(100));
    }

    // ===================================================================
    // PLAN FILTER TESTS
    // ===================================================================

    public function test_benchmark_plan_filter_respected(): void
    {
        $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'active');

        // Plan 10 matches filter [10, 20]
        $this->assertTrue($this->simulateAssertGoalState(100, [10, 20]));

        // Plan 10 does not match filter [30, 40]
        $this->assertFalse($this->simulateAssertGoalState(100, [30, 40]));
    }

    public function test_benchmark_empty_plan_filter_matches_any(): void
    {
        $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'active');

        $this->assertTrue($this->simulateAssertGoalState(100, []));
    }

    public function test_benchmark_plan_condition_matching(): void
    {
        // Empty condition = any plan
        $this->assertTrue($this->matchesPlanCondition(10, []));

        // Plan in list
        $this->assertTrue($this->matchesPlanCondition(10, [10, 20]));

        // Plan not in list
        $this->assertFalse($this->matchesPlanCondition(30, [10, 20]));
    }

    public function test_benchmark_multiple_grants_first_matching_wins(): void
    {
        // Grant for plan 10 with failing subscription
        $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createSubscription(42, 'failing');

        // Grant for plan 20 with active subscription
        $this->createGrant(2, 100, 20, 43, 'subscription');
        $this->createSubscription(43, 'active');

        // Filter for any plan -> should find the second grant
        $this->assertTrue($this->simulateAssertGoalState(100, []));

        // Filter for plan 10 only -> subscription is failing
        $this->assertFalse($this->simulateAssertGoalState(100, [10]));

        // Filter for plan 20 only -> subscription is active
        $this->assertTrue($this->simulateAssertGoalState(100, [20]));
    }
}
