<?php

namespace FChubMemberships\Tests\FluentCRM\Triggers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for TrialExpiringSoonTrigger condition logic.
 *
 * Simulates the days-range and plan filter logic without requiring
 * FluentCRM or WordPress database.
 */
class TrialExpiringSoonTriggerTest extends TestCase
{
    /** @var array Simulated plans */
    private array $plans = [];

    /** @var array Tracks fired actions */
    private array $firedActions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->plans = [];
        $this->firedActions = [];
        $GLOBALS['wp_options'] = [];
        $GLOBALS['wp_actions_fired'] = [];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createPlan(int $id, string $title): array
    {
        $plan = [
            'id'         => $id,
            'title'      => $title,
            'slug'       => strtolower(str_replace(' ', '-', $title)),
            'trial_days' => 14,
        ];
        $this->plans[$id] = $plan;
        return $plan;
    }

    private function makeGrant(int $userId, int $planId, string $trialEndsAt): array
    {
        return [
            'id'            => 1,
            'user_id'       => $userId,
            'plan_id'       => $planId,
            'trial_ends_at' => $trialEndsAt,
            'meta'          => [],
        ];
    }

    /**
     * Simulate the isProcessable logic from TrialExpiringSoonTrigger.
     */
    private function simulateIsProcessable(array $conditions, int $planId, int $daysLeft): bool
    {
        // Plan filter
        $checkIds = $conditions['plan_ids'] ?? [];
        if (!empty($checkIds)) {
            if (!in_array($planId, $checkIds)) {
                return false;
            }
        }

        // Days range filter
        $minDays = $conditions['min_days_left'] ?? '';
        if ($minDays !== '' && $daysLeft < (int) $minDays) {
            return false;
        }

        $maxDays = $conditions['max_days_left'] ?? '';
        if ($maxDays !== '' && $daysLeft > (int) $maxDays) {
            return false;
        }

        return true;
    }

    /**
     * Simulate TrialLifecycleService::sendTrialExpiringNotifications() hook firing.
     */
    private function simulateTrialLifecycleCron(array $grants, int $noticeDays = 3): void
    {
        $now = time();
        $cutoff = $now + ($noticeDays * DAY_IN_SECONDS);

        foreach ($grants as $grant) {
            $trialEndsTs = strtotime($grant['trial_ends_at']);

            // Only process grants whose trial ends between now and cutoff
            if ($trialEndsTs <= $now || $trialEndsTs > $cutoff) {
                continue;
            }

            $daysLeft = max(0, (int) ceil(($trialEndsTs - $now) / DAY_IN_SECONDS));

            $grantArray = [
                'id'            => $grant['id'],
                'user_id'       => $grant['user_id'],
                'plan_id'       => $grant['plan_id'],
                'trial_ends_at' => $grant['trial_ends_at'],
                'meta'          => $grant['meta'] ?? [],
            ];

            // Simulate do_action
            $this->firedActions[] = [
                'tag'  => 'fchub_memberships/trial_expiring_soon',
                'args' => [$grantArray, $daysLeft],
            ];
        }
    }

    // ===================================================================
    // DAYS RANGE TESTS
    // ===================================================================

    public function test_trigger_fires_within_days_range(): void
    {
        $conditions = [
            'plan_ids'      => [],
            'min_days_left' => '',
            'max_days_left' => '5',
        ];

        $result = $this->simulateIsProcessable($conditions, 10, 3);
        $this->assertTrue($result, 'Trigger should fire when 3 days left and max is 5');
    }

    public function test_trigger_skips_outside_days_range(): void
    {
        $conditions = [
            'plan_ids'      => [],
            'min_days_left' => '',
            'max_days_left' => '5',
        ];

        $result = $this->simulateIsProcessable($conditions, 10, 10);
        $this->assertFalse($result, 'Trigger should skip when 10 days left and max is 5');
    }

    public function test_trigger_fires_at_exact_max_boundary(): void
    {
        $conditions = [
            'plan_ids'      => [],
            'min_days_left' => '',
            'max_days_left' => '5',
        ];

        $result = $this->simulateIsProcessable($conditions, 10, 5);
        $this->assertTrue($result, 'Trigger should fire at exact max boundary');
    }

