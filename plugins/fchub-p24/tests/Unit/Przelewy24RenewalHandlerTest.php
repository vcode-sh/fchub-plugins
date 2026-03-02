<?php

namespace FChubP24\Tests\Unit;

use FChubP24\Subscription\Przelewy24RenewalHandler;
use PHPUnit\Framework\TestCase;

class Przelewy24RenewalHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        global $_fchub_test_as_actions, $_fchub_test_wp_remote_request;
        $_fchub_test_as_actions = [];
        $_fchub_test_wp_remote_request = null;

        \FluentCart\App\Models\Subscription::$mockResult = null;
    }

    public function testScheduleRenewalCreatesActionSchedulerJob(): void
    {
        global $_fchub_test_as_actions;

        $timestamp = time() + 86400;
        Przelewy24RenewalHandler::scheduleRenewal(42, $timestamp);

        // First action is unschedule (to clear existing), second is schedule
        $schedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule');
        $this->assertNotEmpty($schedules);

        $action = array_values($schedules)[0];
        $this->assertSame('fchub_p24_process_renewal', $action['hook']);
        $this->assertSame([42], $action['args']);
        $this->assertSame($timestamp, $action['timestamp']);
        $this->assertSame('fchub-p24', $action['group']);
    }

    public function testScheduleRenewalUnschedulesExistingFirst(): void
    {
        global $_fchub_test_as_actions;

        Przelewy24RenewalHandler::scheduleRenewal(42, time() + 86400);

        $this->assertSame('unschedule', $_fchub_test_as_actions[0]['type']);
        $this->assertSame('schedule', $_fchub_test_as_actions[1]['type']);
    }

    public function testUnscheduleRenewalCallsActionScheduler(): void
    {
        global $_fchub_test_as_actions;

        Przelewy24RenewalHandler::unscheduleRenewal(42);

        $this->assertCount(1, $_fchub_test_as_actions);
        $this->assertSame('unschedule', $_fchub_test_as_actions[0]['type']);
        $this->assertSame([42], $_fchub_test_as_actions[0]['args']);
    }

    public function testProcessRenewalSkipsWhenSubscriptionNotFound(): void
    {
        global $_fchub_test_as_actions;

        \FluentCart\App\Models\Subscription::$mockResult = null;

        // Should not throw, just return silently
        Przelewy24RenewalHandler::processRenewal(999);

        // No scheduling should have happened
        $schedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule');
        $this->assertEmpty($schedules);
    }

    public function testProcessRenewalSkipsInvalidStatus(): void
    {
        global $_fchub_test_as_actions;

        $sub = $this->createMockSubscription(42);
        $sub->status = 'canceled';
        \FluentCart\App\Models\Subscription::$mockResult = $sub;

        Przelewy24RenewalHandler::processRenewal(42);

        $schedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule');
        $this->assertEmpty($schedules);
    }

    public function testProcessRenewalFailsWhenNoRefId(): void
    {
        $sub = $this->createMockSubscription(42);
        $sub->status = 'active';
        // No _p24_card_ref_id meta set
        \FluentCart\App\Models\Subscription::$mockResult = $sub;

        Przelewy24RenewalHandler::processRenewal(42);

        // Should have set retry count
        $this->assertSame(1, $sub->getMeta('_p24_retry_count'));
        $this->assertSame('failing', $sub->status);
    }

    public function testHandleRenewalFailureIncrementsRetryCount(): void
    {
        $sub = $this->createMockSubscription(42);
        $sub->status = 'active';

        Przelewy24RenewalHandler::handleRenewalFailure($sub, 'Test failure');

        $this->assertSame(1, $sub->getMeta('_p24_retry_count'));
        $this->assertSame('failing', $sub->status);
    }

    public function testHandleRenewalFailureSchedulesRetry(): void
    {
        global $_fchub_test_as_actions;

        $sub = $this->createMockSubscription(42);
        $sub->status = 'active';

        Przelewy24RenewalHandler::handleRenewalFailure($sub, 'Test failure');

        $schedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule');
        $this->assertNotEmpty($schedules);

        $action = array_values($schedules)[0];
        // First retry delay is 4 hours
        $expectedTime = time() + (4 * HOUR_IN_SECONDS);
        $this->assertEqualsWithDelta($expectedTime, $action['timestamp'], 5);
    }

    public function testHandleRenewalFailureExpiresAfterMaxRetries(): void
    {
        $sub = $this->createMockSubscription(42);
        $sub->status = 'failing';
        $sub->updateMeta('_p24_retry_count', Przelewy24RenewalHandler::MAX_RETRIES);

        Przelewy24RenewalHandler::handleRenewalFailure($sub, 'Final failure');

        $this->assertSame('expired', $sub->status);
        $this->assertSame(0, $sub->getMeta('_p24_retry_count'));
    }

    public function testHandleRenewalFailureRetryDelaysEscalate(): void
    {
        global $_fchub_test_as_actions;

        // First retry = 4h
        $sub1 = $this->createMockSubscription(42);
        $sub1->updateMeta('_p24_retry_count', 0);
        $_fchub_test_as_actions = [];
        Przelewy24RenewalHandler::handleRenewalFailure($sub1, 'fail');
        $schedules1 = array_values(array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule'));
        $delay1 = $schedules1[0]['timestamp'] - time();

        // Second retry = 24h
        $sub2 = $this->createMockSubscription(43);
        $sub2->updateMeta('_p24_retry_count', 1);
        $_fchub_test_as_actions = [];
        Przelewy24RenewalHandler::handleRenewalFailure($sub2, 'fail');
        $schedules2 = array_values(array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule'));
        $delay2 = $schedules2[0]['timestamp'] - time();

        // Third retry = 72h
        $sub3 = $this->createMockSubscription(44);
        $sub3->updateMeta('_p24_retry_count', 2);
        $_fchub_test_as_actions = [];
        Przelewy24RenewalHandler::handleRenewalFailure($sub3, 'fail');
        $schedules3 = array_values(array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule'));
        $delay3 = $schedules3[0]['timestamp'] - time();

        $this->assertEqualsWithDelta(4 * 3600, $delay1, 5);
        $this->assertEqualsWithDelta(24 * 3600, $delay2, 5);
        $this->assertEqualsWithDelta(72 * 3600, $delay3, 5);
    }

    public function testScheduleNextRenewalResetsRetryCount(): void
    {
        $sub = $this->createMockSubscription(42);
        $sub->updateMeta('_p24_retry_count', 2);

        Przelewy24RenewalHandler::scheduleNextRenewal($sub);

        $this->assertSame(0, $sub->getMeta('_p24_retry_count'));
    }

    public function testScheduleNextRenewalSchedulesFutureDate(): void
    {
        global $_fchub_test_as_actions;

        $sub = $this->createMockSubscription(42);

        Przelewy24RenewalHandler::scheduleNextRenewal($sub);

        $schedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule');
        $this->assertNotEmpty($schedules);
    }

    public function testConstantsAreDefined(): void
    {
        $this->assertSame('fchub_p24_process_renewal', Przelewy24RenewalHandler::ACTION_HOOK);
        $this->assertSame(3, Przelewy24RenewalHandler::MAX_RETRIES);
        $this->assertCount(3, Przelewy24RenewalHandler::RETRY_DELAYS);
    }

    private function createMockSubscription(int $id): \FluentCart\App\Models\Subscription
    {
        $sub = new \FluentCart\App\Models\Subscription();
        $sub->id = $id;
        $sub->status = 'active';
        $sub->recurring_total = 5000;
        $sub->currency = 'PLN';
        return $sub;
    }
}
