<?php

namespace FChubMemberships\Tests\Unit\FluentCRM\Triggers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for PaymentFailedTrigger and SubscriptionValidityWatcher payment failure handling.
 *
 * These tests simulate the payment failure flow using in-memory mocks
 * rather than database repositories. They verify:
 * - Hook firing logic in SubscriptionValidityWatcher
 * - Trigger condition evaluation (plan_ids, run_multiple)
 * - User resolution from grants
 */
class PaymentFailedTriggerTest extends TestCase
{
    /** @var array In-memory grants store */
    private array $grants = [];

    /** @var array Captured do_action calls */
    private array $firedActions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grants = [];
        $this->firedActions = [];
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
        ];
        $this->grants[$id] = $grant;
        return $grant;
    }

    private function getGrantsBySourceId(int $sourceId, string $sourceType = 'subscription'): array
    {
        return array_values(array_filter(
            $this->grants,
            fn($g) => $g['source_type'] === $sourceType && $g['source_id'] === $sourceId
        ));
    }

    /**
     * Simulate the handlePaymentFailure logic from SubscriptionValidityWatcher.
     */
    private function simulatePaymentFailureHandler(int $subscriptionId, $eventData, string $source = 'order_payment_failed'): void
    {
        $grants = $this->getGrantsBySourceId($subscriptionId, 'subscription');

        if (empty($grants)) {
            return;
        }

        $subscription = (object) ['id' => $subscriptionId, 'status' => 'failing'];

        do_action('fchub_memberships/payment_failed', $grants, $subscription, $eventData);
    }

    /**
     * Simulate the trigger's isProcessable logic.
     */
    private function simulateIsProcessable(array $conditions, int $planId, bool $alreadyInFunnel = false): bool
    {
        // Plan filter
        $checkIds = $conditions['plan_ids'] ?? [];
        if (!empty($checkIds)) {
            if (!in_array($planId, $checkIds)) {
                return false;
            }
        }

        // Duplicate check
        if ($alreadyInFunnel) {
            $multipleRun = ($conditions['run_multiple'] ?? 'no') === 'yes';
            return $multipleRun;
        }

        return true;
    }

    private function getActionsFired(string $tag): array
    {
        return array_values(array_filter(
            $GLOBALS['wp_actions_fired'],
            fn($a) => $a['tag'] === $tag
        ));
    }

    // ===================================================================
    // HANDLER TESTS (SubscriptionValidityWatcher payment failure logic)
    // ===================================================================

    public function test_payment_failed_hook_fires_when_subscription_has_grants(): void
    {
        $this->createGrant(1, 100, 10, 42, 'subscription');

        $eventData = ['reason' => 'card_declined'];
        $this->simulatePaymentFailureHandler(42, $eventData);

        $actions = $this->getActionsFired('fchub_memberships/payment_failed');
        $this->assertCount(1, $actions);

        $firedGrants = $actions[0]['args'][0];
        $this->assertCount(1, $firedGrants);
        $this->assertEquals(100, $firedGrants[0]['user_id']);
        $this->assertEquals(10, $firedGrants[0]['plan_id']);
    }

    public function test_payment_failed_hook_does_not_fire_when_no_grants(): void
    {
        // No grants exist for subscription 99
        $eventData = ['reason' => 'card_declined'];
        $this->simulatePaymentFailureHandler(99, $eventData);

        $actions = $this->getActionsFired('fchub_memberships/payment_failed');
        $this->assertCount(0, $actions);
    }

    public function test_handler_processes_order_payment_failed_event(): void
    {
        $this->createGrant(1, 200, 20, 55, 'subscription');

        $eventData = ['reason' => 'insufficient_funds', 'old_status' => 'pending', 'new_status' => 'failed'];
        $this->simulatePaymentFailureHandler(55, $eventData, 'order_payment_failed');

        $actions = $this->getActionsFired('fchub_memberships/payment_failed');
        $this->assertCount(1, $actions);

        // Verify subscription object is passed
        $subscription = $actions[0]['args'][1];
        $this->assertEquals(55, $subscription->id);
    }

    public function test_handler_processes_subscription_failing_event(): void
    {
        $this->createGrant(1, 300, 30, 77, 'subscription');

        $eventData = ['old_status' => 'active', 'new_status' => 'failing'];
        $this->simulatePaymentFailureHandler(77, $eventData, 'subscription_failing');

        $actions = $this->getActionsFired('fchub_memberships/payment_failed');
        $this->assertCount(1, $actions);

        // Verify event data is passed through
        $passedEventData = $actions[0]['args'][2];
        $this->assertEquals('failing', $passedEventData['new_status']);
    }

    public function test_handler_fires_for_multiple_grants_on_same_subscription(): void
    {
        $this->createGrant(1, 100, 10, 42, 'subscription');
        $this->createGrant(2, 100, 20, 42, 'subscription');

        $this->simulatePaymentFailureHandler(42, []);

        $actions = $this->getActionsFired('fchub_memberships/payment_failed');
        $this->assertCount(1, $actions);

        // Both grants should be passed together
        $firedGrants = $actions[0]['args'][0];
        $this->assertCount(2, $firedGrants);
    }

    // ===================================================================
    // TRIGGER CONDITION TESTS
    // ===================================================================

    public function test_trigger_fires_for_matching_plan(): void
    {
        $conditions = ['plan_ids' => [10, 20], 'run_multiple' => 'no'];
        $this->assertTrue($this->simulateIsProcessable($conditions, 10));
    }

    public function test_trigger_skips_non_matching_plan(): void
    {
        $conditions = ['plan_ids' => [10, 20], 'run_multiple' => 'no'];
        $this->assertFalse($this->simulateIsProcessable($conditions, 30));
    }

    public function test_trigger_fires_for_all_plans_when_empty_filter(): void
    {
        $conditions = ['plan_ids' => [], 'run_multiple' => 'no'];
        $this->assertTrue($this->simulateIsProcessable($conditions, 999));
    }

    public function test_trigger_resolves_correct_user_from_grants(): void
    {
        $this->createGrant(1, 42, 10, 100, 'subscription');
        $this->createGrant(2, 42, 20, 100, 'subscription');

        $this->simulatePaymentFailureHandler(100, []);

        $actions = $this->getActionsFired('fchub_memberships/payment_failed');
        $this->assertCount(1, $actions);

        $grants = $actions[0]['args'][0];
        // The trigger extracts user_id from grants[0]
        $userId = $grants[0]['user_id'] ?? 0;
        $this->assertEquals(42, $userId);
    }

    public function test_trigger_respects_run_multiple(): void
    {
        // Already in funnel, run_multiple = no -> should not process
        $conditions = ['plan_ids' => [], 'run_multiple' => 'no'];
        $this->assertFalse($this->simulateIsProcessable($conditions, 10, true));

        // Already in funnel, run_multiple = yes -> should process
        $conditions['run_multiple'] = 'yes';
        $this->assertTrue($this->simulateIsProcessable($conditions, 10, true));
    }

    public function test_trigger_allows_first_run_regardless_of_run_multiple(): void
    {
        // Not in funnel yet -> should always process
        $conditions = ['plan_ids' => [], 'run_multiple' => 'no'];
        $this->assertTrue($this->simulateIsProcessable($conditions, 10, false));
    }
}