    public function test_trigger_fires_at_exact_min_boundary(): void
    {
        $conditions = [
            'plan_ids'      => [],
            'min_days_left' => '2',
            'max_days_left' => '5',
        ];

        $result = $this->simulateIsProcessable($conditions, 10, 2);
        $this->assertTrue($result, 'Trigger should fire at exact min boundary');
    }

    public function test_trigger_skips_below_min_days(): void
    {
        $conditions = [
            'plan_ids'      => [],
            'min_days_left' => '3',
            'max_days_left' => '',
        ];

        $result = $this->simulateIsProcessable($conditions, 10, 1);
        $this->assertFalse($result, 'Trigger should skip when days left is below minimum');
    }

    public function test_trigger_fires_when_no_days_range_set(): void
    {
        $conditions = [
            'plan_ids'      => [],
            'min_days_left' => '',
            'max_days_left' => '',
        ];

        $result = $this->simulateIsProcessable($conditions, 10, 100);
        $this->assertTrue($result, 'Trigger should fire when no days range is configured');
    }

    // ===================================================================
    // PLAN FILTER TESTS
    // ===================================================================

    public function test_trigger_respects_plan_filter(): void
    {
        $conditions = [
            'plan_ids'      => [10, 20],
            'min_days_left' => '',
            'max_days_left' => '7',
        ];

        $this->assertTrue(
            $this->simulateIsProcessable($conditions, 10, 3),
            'Should fire for plan 10 (in filter)'
        );

        $this->assertFalse(
            $this->simulateIsProcessable($conditions, 30, 3),
            'Should not fire for plan 30 (not in filter)'
        );
    }

    public function test_trigger_fires_for_any_plan_when_no_filter(): void
    {
        $conditions = [
            'plan_ids'      => [],
            'min_days_left' => '',
            'max_days_left' => '7',
        ];

        $this->assertTrue(
            $this->simulateIsProcessable($conditions, 999, 3),
            'Should fire for any plan when no plan filter is set'
        );
    }

    // ===================================================================
    // LIFECYCLE CRON HOOK TEST
    // ===================================================================

    public function test_hook_fires_from_trial_lifecycle_cron(): void
    {
        $this->createPlan(10, 'Pro Plan');

        // Grant with trial ending in 2 days
        $trialEndsAt = gmdate('Y-m-d H:i:s', strtotime('+2 days'));
        $grant = $this->makeGrant(100, 10, $trialEndsAt);

        $this->simulateTrialLifecycleCron([$grant], 3);

        $this->assertCount(1, $this->firedActions, 'One action should be fired');
        $this->assertEquals(
            'fchub_memberships/trial_expiring_soon',
            $this->firedActions[0]['tag']
        );

        $firedGrant = $this->firedActions[0]['args'][0];
        $firedDaysLeft = $this->firedActions[0]['args'][1];

        $this->assertEquals(100, $firedGrant['user_id']);
        $this->assertEquals(10, $firedGrant['plan_id']);
        $this->assertGreaterThanOrEqual(1, $firedDaysLeft);
        $this->assertLessThanOrEqual(3, $firedDaysLeft);
    }

    public function test_hook_does_not_fire_for_trial_outside_notice_window(): void
    {
        $this->createPlan(10, 'Pro Plan');

        // Grant with trial ending in 10 days (outside 3-day notice window)
        $trialEndsAt = gmdate('Y-m-d H:i:s', strtotime('+10 days'));
        $grant = $this->makeGrant(100, 10, $trialEndsAt);

        $this->simulateTrialLifecycleCron([$grant], 3);

        $this->assertCount(0, $this->firedActions, 'No action should fire for trial outside notice window');
    }

    public function test_hook_does_not_fire_for_already_expired_trial(): void
    {
        $this->createPlan(10, 'Pro Plan');

        // Grant with trial already ended
        $trialEndsAt = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
        $grant = $this->makeGrant(100, 10, $trialEndsAt);

        $this->simulateTrialLifecycleCron([$grant], 3);

        $this->assertCount(0, $this->firedActions, 'No action should fire for already expired trial');
    }
}
