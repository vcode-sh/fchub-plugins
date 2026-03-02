<?php

namespace FChubP24\Tests\Unit;

use FChubP24\Gateway\Przelewy24Gateway;
use PHPUnit\Framework\TestCase;

class Przelewy24GatewayCardInfoTest extends TestCase
{
    private Przelewy24Gateway $gateway;

    protected function setUp(): void
    {
        global $_fchub_test_wp_remote_request, $_fchub_test_as_actions;

        $_fchub_test_wp_remote_request = null;
        $_fchub_test_as_actions = [];

        \FluentCart\App\Models\Subscription::$mockResult = null;

        $this->gateway = new Przelewy24Gateway();
    }

    public function testMaybeStoreCardInfoStoresAllMetaFields(): void
    {
        global $_fchub_test_wp_remote_request;

        $sub = new \FluentCart\App\Models\Subscription();
        $sub->id = 42;
        $sub->status = 'active';
        $sub->recurring_total = 5000;
        $sub->currency = 'PLN';
        \FluentCart\App\Models\Subscription::$mockResult = $sub;

        $_fchub_test_wp_remote_request = function ($url) {
            return [
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'data' => [
                        'refId'    => 'card-ref-xyz',
                        'mask'     => '****5678',
                        'cardType' => 'MASTERCARD',
                        'cardDate' => '0329',
                    ],
                ]),
            ];
        };

        $transaction = new \FluentCart\App\Models\OrderTransaction();
        $transaction->subscription_id = 42;

        // Use reflection to call private method
        $method = new \ReflectionMethod($this->gateway, 'maybeStoreCardInfoAndScheduleRenewal');
        $method->setAccessible(true);
        $method->invoke($this->gateway, $transaction, 987654);

        $this->assertSame('card-ref-xyz', $sub->getMeta('_p24_card_ref_id'));
        $this->assertSame(987654, $sub->getMeta('_p24_card_trace_order_id'));
        $this->assertSame('****5678', $sub->getMeta('_p24_card_mask'));
        $this->assertSame('MASTERCARD', $sub->getMeta('_p24_card_type'));
        $this->assertSame('0329', $sub->getMeta('_p24_card_expiry'));

        $_fchub_test_wp_remote_request = null;
    }

    public function testMaybeStoreCardInfoSetsVendorSubscriptionId(): void
    {
        global $_fchub_test_wp_remote_request;

        $sub = new \FluentCart\App\Models\Subscription();
        $sub->id = 42;
        $sub->status = 'active';
        $sub->recurring_total = 5000;
        \FluentCart\App\Models\Subscription::$mockResult = $sub;

        $_fchub_test_wp_remote_request = function () {
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['refId' => 'ref-abc']]),
            ];
        };

        $transaction = new \FluentCart\App\Models\OrderTransaction();
        $transaction->subscription_id = 42;

        $method = new \ReflectionMethod($this->gateway, 'maybeStoreCardInfoAndScheduleRenewal');
        $method->setAccessible(true);
        $method->invoke($this->gateway, $transaction, 123);

        $this->assertSame('p24_sub_42', $sub->vendor_subscription_id);

        $_fchub_test_wp_remote_request = null;
    }

    public function testMaybeStoreCardInfoSchedulesRenewal(): void
    {
        global $_fchub_test_wp_remote_request, $_fchub_test_as_actions;

        $sub = new \FluentCart\App\Models\Subscription();
        $sub->id = 42;
        $sub->status = 'active';
        $sub->recurring_total = 5000;
        \FluentCart\App\Models\Subscription::$mockResult = $sub;

        $_fchub_test_wp_remote_request = function () {
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['refId' => 'ref-abc']]),
            ];
        };

        $transaction = new \FluentCart\App\Models\OrderTransaction();
        $transaction->subscription_id = 42;

        $method = new \ReflectionMethod($this->gateway, 'maybeStoreCardInfoAndScheduleRenewal');
        $method->setAccessible(true);
        $method->invoke($this->gateway, $transaction, 123);

        $schedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule');
        $this->assertNotEmpty($schedules);

        $_fchub_test_wp_remote_request = null;
    }

    public function testMaybeStoreCardInfoSkipsNonSubscriptionTransaction(): void
    {
        $transaction = new \FluentCart\App\Models\OrderTransaction();
        // No subscription_id set

        $method = new \ReflectionMethod($this->gateway, 'maybeStoreCardInfoAndScheduleRenewal');
        $method->setAccessible(true);
        $method->invoke($this->gateway, $transaction, 123);

        // No subscription should have been looked up or modified
        $this->assertNull(\FluentCart\App\Models\Subscription::$mockResult);
    }

    public function testMaybeStoreCardInfoIdempotent(): void
    {
        global $_fchub_test_wp_remote_request, $_fchub_test_as_actions;

        $sub = new \FluentCart\App\Models\Subscription();
        $sub->id = 42;
        $sub->status = 'active';
        $sub->recurring_total = 5000;
        $sub->updateMeta('_p24_card_ref_id', 'already-stored');
        \FluentCart\App\Models\Subscription::$mockResult = $sub;

        $apiCalled = false;
        $_fchub_test_wp_remote_request = function () use (&$apiCalled) {
            $apiCalled = true;
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['refId' => 'new-ref']]),
            ];
        };

        $transaction = new \FluentCart\App\Models\OrderTransaction();
        $transaction->subscription_id = 42;

        $method = new \ReflectionMethod($this->gateway, 'maybeStoreCardInfoAndScheduleRenewal');
        $method->setAccessible(true);
        $method->invoke($this->gateway, $transaction, 123);

        // API should not have been called since refId already exists
        $this->assertFalse($apiCalled);
        $this->assertSame('already-stored', $sub->getMeta('_p24_card_ref_id'));

        $_fchub_test_wp_remote_request = null;
    }

    public function testMaybeStoreCardInfoHandlesApiError(): void
    {
        global $_fchub_test_wp_remote_request;

        $sub = new \FluentCart\App\Models\Subscription();
        $sub->id = 42;
        $sub->status = 'active';
        $sub->recurring_total = 5000;
        \FluentCart\App\Models\Subscription::$mockResult = $sub;

        $_fchub_test_wp_remote_request = function () {
            return [
                'response' => ['code' => 404],
                'body'     => json_encode(['error' => 'Not found']),
            ];
        };

        $transaction = new \FluentCart\App\Models\OrderTransaction();
        $transaction->subscription_id = 42;

        $method = new \ReflectionMethod($this->gateway, 'maybeStoreCardInfoAndScheduleRenewal');
        $method->setAccessible(true);
        $method->invoke($this->gateway, $transaction, 123);

        // Should not have stored any card info
        $this->assertNull($sub->getMeta('_p24_card_ref_id'));

        $_fchub_test_wp_remote_request = null;
    }

    public function testMaybeStoreCardInfoHandlesNoRefIdInResponse(): void
    {
        global $_fchub_test_wp_remote_request;

        $sub = new \FluentCart\App\Models\Subscription();
        $sub->id = 42;
        $sub->status = 'active';
        $sub->recurring_total = 5000;
        \FluentCart\App\Models\Subscription::$mockResult = $sub;

        $_fchub_test_wp_remote_request = function () {
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['mask' => '****1234']]), // no refId
            ];
        };

        $transaction = new \FluentCart\App\Models\OrderTransaction();
        $transaction->subscription_id = 42;

        $method = new \ReflectionMethod($this->gateway, 'maybeStoreCardInfoAndScheduleRenewal');
        $method->setAccessible(true);
        $method->invoke($this->gateway, $transaction, 123);

        $this->assertNull($sub->getMeta('_p24_card_ref_id'));

        $_fchub_test_wp_remote_request = null;
    }

    public function testGatewayHasSubscriptionsFeatureWhenEnabled(): void
    {
        $gateway = new Przelewy24Gateway();
        $this->assertContains('subscriptions', $gateway->supportedFeatures);
    }
}
