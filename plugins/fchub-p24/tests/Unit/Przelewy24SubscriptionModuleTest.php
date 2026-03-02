<?php

namespace FChubP24\Tests\Unit;

use FChubP24\Subscription\Przelewy24SubscriptionModule;
use PHPUnit\Framework\TestCase;

class Przelewy24SubscriptionModuleTest extends TestCase
{
    private Przelewy24SubscriptionModule $module;

    protected function setUp(): void
    {
        global $_fchub_test_as_actions;
        $_fchub_test_as_actions = [];

        $this->module = new Przelewy24SubscriptionModule();
    }

    public function testCancelReturnsCorrectStatus(): void
    {
        $result = $this->module->cancel('p24_sub_42');

        $this->assertSame('canceled', $result['status']);
        $this->assertArrayHasKey('canceled_at', $result);
    }

    public function testCancelUnschedulesRenewal(): void
    {
        global $_fchub_test_as_actions;

        $this->module->cancel('p24_sub_42');

        $unschedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'unschedule');
        $this->assertNotEmpty($unschedules);

        $action = array_values($unschedules)[0];
        $this->assertSame('fchub_p24_process_renewal', $action['hook']);
        $this->assertSame([42], $action['args']);
    }

    public function testCancelSubscriptionUnschedulesRenewal(): void
    {
        global $_fchub_test_as_actions;

        $subscription = $this->createMockSubscription(10);
        $result = $this->module->cancelSubscription([], null, $subscription);

        $this->assertSame('canceled', $result['status']);

        $unschedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'unschedule');
        $action = array_values($unschedules)[0];
        $this->assertSame([10], $action['args']);
    }

    public function testPauseSubscriptionUnschedulesAndReturnsPaused(): void
    {
        global $_fchub_test_as_actions;

        $subscription = $this->createMockSubscription(15);
        $result = $this->module->pauseSubscription([], null, $subscription);

        $this->assertSame('paused', $result['status']);

        $unschedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'unschedule');
        $this->assertNotEmpty($unschedules);
    }

    public function testResumeSubscriptionSchedulesAndReturnsActive(): void
    {
        global $_fchub_test_as_actions;

        $subscription = $this->createMockSubscription(20);
        $result = $this->module->resumeSubscription([], null, $subscription);

        $this->assertSame('active', $result['status']);

        $schedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule');
        $this->assertNotEmpty($schedules);

        $action = array_values($schedules)[0];
        $this->assertSame('fchub_p24_process_renewal', $action['hook']);
        $this->assertSame([20], $action['args']);
    }

    public function testCancelAutoRenewUnschedules(): void
    {
        global $_fchub_test_as_actions;

        $subscription = $this->createMockSubscription(25);
        $this->module->cancelAutoRenew($subscription);

        $unschedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'unschedule');
        $this->assertNotEmpty($unschedules);
    }

    public function testCancelOnPlanChangeUnschedules(): void
    {
        global $_fchub_test_as_actions;

        $this->module->cancelOnPlanChange('p24_sub_30', 1, 30, 'plan change');

        $unschedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'unschedule');
        $this->assertNotEmpty($unschedules);

        $action = array_values($unschedules)[0];
        $this->assertSame([30], $action['args']);
    }

    public function testReSyncReturnsModelUnchanged(): void
    {
        $subscription = $this->createMockSubscription(35);
        $subscription->status = 'active';

        $result = $this->module->reSyncSubscriptionFromRemote($subscription);

        $this->assertSame($subscription, $result);
        $this->assertSame('active', $result->status);
    }

    public function testCardUpdateThrowsException(): void
    {
        $this->expectException(\Exception::class);

        $this->module->cardUpdate([], 42);
    }

    public function testCancelExtractsIdFromVendorFormat(): void
    {
        global $_fchub_test_as_actions;

        $this->module->cancel('p24_sub_99');

        $unschedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'unschedule');
        $action = array_values($unschedules)[0];
        $this->assertSame([99], $action['args']);
    }

    public function testCancelHandlesNumericVendorId(): void
    {
        global $_fchub_test_as_actions;

        $this->module->cancel('123');

        $unschedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'unschedule');
        $action = array_values($unschedules)[0];
        $this->assertSame([123], $action['args']);
    }

    private function createMockSubscription(int $id): object
    {
        $sub = new \FluentCart\App\Models\Subscription();
        $sub->id = $id;
        $sub->status = 'active';
        $sub->recurring_total = 5000;
        $sub->currency = 'PLN';
        return $sub;
    }
}
