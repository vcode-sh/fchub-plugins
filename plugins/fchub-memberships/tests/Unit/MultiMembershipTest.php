<?php

namespace FChubMemberships\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for multi-membership mode logic (stack, exclusive, upgrade_only).
 *
 * These tests simulate the grant/revoke flow from AccessGrantService::grantPlan()
 * using in-memory arrays instead of database repositories. The logic mirrors
 * lines 64-129 of AccessGrantService.php.
 */
class MultiMembershipTest extends TestCase
{
    /** @var array<int, array> In-memory grant store keyed by grant ID */
    private array $grants = [];

    /** @var array<int, array> In-memory plan store keyed by plan ID */
    private array $plans = [];

    /** @var int Auto-increment counter for grant IDs */
    private int $nextGrantId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grants = [];
        $this->plans = [];
        $this->nextGrantId = 1;
        $GLOBALS['wp_options'] = [];
        $GLOBALS['wp_actions_fired'] = [];
    }

    // ---------------------------------------------------------------
    // Helpers – mirror the real AccessGrantService / GrantRepository
    // ---------------------------------------------------------------

    /**
     * Create a mock plan definition.
     */
    private function createPlan(int $id, string $title, int $level = 0, array $extra = []): array
    {
        $plan = array_merge([
            'id' => $id,
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'status' => 'active',
            'level' => $level,
            'duration_type' => 'lifetime',
            'duration_days' => null,
            'trial_days' => 0,
            'grace_period_days' => 0,
            'includes_plan_ids' => [],
            'settings' => [],
            'meta' => [],
        ], $extra);
        $this->plans[$id] = $plan;
        return $plan;
    }

    /**
     * Simulate AccessGrantService::grantPlan() multi-membership enforcement
     * (lines 64-129 of the real service).
     *
     * @return array{created:int, updated:int, total:int, blocked?:bool, reason?:string}
     */
    private function simulateGrant(int $userId, int $planId, string $mode = 'stack'): array
    {
        $plan = $this->plans[$planId] ?? null;
        if (!$plan) {
            return ['error' => 'Plan not found'];
        }

        // Get user's active plan IDs, excluding current plan (renewal case)
        $activePlanIds = $this->getUserActivePlanIds($userId);
        $otherPlanIds = array_values(array_filter($activePlanIds, fn($id) => $id !== $planId));

        if ($mode !== 'stack' && !empty($otherPlanIds)) {
            if ($mode === 'exclusive') {
                // Revoke ALL other plans regardless of level
                foreach ($otherPlanIds as $oldPlanId) {
                    $this->revokeGrants($userId, $oldPlanId, 'Replaced by plan #' . $planId);
                }
                do_action('fchub_memberships/plan_replaced', $userId, $planId, $otherPlanIds);
            } elseif ($mode === 'upgrade_only') {
                $planLevel = $plan['level'] ?? 0;
                $currentHighest = $this->getHighestActivePlanLevel($userId);

                // Block if new plan level is strictly lower than current highest
                if ($planLevel < $currentHighest) {
                    return [
                        'created' => 0,
                        'updated' => 0,
                        'total' => 0,
                        'blocked' => true,
                        'reason' => 'downgrade_blocked',
                    ];
                }

                // Revoke only plans with strictly lower level
                $revokedPlanIds = [];
                foreach ($otherPlanIds as $oldPlanId) {
                    $oldPlan = $this->plans[$oldPlanId] ?? null;
                    if ($oldPlan && ($oldPlan['level'] ?? 0) < $planLevel) {
                        $this->revokeGrants($userId, $oldPlanId, 'Upgraded to plan #' . $planId);
                        $revokedPlanIds[] = $oldPlanId;
                    }
                }
                if (!empty($revokedPlanIds)) {
                    do_action('fchub_memberships/plan_upgraded', $userId, $planId, $revokedPlanIds);
                }
            }
        }

        // Create the grant
        $grantId = $this->nextGrantId++;
        $this->grants[$grantId] = [
            'id' => $grantId,
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => 'active',
            'level' => $plan['level'] ?? 0,
            'source_type' => 'manual',
            'source_id' => 0,
            'source_ids' => [],
            'meta' => [],
        ];

        return ['created' => 1, 'updated' => 0, 'total' => 1];
    }

    /**
     * Mirrors GrantRepository::getUserActivePlanIds().
     *
     * @return int[]
     */
    private function getUserActivePlanIds(int $userId): array
    {
        $planIds = [];
        foreach ($this->grants as $grant) {
            if ($grant['user_id'] === $userId && $grant['status'] === 'active' && $grant['plan_id'] !== null) {
                $planIds[] = $grant['plan_id'];
            }
        }
        return array_values(array_unique($planIds));
    }

    /**
     * Mirrors GrantRepository::getHighestActivePlanLevel().
     */
    private function getHighestActivePlanLevel(int $userId): int
    {
        $planIds = $this->getUserActivePlanIds($userId);
        if (empty($planIds)) {
            return 0;
        }

        $maxLevel = 0;
        foreach ($planIds as $planId) {
            $plan = $this->plans[$planId] ?? null;
            if ($plan) {
                $maxLevel = max($maxLevel, $plan['level'] ?? 0);
            }
        }
        return $maxLevel;
    }

    /**
     * Revoke all active grants for a user+plan combination.
     * Mirrors AccessGrantService::revokePlan() (simplified).
     */
    private function revokeGrants(int $userId, int $planId, string $reason = ''): void
    {
        foreach ($this->grants as &$grant) {
            if ($grant['user_id'] === $userId && $grant['plan_id'] === $planId && $grant['status'] === 'active') {
                $grant['status'] = 'revoked';
                $grant['meta']['revoke_reason'] = $reason;
            }
        }
        unset($grant);
    }

    /**
     * Get all active grants for a specific user.
     */
    private function getActiveGrantsForUser(int $userId): array
    {
        return array_values(array_filter(
            $this->grants,
            fn($g) => $g['user_id'] === $userId && $g['status'] === 'active'
        ));
    }

    /**
     * Count grants matching status for a user.
     */
    private function countGrantsByStatus(int $userId, string $status): int
    {
        return count(array_filter(
            $this->grants,
            fn($g) => $g['user_id'] === $userId && $g['status'] === $status
        ));
    }

    /**
     * Filter fired WP actions by tag name.
     */
    private function getActionsFired(string $tag): array
    {
        return array_values(array_filter(
            $GLOBALS['wp_actions_fired'],
            fn($a) => $a['tag'] === $tag
        ));
    }

    // ===================================================================
    // STACK MODE TESTS
    // ===================================================================

    public function testStackMode_AllowsMultiplePlans(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $result1 = $this->simulateGrant(100, 1, 'stack');
        $result2 = $this->simulateGrant(100, 2, 'stack');

        $this->assertEquals(1, $result1['created']);
        $this->assertEquals(1, $result2['created']);

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(2, $active);

        $activePlanIds = array_column($active, 'plan_id');
        $this->assertContains(1, $activePlanIds);
        $this->assertContains(2, $activePlanIds);
    }

    public function testStackMode_AllowsThreeOrMorePlans(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 3);

        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');
        $this->simulateGrant(100, 3, 'stack');

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(3, $active);
    }

    public function testStackMode_RevokingOnePlanKeepsOthers(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');

        $this->revokeGrants(100, 1);

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(2, $active[0]['plan_id']);
    }

    public function testStackMode_SamePlanTwiceCreatesAdditionalGrant(): void
    {
        $this->createPlan(1, 'Bronze', 1);

        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 1, 'stack');

        // In the real code, grant_key deduplication handles this by updating
        // the existing grant. In our simplified simulation, both grants exist.
        $active = $this->getActiveGrantsForUser(100);
        $this->assertGreaterThanOrEqual(1, count($active));
    }

    public function testStackMode_DoesNotFireReplacedOrUpgradedActions(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');

        $replaced = $this->getActionsFired('fchub_memberships/plan_replaced');
        $upgraded = $this->getActionsFired('fchub_memberships/plan_upgraded');

        $this->assertCount(0, $replaced);
        $this->assertCount(0, $upgraded);
    }

    // ===================================================================
    // EXCLUSIVE MODE TESTS
    // ===================================================================

    public function testExclusiveMode_NewPlanRevokesOldPlan(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'exclusive');
        $this->simulateGrant(100, 2, 'exclusive');

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(2, $active[0]['plan_id']);
    }

    public function testExclusiveMode_OldGrantIsMarkedRevoked(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'exclusive');
        $this->simulateGrant(100, 2, 'exclusive');

        $revokedCount = $this->countGrantsByStatus(100, 'revoked');
        $this->assertEquals(1, $revokedCount);
    }

    public function testExclusiveMode_FiresPlanReplacedAction(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'exclusive');
        $GLOBALS['wp_actions_fired'] = [];
        $this->simulateGrant(100, 2, 'exclusive');

        $actions = $this->getActionsFired('fchub_memberships/plan_replaced');
        $this->assertCount(1, $actions);

        $action = $actions[0];
        $this->assertEquals(100, $action['args'][0]);    // userId
        $this->assertEquals(2, $action['args'][1]);       // newPlanId
        $this->assertContains(1, $action['args'][2]);     // oldPlanIds array
    }

    public function testExclusiveMode_FirstPlanHasNoRevocation(): void
    {
        $this->createPlan(1, 'Bronze', 1);

        $result = $this->simulateGrant(100, 1, 'exclusive');

        $this->assertEquals(1, $result['created']);
        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);

        $replaced = $this->getActionsFired('fchub_memberships/plan_replaced');
        $this->assertCount(0, $replaced, 'No plan_replaced action should fire for the first plan');
    }

    public function testExclusiveMode_AllowsDowngrade(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 2, 'exclusive'); // Higher first
        $this->simulateGrant(100, 1, 'exclusive'); // Lower - allowed in exclusive

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(1, $active[0]['plan_id']); // Bronze is now active
    }

    public function testExclusiveMode_RevokesMultipleOldPlans(): void
    {
        // Edge case: if somehow user ended up with multiple plans (e.g. after mode change)
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 3);

        // Grant two plans via stack first to set up the scenario
        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');

        // Now switch to exclusive for the third plan
        $this->simulateGrant(100, 3, 'exclusive');

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(3, $active[0]['plan_id']);
    }

    public function testExclusiveMode_RenewingSamePlanDoesNotSelfRevoke(): void
    {
        $this->createPlan(1, 'Silver', 2);

        $this->simulateGrant(100, 1, 'exclusive');
        $activeBefore = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $activeBefore);

        // Simulate renewal: same plan granted again.
        // The code filters out the current plan from otherPlanIds.
        $result = $this->simulateGrant(100, 1, 'exclusive');

        $this->assertEquals(1, $result['created']);
        $activeAfter = $this->getActiveGrantsForUser(100);
        $this->assertNotEmpty($activeAfter);

        // No plan_replaced action because the renewed plan is excluded
        $replaced = $this->getActionsFired('fchub_memberships/plan_replaced');
        $this->assertCount(0, $replaced);
    }

    public function testExclusiveMode_ReplacedActionContainsAllOldPlanIds(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 3);

        // Set up multiple via stack
        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');

        $GLOBALS['wp_actions_fired'] = [];
        $this->simulateGrant(100, 3, 'exclusive');

        $actions = $this->getActionsFired('fchub_memberships/plan_replaced');
        $this->assertCount(1, $actions);

        $oldPlanIds = $actions[0]['args'][2];
        $this->assertContains(1, $oldPlanIds);
        $this->assertContains(2, $oldPlanIds);
        $this->assertCount(2, $oldPlanIds);
    }

    // ===================================================================
    // UPGRADE ONLY MODE TESTS
    // ===================================================================

    public function testUpgradeOnly_AllowsUpgrade(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $result = $this->simulateGrant(100, 2, 'upgrade_only');

        $this->assertEquals(1, $result['created']);
        $this->assertFalse($result['blocked'] ?? false);

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(2, $active[0]['plan_id']); // Silver replaced Bronze
    }

    public function testUpgradeOnly_BlocksDowngrade(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 2, 'upgrade_only');
        $result = $this->simulateGrant(100, 1, 'upgrade_only');

        $this->assertTrue($result['blocked'] ?? false);
        $this->assertEquals('downgrade_blocked', $result['reason'] ?? '');

        // Silver should remain active
        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(2, $active[0]['plan_id']);
    }

    public function testUpgradeOnly_BlocksDowngradeReturnsZeroCounts(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 2, 'upgrade_only');
        $result = $this->simulateGrant(100, 1, 'upgrade_only');

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(0, $result['updated']);
        $this->assertEquals(0, $result['total']);
    }

    public function testUpgradeOnly_AllowsSameLevel(): void
    {
        $this->createPlan(1, 'Silver A', 2);
        $this->createPlan(2, 'Silver B', 2);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $result = $this->simulateGrant(100, 2, 'upgrade_only');

        $this->assertEquals(1, $result['created']);
        $this->assertFalse($result['blocked'] ?? false);
    }

    public function testUpgradeOnly_SameLevelKeepsBothPlans(): void
    {
        $this->createPlan(1, 'Silver A', 2);
        $this->createPlan(2, 'Silver B', 2);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $this->simulateGrant(100, 2, 'upgrade_only');

        // Same level: Silver A should NOT be revoked (only strictly lower levels get revoked)
        $active = $this->getActiveGrantsForUser(100);
        $activePlanIds = array_column($active, 'plan_id');
        $this->assertContains(1, $activePlanIds, 'Silver A should be kept (same level)');
        $this->assertContains(2, $activePlanIds, 'Silver B should be added');
    }

    public function testUpgradeOnly_FiresPlanUpgradedAction(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $GLOBALS['wp_actions_fired'] = [];
        $this->simulateGrant(100, 2, 'upgrade_only');

        $actions = $this->getActionsFired('fchub_memberships/plan_upgraded');
        $this->assertCount(1, $actions);

        $action = $actions[0];
        $this->assertEquals(100, $action['args'][0]);    // userId
        $this->assertEquals(2, $action['args'][1]);       // newPlanId
        $this->assertContains(1, $action['args'][2]);     // revokedPlanIds
    }

    public function testUpgradeOnly_DoesNotFireUpgradedActionForSameLevel(): void
    {
        $this->createPlan(1, 'Silver A', 2);
        $this->createPlan(2, 'Silver B', 2);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $GLOBALS['wp_actions_fired'] = [];
        $this->simulateGrant(100, 2, 'upgrade_only');

        // Same level means no plans get revoked, so no upgraded action
        $actions = $this->getActionsFired('fchub_memberships/plan_upgraded');
        $this->assertCount(0, $actions);
    }

    public function testUpgradeOnly_NoActiveGrantsAllowsAnyLevel(): void
    {
        $this->createPlan(1, 'Bronze', 1);

        $result = $this->simulateGrant(100, 1, 'upgrade_only');

        $this->assertEquals(1, $result['created']);
        $this->assertFalse($result['blocked'] ?? false);
    }

    public function testUpgradeOnly_NoActiveGrantsAllowsHighLevel(): void
    {
        $this->createPlan(5, 'Platinum', 10);

        $result = $this->simulateGrant(100, 5, 'upgrade_only');

        $this->assertEquals(1, $result['created']);
        $this->assertFalse($result['blocked'] ?? false);
    }

    public function testUpgradeOnly_LevelZeroTreatedAsLowest(): void
    {
        $this->createPlan(1, 'Free', 0);
        $this->createPlan(2, 'Paid', 1);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $result = $this->simulateGrant(100, 2, 'upgrade_only');

        $this->assertEquals(1, $result['created']);

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(2, $active[0]['plan_id']);
    }

    public function testUpgradeOnly_MultipleOldPlansOnlyLowerRevoked(): void
    {
        $this->createPlan(1, 'Free', 0);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 5);

        // Set up two plans via stack
        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');

        // Now grant Gold in upgrade_only mode
        $result = $this->simulateGrant(100, 3, 'upgrade_only');

        $this->assertEquals(1, $result['created']);

        $active = $this->getActiveGrantsForUser(100);
        $activePlanIds = array_column($active, 'plan_id');

        $this->assertNotContains(1, $activePlanIds, 'Free (level 0) should be revoked');
        $this->assertNotContains(2, $activePlanIds, 'Silver (level 2) should be revoked');
        $this->assertContains(3, $activePlanIds, 'Gold (level 5) should be active');
    }

    public function testUpgradeOnly_UpgradedActionContainsOnlyRevokedPlanIds(): void
    {
        $this->createPlan(1, 'Free', 0);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 2); // Same level as Silver
        $this->createPlan(4, 'Platinum', 5);

        // Set up three plans
        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');
        $this->simulateGrant(100, 3, 'stack');

        $GLOBALS['wp_actions_fired'] = [];
        $this->simulateGrant(100, 4, 'upgrade_only');

        $actions = $this->getActionsFired('fchub_memberships/plan_upgraded');
        $this->assertCount(1, $actions);

        $revokedPlanIds = $actions[0]['args'][2];
        // Free (0) and Silver (2) and Gold (2) are all < Platinum (5)
        $this->assertContains(1, $revokedPlanIds, 'Free should be in revoked list');
        $this->assertContains(2, $revokedPlanIds, 'Silver should be in revoked list');
        $this->assertContains(3, $revokedPlanIds, 'Gold should be in revoked list');
    }

    public function testUpgradeOnly_SequentialUpgrades(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 3);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $this->simulateGrant(100, 2, 'upgrade_only');
        $this->simulateGrant(100, 3, 'upgrade_only');

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(3, $active[0]['plan_id']);
    }

    public function testUpgradeOnly_BlocksDowngradeAfterMultipleUpgrades(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 3);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $this->simulateGrant(100, 2, 'upgrade_only');
        $this->simulateGrant(100, 3, 'upgrade_only');

        // Try to downgrade back to Bronze
        $result = $this->simulateGrant(100, 1, 'upgrade_only');

        $this->assertTrue($result['blocked'] ?? false);
        $this->assertEquals('downgrade_blocked', $result['reason']);

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(3, $active[0]['plan_id']);
    }

    // ===================================================================
    // HELPER METHOD TESTS
    // ===================================================================

    public function testGetUserActivePlanIds_ReturnsCorrectIds(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');

        $ids = $this->getUserActivePlanIds(100);
        $this->assertCount(2, $ids);
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
    }

    public function testGetUserActivePlanIds_ExcludesRevokedGrants(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');
        $this->revokeGrants(100, 1);

        $ids = $this->getUserActivePlanIds(100);
        $this->assertCount(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertNotContains(1, $ids);
    }

    public function testGetUserActivePlanIds_ReturnsEmptyForUnknownUser(): void
    {
        $ids = $this->getUserActivePlanIds(999);
        $this->assertEmpty($ids);
    }

    public function testGetUserActivePlanIds_DeduplicatesSamePlan(): void
    {
        $this->createPlan(1, 'Bronze', 1);

        // Grant same plan twice (in stack mode, creates two grant records)
        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 1, 'stack');

        $ids = $this->getUserActivePlanIds(100);
        // Should return unique plan IDs only
        $this->assertEquals(array_unique($ids), $ids);
        $this->assertContains(1, $ids);
    }

    public function testGetHighestActivePlanLevel_ReturnsMaxLevel(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 5);

        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');

        $this->assertEquals(5, $this->getHighestActivePlanLevel(100));
    }

    public function testGetHighestActivePlanLevel_ReturnsZeroForNoPlans(): void
    {
        $this->assertEquals(0, $this->getHighestActivePlanLevel(100));
    }

    public function testGetHighestActivePlanLevel_IgnoresRevokedGrants(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Gold', 10);

        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');
        $this->revokeGrants(100, 2); // Revoke Gold

        $this->assertEquals(1, $this->getHighestActivePlanLevel(100));
    }

    public function testGetHighestActivePlanLevel_HandlesLevelZero(): void
    {
        $this->createPlan(1, 'Free', 0);

        $this->simulateGrant(100, 1, 'stack');

        $this->assertEquals(0, $this->getHighestActivePlanLevel(100));
    }

    // ===================================================================
    // EDGE CASES & CROSS-CUTTING SCENARIOS
    // ===================================================================

    public function testDifferentUsersAreIndependent(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'exclusive');
        $this->simulateGrant(200, 2, 'exclusive');

        $active100 = $this->getActiveGrantsForUser(100);
        $active200 = $this->getActiveGrantsForUser(200);

        $this->assertCount(1, $active100);
        $this->assertEquals(1, $active100[0]['plan_id']);
        $this->assertCount(1, $active200);
        $this->assertEquals(2, $active200[0]['plan_id']);
    }

    public function testDifferentUsersIndependentInUpgradeMode(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        // User 100 gets Silver
        $this->simulateGrant(100, 2, 'upgrade_only');

        // User 200 should still be able to get Bronze (independent)
        $result = $this->simulateGrant(200, 1, 'upgrade_only');

        $this->assertEquals(1, $result['created']);
        $this->assertFalse($result['blocked'] ?? false);
    }

    public function testModeChangeDoesNotRetroactivelyAffectExistingGrants(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        // Stack mode: user gets both plans
        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(2, $active, 'Both plans should be active after stack mode grants');

        // No new grants in a different mode = existing grants are untouched
        // The mode only applies when a NEW grant is being created
        $activePlanIds = array_column($active, 'plan_id');
        $this->assertContains(1, $activePlanIds);
        $this->assertContains(2, $activePlanIds);
    }

    public function testExclusiveMode_PlanReplacedActionRevokeReasonIsStored(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'exclusive');
        $this->simulateGrant(100, 2, 'exclusive');

        // Check the revoked grant has a reason in meta
        $revokedGrants = array_values(array_filter(
            $this->grants,
            fn($g) => $g['user_id'] === 100 && $g['status'] === 'revoked'
        ));

        $this->assertCount(1, $revokedGrants);
        $this->assertStringContainsString('Replaced by plan #2', $revokedGrants[0]['meta']['revoke_reason']);
    }

    public function testUpgradeOnly_RevokeReasonContainsUpgradeInfo(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $this->simulateGrant(100, 2, 'upgrade_only');

        $revokedGrants = array_values(array_filter(
            $this->grants,
            fn($g) => $g['user_id'] === 100 && $g['status'] === 'revoked'
        ));

        $this->assertCount(1, $revokedGrants);
        $this->assertStringContainsString('Upgraded to plan #2', $revokedGrants[0]['meta']['revoke_reason']);
    }

    public function testStackMode_ExistingExclusivelyGrantedPlansAreNotAffected(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 3);

        // Exclusive mode leaves only Silver
        $this->simulateGrant(100, 1, 'exclusive');
        $this->simulateGrant(100, 2, 'exclusive');

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(2, $active[0]['plan_id']);

        // Now grant Gold in stack mode -- Silver stays
        $this->simulateGrant(100, 3, 'stack');

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(2, $active);

        $activePlanIds = array_column($active, 'plan_id');
        $this->assertContains(2, $activePlanIds);
        $this->assertContains(3, $activePlanIds);
    }

    public function testUpgradeOnly_DoesNotBlockDowngradeWhenNoActivePlans(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        // Grant and then revoke Silver
        $this->simulateGrant(100, 2, 'upgrade_only');
        $this->revokeGrants(100, 2);

        // Now Bronze should be allowed since no active plans exist
        $result = $this->simulateGrant(100, 1, 'upgrade_only');

        $this->assertEquals(1, $result['created']);
        $this->assertFalse($result['blocked'] ?? false);
    }

    public function testUpgradeOnly_StrictLevelComparisonNotLessOrEqual(): void
    {
        // Verifies the comparison is strictly less-than: planLevel < currentHighest
        // Equal levels should NOT be blocked
        $this->createPlan(1, 'Plan A', 3);
        $this->createPlan(2, 'Plan B', 3);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $result = $this->simulateGrant(100, 2, 'upgrade_only');

        // Same level (3 == 3): not blocked
        $this->assertFalse($result['blocked'] ?? false);
        $this->assertEquals(1, $result['created']);
    }

    public function testExclusiveMode_SubscriptionSourcedGrantsHandled(): void
    {
        $this->createPlan(1, 'Monthly', 1);
        $this->createPlan(2, 'Annual', 2);

        // Simulate subscription-sourced grant
        $grantId = $this->nextGrantId++;
        $this->grants[$grantId] = [
            'id' => $grantId,
            'user_id' => 100,
            'plan_id' => 1,
            'status' => 'active',
            'level' => 1,
            'source_type' => 'subscription',
            'source_id' => 42,
            'source_ids' => [42],
            'meta' => [],
        ];

        // Grant a new plan in exclusive mode
        $this->simulateGrant(100, 2, 'exclusive');

        $active = $this->getActiveGrantsForUser(100);
        $this->assertCount(1, $active);
        $this->assertEquals(2, $active[0]['plan_id']);

        // The subscription-sourced grant should be revoked
        $this->assertEquals('revoked', $this->grants[$grantId]['status']);
    }

    public function testNoGrantsExist_AllModesAllowFirstGrant(): void
    {
        $this->createPlan(1, 'Starter', 1);

        foreach (['stack', 'exclusive', 'upgrade_only'] as $mode) {
            $this->setUp(); // Reset state
            $this->createPlan(1, 'Starter', 1);

            $result = $this->simulateGrant(100, 1, $mode);

            $this->assertEquals(1, $result['created'], "First grant should succeed in {$mode} mode");
            $this->assertFalse($result['blocked'] ?? false, "First grant should not be blocked in {$mode} mode");
        }
    }

    public function testUpgradeOnly_LargeGapBetweenLevels(): void
    {
        $this->createPlan(1, 'Free', 0);
        $this->createPlan(2, 'Enterprise', 100);

        $this->simulateGrant(100, 1, 'upgrade_only');
        $result = $this->simulateGrant(100, 2, 'upgrade_only');

        $this->assertEquals(1, $result['created']);
        $this->assertFalse($result['blocked'] ?? false);

        // Reverse should be blocked
        $result2 = $this->simulateGrant(100, 1, 'upgrade_only');
        $this->assertTrue($result2['blocked'] ?? false);
    }

    public function testGrantCountTotals(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 3);

        // Stack: 3 grants total
        $this->simulateGrant(100, 1, 'stack');
        $this->simulateGrant(100, 2, 'stack');
        $this->simulateGrant(100, 3, 'stack');

        $this->assertCount(3, $this->getActiveGrantsForUser(100));
        $this->assertEquals(0, $this->countGrantsByStatus(100, 'revoked'));
    }

    public function testExclusiveMode_GrantCountAfterReplacements(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 3);

        $this->simulateGrant(100, 1, 'exclusive');
        $this->simulateGrant(100, 2, 'exclusive');
        $this->simulateGrant(100, 3, 'exclusive');

        $this->assertCount(1, $this->getActiveGrantsForUser(100));
        $this->assertEquals(2, $this->countGrantsByStatus(100, 'revoked'));
    }
}
