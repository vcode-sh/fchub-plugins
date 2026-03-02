<?php

namespace FChubMemberships\Tests\FluentCRM\Triggers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for MembershipAnniversaryTrigger logic.
 *
 * Simulates the anniversary check logic without database access,
 * mirroring the MembershipAnniversaryTrigger::checkAnniversaries() flow.
 */
class AnniversaryTriggerTest extends TestCase
{
    private array $grants = [];
    private array $firedActions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grants = [];
        $this->firedActions = [];
        $GLOBALS['wp_actions_fired'] = [];
    }

    // ---------------------------------------------------------------
    // Test 12: anniversary fires at milestone
    // ---------------------------------------------------------------
    public function test_anniversary_fires_at_milestone(): void
    {
        $createdAt = date('Y-m-d H:i:s', strtotime('-365 days'));

        $grant = $this->createGrant(1, 100, 1, 'active', $createdAt);

        $this->checkAnniversaries([365]);

        $this->assertCount(1, $this->firedActions);
        $this->assertEquals(365, $this->firedActions[0]['milestone']);
        $this->assertEquals(1, $this->firedActions[0]['grant_id']);
    }

    // ---------------------------------------------------------------
    // Test 13: anniversary skips non-milestone day
    // ---------------------------------------------------------------
    public function test_anniversary_skips_non_milestone_day(): void
    {
        $createdAt = date('Y-m-d H:i:s', strtotime('-100 days'));

        $grant = $this->createGrant(1, 100, 1, 'active', $createdAt);

        $this->checkAnniversaries([30, 60, 90, 180, 365, 730]);

        $this->assertCount(0, $this->firedActions);
    }

    // ---------------------------------------------------------------
    // Test 14: anniversary does not fire twice
    // ---------------------------------------------------------------
    public function test_anniversary_does_not_fire_twice(): void
    {
        $createdAt = date('Y-m-d H:i:s', strtotime('-365 days'));

        // Grant with milestone already tracked in meta
        $grant = $this->createGrant(1, 100, 1, 'active', $createdAt, [
            'anniversary_milestones_fired' => [365],
        ]);

        $this->checkAnniversaries([365]);

        $this->assertCount(0, $this->firedActions, 'Should not fire for already tracked milestone');
    }

    // ---------------------------------------------------------------
    // Test 15: anniversary respects plan filter
    // ---------------------------------------------------------------
    public function test_anniversary_respects_plan_filter(): void
    {
        $createdAt = date('Y-m-d H:i:s', strtotime('-90 days'));

        // Grant for plan 1
        $this->createGrant(1, 100, 1, 'active', $createdAt);
        // Grant for plan 2
        $this->createGrant(2, 200, 2, 'active', $createdAt);

        // Check with plan filter for plan 2 only
        $this->checkAnniversariesWithPlanFilter([90], [2]);

        $this->assertCount(1, $this->firedActions);
        $this->assertEquals(2, $this->firedActions[0]['grant_id']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createGrant(int $id, int $userId, int $planId, string $status, string $createdAt, array $meta = []): array
    {
        $grant = [
            'id'         => $id,
            'user_id'    => $userId,
            'plan_id'    => $planId,
            'status'     => $status,
            'created_at' => $createdAt,
            'meta'       => $meta,
        ];
        $this->grants[$id] = $grant;
        return $grant;
    }

    /**
     * Simulate the checkAnniversaries logic.
     */
    private function checkAnniversaries(array $milestones): void
    {
        foreach ($milestones as $days) {
            foreach ($this->grants as &$grant) {
                if ($grant['status'] !== 'active') {
                    continue;
                }

                $daysSinceCreated = (int) floor(
                    (time() - strtotime($grant['created_at'])) / DAY_IN_SECONDS
                );

                if ($daysSinceCreated !== $days) {
                    continue;
                }

                $firedMilestones = $grant['meta']['anniversary_milestones_fired'] ?? [];

                if (in_array($days, $firedMilestones, true)) {
                    continue;
                }

                $this->firedActions[] = [
                    'grant_id'  => $grant['id'],
                    'milestone' => $days,
                ];

                $firedMilestones[] = $days;
                $grant['meta']['anniversary_milestones_fired'] = $firedMilestones;
            }
            unset($grant);
        }
    }

    /**
     * Simulate checkAnniversaries with plan filter.
     */
    private function checkAnniversariesWithPlanFilter(array $milestones, array $planIds): void
    {
        foreach ($milestones as $days) {
            foreach ($this->grants as &$grant) {
                if ($grant['status'] !== 'active') {
                    continue;
                }

                if (!in_array($grant['plan_id'], $planIds, true)) {
                    continue;
                }

                $daysSinceCreated = (int) floor(
                    (time() - strtotime($grant['created_at'])) / DAY_IN_SECONDS
                );

                if ($daysSinceCreated !== $days) {
                    continue;
                }

                $firedMilestones = $grant['meta']['anniversary_milestones_fired'] ?? [];

                if (in_array($days, $firedMilestones, true)) {
                    continue;
                }

                $this->firedActions[] = [
                    'grant_id'  => $grant['id'],
                    'milestone' => $days,
                ];

                $firedMilestones[] = $days;
                $grant['meta']['anniversary_milestones_fired'] = $firedMilestones;
            }
            unset($grant);
        }
    }
}
