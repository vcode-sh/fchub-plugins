<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\SubscriptionGrantLifecycleService;
use FChubMemberships\Domain\SubscriptionValidityWatcher;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * Bug hunt tests for SubscriptionValidityWatcher hook registration and dispatch.
 *
 * Bug J: Hook name used 'cancelled' (double L) — FluentCart fires 'canceled' (single L).
 * Bug K: Status changed + individual hooks caused double-fire for cancel/pause/resume.
 *
 * Since SubscriptionGrantLifecycleService is final, we test by injecting tracking
 * dependencies (AccessGrantService + GrantRepository) and verifying downstream calls.
 */
final class SubscriptionWatcherBugHuntTest extends PluginTestCase
{
    // --- Bug J: hook spelling tests ---

    /**
     * Bug J: Verify the canceled hook uses single-L spelling matching FluentCart's event.
     */
    public function test_registers_canceled_hook_with_single_l(): void
    {
        $watcher = $this->createWatcher();
        $watcher->registerHooks();

        $hooks = array_keys($GLOBALS['_fchub_test_actions']);

        self::assertContains('fluent_cart/subscription_canceled', $hooks);
        self::assertNotContains('fluent_cart/subscription_cancelled', $hooks);
    }

    /**
     * Bug J: Verify onSubscriptionCancelled fires cancel flow when FluentCart
     * dispatches the event with single-L spelling.
     */
    public function test_canceled_event_triggers_cancel_flow(): void
    {
        $grantService = $this->createTrackingGrantService();
        $grantRepo = $this->createGrantRepoWithGrants(42, [
            ['id' => 1, 'user_id' => 10, 'plan_id' => 3, 'status' => 'active', 'source_type' => 'subscription', 'source_ids' => [42], 'meta' => []],
        ]);

        $watcher = $this->createWatcher($grantService, $grantRepo);
        $watcher->registerHooks();

        do_action('fluent_cart/subscription_canceled', [
            'subscription' => (object) ['id' => 42],
            'order' => null,
            'customer' => null,
        ]);

        // cancel() calls revokePlan() which we can verify was called
        self::assertNotEmpty($grantService->revokePlanCalls, 'cancel flow should trigger revokePlan');
        self::assertSame(10, $grantService->revokePlanCalls[0]['user_id']);
        self::assertSame(3, $grantService->revokePlanCalls[0]['plan_id']);
    }

    // --- Bug K: double-fire prevention tests ---

