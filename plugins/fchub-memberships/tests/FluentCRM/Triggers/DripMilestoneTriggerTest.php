<?php

namespace FChubMemberships\Tests\FluentCRM\Triggers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for DripMilestoneTrigger logic.
 *
 * Simulates the drip milestone check from DripScheduleService::checkDripMilestones()
 * without database access.
 */
class DripMilestoneTriggerTest extends TestCase
{
    private array $grants = [];
    private array $firedActions = [];
    private array $notifications = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grants = [];
        $this->firedActions = [];
        $this->notifications = [];
        $GLOBALS['wp_actions_fired'] = [];
    }

    // ---------------------------------------------------------------
    // Test 16: milestone fires at threshold
    // ---------------------------------------------------------------
    public function test_milestone_fires_at_threshold(): void
    {
        $grant = $this->createGrant(1, 100, 1, 'active');

        // 3 of 4 sent = 75%
        $this->notifications[1] = [
            ['id' => 1, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 2, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 3, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 4, 'grant_id' => 1, 'status' => 'pending'],
        ];

        $this->checkDripMilestones([1]);

        $firedPercentages = array_column($this->firedActions, 'percentage');
        $this->assertContains(75, $firedPercentages, '75% milestone should fire');
        $this->assertContains(50, $firedPercentages, '50% milestone should also fire (passed threshold)');
        $this->assertContains(25, $firedPercentages, '25% milestone should also fire (passed threshold)');
        $this->assertNotContains(100, $firedPercentages, '100% milestone should not fire yet');
    }

    // ---------------------------------------------------------------
    // Test 17: milestone does not fire between thresholds
    // ---------------------------------------------------------------
    public function test_milestone_does_not_fire_between_thresholds(): void
    {
        $grant = $this->createGrant(1, 100, 1, 'active');

        // 3 of 5 sent = 60% (between 50 and 75)
        $this->notifications[1] = [
            ['id' => 1, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 2, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 3, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 4, 'grant_id' => 1, 'status' => 'pending'],
            ['id' => 5, 'grant_id' => 1, 'status' => 'pending'],
        ];

        $this->checkDripMilestones([1]);

        $firedPercentages = array_column($this->firedActions, 'percentage');
        // 60% passes 25 and 50 but NOT 75
        $this->assertContains(25, $firedPercentages);
        $this->assertContains(50, $firedPercentages);
        $this->assertNotContains(75, $firedPercentages, '75% milestone should not fire at 60%');
        $this->assertNotContains(100, $firedPercentages);
    }

    // ---------------------------------------------------------------
    // Test 18: milestone does not fire twice
    // ---------------------------------------------------------------
    public function test_milestone_does_not_fire_twice(): void
    {
        // Grant with 50 milestone already tracked
        $grant = $this->createGrant(1, 100, 1, 'active', [
            'drip_milestones_fired' => [25, 50],
        ]);

        // 3 of 4 sent = 75%
        $this->notifications[1] = [
            ['id' => 1, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 2, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 3, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 4, 'grant_id' => 1, 'status' => 'pending'],
        ];

        $this->checkDripMilestones([1]);

        $firedPercentages = array_column($this->firedActions, 'percentage');
        // Only 75 should fire (25 and 50 already tracked)
        $this->assertContains(75, $firedPercentages);
        $this->assertNotContains(25, $firedPercentages, '25% was already fired');
        $this->assertNotContains(50, $firedPercentages, '50% was already fired');
    }

    // ---------------------------------------------------------------
    // Test 19: milestone fires 100 when all complete
    // ---------------------------------------------------------------
    public function test_milestone_fires_100_when_all_complete(): void
    {
        $grant = $this->createGrant(1, 100, 1, 'active');

        // All 4 sent = 100%
        $this->notifications[1] = [
            ['id' => 1, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 2, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 3, 'grant_id' => 1, 'status' => 'sent'],
            ['id' => 4, 'grant_id' => 1, 'status' => 'sent'],
        ];

        $this->checkDripMilestones([1]);

        $firedPercentages = array_column($this->firedActions, 'percentage');
        $this->assertContains(100, $firedPercentages, '100% milestone should fire');
        $this->assertContains(75, $firedPercentages);
        $this->assertContains(50, $firedPercentages);
        $this->assertContains(25, $firedPercentages);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createGrant(int $id, int $userId, int $planId, string $status, array $meta = []): array
    {
        $grant = [
            'id'      => $id,
            'user_id' => $userId,
            'plan_id' => $planId,
            'status'  => $status,
            'meta'    => $meta,
        ];
        $this->grants[$id] = $grant;
        return $grant;
    }

    /**
     * Simulate the checkDripMilestones logic from DripScheduleService.
     */
    private function checkDripMilestones(array $grantIds): void
    {
        $milestones = [25, 50, 75, 100];

        foreach ($grantIds as $grantId) {
            $grant = $this->grants[$grantId] ?? null;
            if (!$grant || $grant['status'] !== 'active') {
                continue;
            }

            $allNotifications = $this->notifications[$grantId] ?? [];
            if (empty($allNotifications)) {
                continue;
            }

            $total = count($allNotifications);
            $sent = count(array_filter($allNotifications, fn($n) => $n['status'] === 'sent'));
            $percentage = (int) round(($sent / $total) * 100);

            $firedMilestones = $grant['meta']['drip_milestones_fired'] ?? [];

            foreach ($milestones as $milestone) {
                if ($percentage >= $milestone && !in_array($milestone, $firedMilestones, true)) {
                    $this->firedActions[] = [
                        'grant_id'   => $grantId,
                        'percentage' => $milestone,
                        'user_id'    => $grant['user_id'],
                    ];

                    $firedMilestones[] = $milestone;
                }
            }

            // Update meta
            $this->grants[$grantId]['meta']['drip_milestones_fired'] = $firedMilestones;
        }
    }
}
