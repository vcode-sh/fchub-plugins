<?php

namespace FChubMemberships\Tests\FluentCRM\Benchmarks;

use PHPUnit\Framework\TestCase;

/**
 * Tests for MembershipResumedBenchmark logic.
 *
 * Simulates the benchmark condition checks using in-memory state
 * without requiring FluentCRM or WordPress database.
 */
class MembershipResumedBenchmarkTest extends TestCase
{
    /** @var array In-memory grants */
    private array $grants = [];

    /** @var int Auto-increment for grants */
    private int $nextGrantId = 1;

    /** @var array Tracks fired actions */
    private array $firedActions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grants = [];
        $this->nextGrantId = 1;
        $this->firedActions = [];
        $GLOBALS['wp_options'] = [];
        $GLOBALS['wp_actions_fired'] = [];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createGrant(int $userId, int $planId, string $status = 'active'): array
    {
        $grant = [
            'id'        => $this->nextGrantId++,
            'user_id'   => $userId,
            'plan_id'   => $planId,
            'status'    => $status,
            'meta'      => [],
        ];
        $this->grants[] = $grant;
        return $grant;
    }

    private function getUserActiveGrants(int $userId): array
    {
        return array_values(array_filter(
            $this->grants,
            fn($g) => $g['user_id'] === $userId && $g['status'] === 'active'
        ));
    }

    /**
     * Simulate the benchmark handle() plan matching logic.
     */
    private function simulatePlanMatch(int $planId, array $benchmarkPlanIds): bool
    {
        if (empty($benchmarkPlanIds)) {
            return true;
        }
        return in_array($planId, array_map('intval', $benchmarkPlanIds), true);
    }

    /**
     * Simulate the assertCurrentGoalState logic from MembershipResumedBenchmark.
     * Returns true if the user has any active grants matching the plan filter.
     */
    private function simulateGoalState(int $userId, array $benchmarkPlanIds): bool
    {
        $activeGrants = $this->getUserActiveGrants($userId);

        if (empty($activeGrants)) {
            return false;
        }

        if (empty($benchmarkPlanIds)) {
            return true;
        }

        $benchmarkPlanIds = array_map('intval', $benchmarkPlanIds);
        foreach ($activeGrants as $grant) {
            if (in_array($grant['plan_id'], $benchmarkPlanIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simulate the resumeGrant action and benchmark event handling.
     */
    private function simulateResumeEvent(array $grant, array $benchmarkPlanIds): bool
    {
        // The benchmark listens to fchub_memberships/grant_resumed
        // handle() extracts grant, checks plan_ids, then starts funnel

        if (empty($grant['user_id'])) {
            return false;
        }

        $planId = $grant['plan_id'] ?? 0;
        return $this->simulatePlanMatch($planId, $benchmarkPlanIds);
    }

    // ===================================================================
    // BENCHMARK MET TESTS
    // ===================================================================

    public function test_benchmark_met_on_resume_event(): void
    {
        $grant = $this->createGrant(100, 10, 'active');

        // Simulate the grant_resumed event firing
        $result = $this->simulateResumeEvent($grant, []);

        $this->assertTrue($result, 'Benchmark should be met when grant_resumed fires with no plan filter');
    }

    public function test_benchmark_met_on_resume_with_matching_plan(): void
    {
        $grant = $this->createGrant(100, 10, 'active');

        $result = $this->simulateResumeEvent($grant, [10, 20]);

        $this->assertTrue($result, 'Benchmark should be met when plan matches filter');
    }

    // ===================================================================
    // PLAN FILTER TESTS
    // ===================================================================

    public function test_benchmark_respects_plan_filter(): void
    {
        $grant = $this->createGrant(100, 10, 'active');

        // Plan 10 is NOT in the filter [20, 30]
        $result = $this->simulateResumeEvent($grant, [20, 30]);

        $this->assertFalse($result, 'Benchmark should not be met when plan does not match filter');
    }

    public function test_benchmark_fires_for_any_plan_when_no_filter(): void
    {
        $grant = $this->createGrant(100, 999, 'active');

        $result = $this->simulateResumeEvent($grant, []);

        $this->assertTrue($result, 'Benchmark should fire for any plan when no filter configured');
    }

    // ===================================================================
    // ASSERT CURRENT GOAL STATE TESTS
    // ===================================================================

    public function test_assert_current_goal_state_with_active_grant(): void
    {
        $this->createGrant(100, 10, 'active');

        $result = $this->simulateGoalState(100, []);

        $this->assertTrue($result, 'Goal state should be true when user has active grants');
    }

    public function test_assert_current_goal_state_with_active_grant_matching_plan(): void
    {
        $this->createGrant(100, 10, 'active');

        $result = $this->simulateGoalState(100, [10]);

        $this->assertTrue($result, 'Goal state should be true when user has active grant for matching plan');
    }

    public function test_assert_current_goal_state_with_paused_grant(): void
    {
        // User only has a paused grant, no active ones
        $this->createGrant(100, 10, 'paused');

        $result = $this->simulateGoalState(100, []);

        $this->assertFalse($result, 'Goal state should be false when user only has paused grants');
    }

    public function test_assert_current_goal_state_with_no_grants(): void
    {
        $result = $this->simulateGoalState(100, []);

        $this->assertFalse($result, 'Goal state should be false when user has no grants');
    }

    public function test_assert_current_goal_state_with_non_matching_plan(): void
    {
        $this->createGrant(100, 10, 'active');

        $result = $this->simulateGoalState(100, [20, 30]);

        $this->assertFalse($result, 'Goal state should be false when active grant plan does not match filter');
    }

    public function test_assert_current_goal_state_with_mixed_statuses(): void
    {
        // One paused, one active
        $this->createGrant(100, 10, 'paused');
        $this->createGrant(100, 20, 'active');

        $result = $this->simulateGoalState(100, [20]);

        $this->assertTrue($result, 'Goal state should be true when user has at least one active matching grant');
    }

    public function test_assert_current_goal_state_revoked_does_not_count(): void
    {
        $this->createGrant(100, 10, 'revoked');

        $result = $this->simulateGoalState(100, []);

        $this->assertFalse($result, 'Revoked grants should not satisfy the goal state');
    }
}