    /**
     * Bug K: Status changed only handles 'expired' — cancel, paused, active are excluded.
     */
    public function test_status_changed_ignores_cancel_pause_active(): void
    {
        $grantService = $this->createTrackingGrantService();
        $grantRepo = $this->createEmptyGrantRepo();

        $watcher = $this->createWatcher($grantService, $grantRepo);
        $watcher->registerHooks();

        $subscription = (object) ['id' => 10];

        // Fire status_changed with statuses that have individual hooks
        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => $subscription,
            'new_status' => 'canceled',
            'old_status' => 'active',
        ]);

        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => $subscription,
            'new_status' => 'paused',
            'old_status' => 'active',
        ]);

        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => $subscription,
            'new_status' => 'active',
            'old_status' => 'paused',
        ]);

        // None of these should trigger any lifecycle calls
        self::assertEmpty($grantService->revokePlanCalls, 'status_changed should not handle canceled');
        self::assertEmpty($grantService->pausedIds, 'status_changed should not handle paused');
        self::assertEmpty($grantService->resumedIds, 'status_changed should not handle active');
    }

    /**
     * Bug K: Verify expired IS handled by status_changed (no individual hook for it).
     */
    public function test_status_changed_handles_expired(): void
    {
        $watcher = $this->createWatcher();
        $watcher->registerHooks();

        $subscription = (object) ['id' => 99];

        // expired calls handleSubscriptionExpired -> dispatchExpiration -> validityChecks
        // Just verify no crash — the actual handling is in SubscriptionValidityCheckService
        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => $subscription,
            'new_status' => 'expired',
            'old_status' => 'active',
        ]);

        self::assertTrue(true, 'expired status handled without error');
    }

    /**
     * Bug K: Cancel fires only once when both status_changed and individual hook fire.
     */
    public function test_cancel_fires_only_once(): void
    {
        $grantService = $this->createTrackingGrantService();
        $grantRepo = $this->createGrantRepoWithGrants(77, [
            ['id' => 1, 'user_id' => 10, 'plan_id' => 3, 'status' => 'active', 'source_type' => 'subscription', 'source_ids' => [77], 'meta' => []],
        ]);

        $watcher = $this->createWatcher($grantService, $grantRepo);
        $watcher->registerHooks();

        $subscription = (object) ['id' => 77];

        // Simulate FluentCart firing both hooks
        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => $subscription,
            'new_status' => 'canceled',
            'old_status' => 'active',
        ]);

        do_action('fluent_cart/subscription_canceled', [
            'subscription' => $subscription,
            'order' => null,
            'customer' => null,
        ]);

        // Cancel should fire exactly once (from the individual hook only)
        self::assertCount(1, $grantService->revokePlanCalls);
    }

    // --- Hook registration tests ---

    /**
     * Verify paused hook uses the /payments/ prefix (FluentCart dynamic hook).
     */
    public function test_registers_paused_hook_with_payments_prefix(): void
    {
        $watcher = $this->createWatcher();
        $watcher->registerHooks();

        $hooks = array_keys($GLOBALS['_fchub_test_actions']);

        self::assertContains('fluent_cart/payments/subscription_paused', $hooks);
        self::assertNotContains('fluent_cart/subscription_paused', $hooks);
    }

    /**
     * Verify resumed hook maps to /payments/subscription_active.
     */
    public function test_registers_active_hook_for_resume(): void
    {
        $watcher = $this->createWatcher();
        $watcher->registerHooks();

        $hooks = array_keys($GLOBALS['_fchub_test_actions']);

        self::assertContains('fluent_cart/payments/subscription_active', $hooks);
        self::assertNotContains('fluent_cart/subscription_resumed', $hooks);
    }

    /**
     * Verify status_changed uses /payments/ prefix matching FluentCart.
     */
    public function test_registers_status_changed_with_payments_prefix(): void
    {
        $watcher = $this->createWatcher();
        $watcher->registerHooks();

        $hooks = array_keys($GLOBALS['_fchub_test_actions']);

        self::assertContains('fluent_cart/payments/subscription_status_changed', $hooks);
        self::assertNotContains('fluent_cart/subscription_status_changed', $hooks);
    }

    // --- Handler dispatch tests ---

    /**
     * Verify paused hook fires pause handler.
     */
    public function test_paused_hook_triggers_pause_handler(): void
    {
        $grantService = $this->createTrackingGrantService();
        $grantRepo = $this->createGrantRepoWithGrants(33, [
            ['id' => 1, 'user_id' => 10, 'plan_id' => 3, 'status' => 'active', 'source_type' => 'subscription', 'source_ids' => [33], 'meta' => []],
        ]);

        $watcher = $this->createWatcher($grantService, $grantRepo);
        $watcher->registerHooks();

        do_action('fluent_cart/payments/subscription_paused', [
            'subscription' => (object) ['id' => 33],
        ]);

        self::assertSame([1], $grantService->pausedIds);
    }

    /**
     * Verify active hook triggers resume handler.
     */
    public function test_active_hook_triggers_resume_handler(): void
    {
        $grantService = $this->createTrackingGrantService();
        $grantRepo = $this->createGrantRepoWithGrants(55, [
            ['id' => 2, 'user_id' => 10, 'plan_id' => 3, 'status' => 'paused', 'source_type' => 'subscription', 'source_ids' => [55], 'meta' => []],
        ]);

        $watcher = $this->createWatcher($grantService, $grantRepo);
        $watcher->registerHooks();

        do_action('fluent_cart/payments/subscription_active', [
            'subscription' => (object) ['id' => 55],
        ]);

        self::assertSame([2], $grantService->resumedIds);
    }

    /**
     * Verify renewed hook still works (event-based, no prefix).
     */
    public function test_renewed_hook_triggers_renew_handler(): void
    {
        $grantService = $this->createTrackingGrantService();
        $grantRepo = $this->createGrantRepoWithGrants(88, [
            ['user_id' => 10, 'plan_id' => 3, 'status' => 'active', 'source_type' => 'subscription', 'source_ids' => [88], 'meta' => []],
        ]);

        $watcher = $this->createWatcher($grantService, $grantRepo);
        $watcher->registerHooks();

        do_action('fluent_cart/subscription_renewed', [
            'subscription' => (object) ['id' => 88, 'next_billing_date' => '2026-05-01 00:00:00'],
        ]);

        // renew extends active grants
        self::assertNotEmpty($grantService->extendedExpiry, 'renew should extend active grant');
    }

    // --- Edge case tests ---

    /**
     * Status changed with null subscription is silently ignored.
     */
    public function test_status_changed_with_null_subscription_is_ignored(): void
    {
        $watcher = $this->createWatcher();
        $watcher->registerHooks();

        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => null,
            'new_status' => 'expired',
        ]);

        self::assertTrue(true);
    }

    /**
     * Status changed with unknown status is silently ignored.
     */
    public function test_status_changed_with_unknown_status_is_ignored(): void
    {
        $grantService = $this->createTrackingGrantService();
        $watcher = $this->createWatcher($grantService);
        $watcher->registerHooks();

        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => (object) ['id' => 1],
            'new_status' => 'trialing',
        ]);

        self::assertEmpty($grantService->pausedIds);
        self::assertEmpty($grantService->resumedIds);
        self::assertEmpty($grantService->revokePlanCalls);
    }

    /**
     * Handlers gracefully handle data without subscription key.
     */
    public function test_handlers_ignore_data_without_subscription(): void
    {
        $grantService = $this->createTrackingGrantService();
        $watcher = $this->createWatcher($grantService);
        $watcher->registerHooks();

        do_action('fluent_cart/subscription_canceled', ['order' => null]);
        do_action('fluent_cart/payments/subscription_paused', []);
        do_action('fluent_cart/payments/subscription_active', ['something' => 'else']);

        self::assertEmpty($grantService->revokePlanCalls);
        self::assertEmpty($grantService->pausedIds);
        self::assertEmpty($grantService->resumedIds);
    }

    // --- helpers ---

    private function createWatcher(?object $grantService = null, ?GrantRepository $grantRepo = null): SubscriptionValidityWatcher
    {
        $grantService = $grantService ?? $this->createTrackingGrantService();
        $grantRepo = $grantRepo ?? $this->createEmptyGrantRepo();

        $lifecycle = new SubscriptionGrantLifecycleService($grantService, $grantRepo);

        return new SubscriptionValidityWatcher($lifecycle, $grantRepo);
    }

    private function createTrackingGrantService(): object
    {
        return new class() extends AccessGrantService {
            /** @var int[] */
            public array $pausedIds = [];
            /** @var int[] */
            public array $resumedIds = [];
            /** @var array[] */
            public array $revokePlanCalls = [];
            /** @var array[] */
            public array $extendedExpiry = [];

            public function __construct() {}

            public function pauseGrant(int $grantId, string $reason = ''): array
            {
                $this->pausedIds[] = $grantId;
                return ['success' => true];
            }

            public function resumeGrant(int $grantId): array
            {
                $this->resumedIds[] = $grantId;
                return ['success' => true];
            }

            public function revokePlan(int $userId, int $planId, array $context = []): array
            {
                $this->revokePlanCalls[] = ['user_id' => $userId, 'plan_id' => $planId, 'context' => $context];
                return ['revoked' => 1, 'retained' => 0];
            }

            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extendedExpiry[] = ['user_id' => $userId, 'plan_id' => $planId, 'expires_at' => $newExpiresAt];
                return 1;
            }
        };
    }

    private function createEmptyGrantRepo(): GrantRepository
    {
        return new class() extends GrantRepository {
            public function __construct() {}

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [];
            }
        };
    }

    private function createGrantRepoWithGrants(int $subscriptionId, array $grants): GrantRepository
    {
        return new class($subscriptionId, $grants) extends GrantRepository {
            public function __construct(private int $subscriptionId, private array $grants) {}

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return $sourceId === $this->subscriptionId ? $this->grants : [];
            }
        };
    }
}
