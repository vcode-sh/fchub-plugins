<?php

namespace FChubMemberships\Tests\FluentCRM\Benchmarks;

use PHPUnit\Framework\TestCase;

/**
 * Tests for MembershipPausedBenchmark and MembershipRevokedBenchmark logic.
 *
 * Simulates the benchmark matching logic without database access.
 */
class PausedRevokedBenchmarkTest extends TestCase
{
    private array $grants = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grants = [];
        $GLOBALS['wp_actions_fired'] = [];
    }

    // ---------------------------------------------------------------
    // Test 20: paused benchmark met on pause
    // ---------------------------------------------------------------
    public function test_paused_benchmark_met_on_pause(): void
    {
        // Simulate grant_paused hook firing
        $grant = ['id' => 1, 'user_id' => 100, 'plan_id' => 1, 'status' => 'paused'];
        $reason = 'User requested pause';

        // The benchmark handle() receives ($grant, $reason)
        // Test that plan matching works
        $benchmarkPlanIds = []; // empty = any plan
        $planId = $grant['plan_id'];

        $matches = $this->matchesPlanCondition($planId, $benchmarkPlanIds);
        $this->assertTrue($matches, 'Empty plan_ids should match any plan');
    }

    // ---------------------------------------------------------------
    // Test 21: paused benchmark assert state with paused grant
    // ---------------------------------------------------------------
    public function test_paused_benchmark_assert_state_with_paused_grant(): void
    {
        $userId = 100;

        // User has a paused grant
        $this->grants = [
            ['id' => 1, 'user_id' => $userId, 'plan_id' => 1, 'status' => 'paused'],
        ];

        $result = $this->assertPausedGoalState($userId, []);
        $this->assertTrue($result, 'Should assert true when user has paused grant');

        // With specific plan filter
        $result = $this->assertPausedGoalState($userId, [1]);
        $this->assertTrue($result, 'Should assert true when paused grant matches plan filter');

        // With non-matching plan filter
        $result = $this->assertPausedGoalState($userId, [99]);
        $this->assertFalse($result, 'Should assert false when paused grant does not match plan filter');
    }

    // ---------------------------------------------------------------
    // Test 22: revoked benchmark met on revoke
    // ---------------------------------------------------------------
    public function test_revoked_benchmark_met_on_revoke(): void
    {
        // Simulate grant_revoked hook firing: ($grants, $planId, $userId, $reason)
        $grants = [['id' => 1, 'user_id' => 100, 'plan_id' => 2, 'status' => 'revoked']];
        $planId = 2;
        $userId = 100;
        $reason = 'Admin revoked';

        // Test plan matching with specific plan ID
        $benchmarkPlanIds = [2];
        $matches = $this->matchesPlanCondition($planId, $benchmarkPlanIds);
        $this->assertTrue($matches, 'Should match when plan_id is in benchmark plan_ids');

        // Test with non-matching plan
        $benchmarkPlanIds = [99];
        $matches = $this->matchesPlanCondition($planId, $benchmarkPlanIds);
        $this->assertFalse($matches, 'Should not match when plan_id is not in benchmark plan_ids');
    }

    // ---------------------------------------------------------------
    // Test 23: revoked benchmark assert state with revoked grant
    // ---------------------------------------------------------------
    public function test_revoked_benchmark_assert_state_with_revoked_grant(): void
    {
        $userId = 100;

        // User has a revoked grant
        $this->grants = [
            ['id' => 1, 'user_id' => $userId, 'plan_id' => 2, 'status' => 'revoked'],
        ];

        $result = $this->assertRevokedGoalState($userId, []);
        $this->assertTrue($result, 'Should assert true when user has revoked grant');

        $result = $this->assertRevokedGoalState($userId, [2]);
        $this->assertTrue($result, 'Should assert true when revoked grant matches plan filter');

        $result = $this->assertRevokedGoalState($userId, [99]);
        $this->assertFalse($result, 'Should assert false when revoked grant does not match plan filter');
    }

    // ---------------------------------------------------------------
    // Test 24: benchmarks respect plan filter
    // ---------------------------------------------------------------
    public function test_benchmarks_respect_plan_filter(): void
    {
        $userId = 100;

        // User has multiple grants with different statuses and plans
        $this->grants = [
            ['id' => 1, 'user_id' => $userId, 'plan_id' => 1, 'status' => 'active'],
            ['id' => 2, 'user_id' => $userId, 'plan_id' => 2, 'status' => 'paused'],
            ['id' => 3, 'user_id' => $userId, 'plan_id' => 3, 'status' => 'revoked'],
        ];

        // Paused benchmark: plan 2 should match
        $this->assertTrue($this->assertPausedGoalState($userId, [2]));
        // Paused benchmark: plan 1 should NOT match (it's active, not paused)
        $this->assertFalse($this->assertPausedGoalState($userId, [1]));

        // Revoked benchmark: plan 3 should match
        $this->assertTrue($this->assertRevokedGoalState($userId, [3]));
        // Revoked benchmark: plan 1 should NOT match (it's active, not revoked)
        $this->assertFalse($this->assertRevokedGoalState($userId, [1]));

        // Empty plan_ids = any plan with the matching status
        $this->assertTrue($this->assertPausedGoalState($userId, []));
        $this->assertTrue($this->assertRevokedGoalState($userId, []));
    }

    // ---------------------------------------------------------------
    // Helpers: mirror benchmark logic
    // ---------------------------------------------------------------

    private function matchesPlanCondition(int $planId, array $conditionPlanIds): bool
    {
        if (empty($conditionPlanIds)) {
            return true;
        }
        return in_array($planId, array_map('intval', $conditionPlanIds), true);
    }

    private function assertPausedGoalState(int $userId, array $benchmarkPlanIds): bool
    {
        $pausedGrants = array_filter($this->grants, function ($g) use ($userId) {
            return $g['user_id'] === $userId && $g['status'] === 'paused';
        });

        if (empty($pausedGrants)) {
            return false;
        }

        if (empty($benchmarkPlanIds)) {
            return true;
        }

        $benchmarkPlanIds = array_map('intval', $benchmarkPlanIds);
        foreach ($pausedGrants as $grant) {
            if (in_array($grant['plan_id'], $benchmarkPlanIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function assertRevokedGoalState(int $userId, array $benchmarkPlanIds): bool
    {
        $revokedGrants = array_filter($this->grants, function ($g) use ($userId) {
            return $g['user_id'] === $userId && $g['status'] === 'revoked';
        });

        if (empty($revokedGrants)) {
            return false;
        }

        if (empty($benchmarkPlanIds)) {
            return true;
        }

        $benchmarkPlanIds = array_map('intval', $benchmarkPlanIds);
        foreach ($revokedGrants as $grant) {
            if (in_array($grant['plan_id'], $benchmarkPlanIds, true)) {
                return true;
            }
        }

        return false;
    }
}
