<?php

namespace FChubP24\Tests\Unit;

use FChubP24\Gateway\Przelewy24Gateway;
use FChubP24\Tests\PhpInputStreamWrapper;
use FChubP24\Tests\TestSettings;
use FChubP24\Tests\WpSendJsonException;
use PHPUnit\Framework\TestCase;

class Przelewy24GatewayRenewalIPNTest extends TestCase
{
    private Przelewy24Gateway $gateway;
    private string $crcKey = '679175ea875c2776';
    private bool $phpInputOverridden = false;

    protected function setUp(): void
    {
        global $_fchub_test_wp_remote_request, $_fchub_test_as_actions;

        $_fchub_test_wp_remote_request = null;
        $_fchub_test_as_actions = [];

        \FluentCart\App\Models\OrderTransaction::$mockResult = null;
        \FluentCart\App\Models\Order::$mockResults = [];
        \FluentCart\App\Models\Subscription::$mockResult = null;
        \FluentCart\App\Models\Subscription::$mockResults = [];
        \FluentCart\App\Modules\Subscriptions\Services\SubscriptionService::$lastRenewalData = null;
        \FluentCart\App\Modules\Subscriptions\Services\SubscriptionService::$shouldFail = false;

        $settings = new TestSettings([
            'test_merchant_id' => '383989',
            'test_shop_id'    => '383989',
            'test_crc_key'    => $this->crcKey,
            'test_api_key'    => 'ec201b57daf4a3e1f65825c04ca9b5b5',
        ]);

        $ref = new \ReflectionClass(Przelewy24Gateway::class);
        $this->gateway = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue($this->gateway, $settings);
    }

    protected function tearDown(): void
    {
        global $_fchub_test_wp_remote_request;
        $_fchub_test_wp_remote_request = null;

        if ($this->phpInputOverridden) {
            stream_wrapper_restore('php');
            $this->phpInputOverridden = false;
        }
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function testRenewalIPNDetectedByPendingSession(): void
    {
        $sessionId = 'renewal-session-uuid-123';
        $orderId = 555;
        $amount = 5000;

        $sub = $this->createSubscriptionWithPendingSession(42, $sessionId, $amount);
        \FluentCart\App\Models\Subscription::$mockResults = [$sub];

        $this->mockVerifySuccess();

        $notification = $this->buildNotification($sessionId, $orderId, $amount);

        try {
            $this->simulateIPN($notification);
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(200, $e->statusCode);
            $this->assertSame('OK', $e->data['status']);
        }

        $renewalData = \FluentCart\App\Modules\Subscriptions\Services\SubscriptionService::$lastRenewalData;
        $this->assertNotNull($renewalData);
        $this->assertSame(42, $renewalData['subscription_id']);
        $this->assertSame((string) $orderId, $renewalData['vendor_charge_id']);
        $this->assertSame($amount, $renewalData['total']);
    }

    public function testRenewalIPNClearsPendingSession(): void
    {
        $sessionId = 'renewal-session-uuid-456';
        $sub = $this->createSubscriptionWithPendingSession(42, $sessionId, 5000);
        \FluentCart\App\Models\Subscription::$mockResults = [$sub];

        $this->mockVerifySuccess();

        try {
            $this->simulateIPN($this->buildNotification($sessionId, 555, 5000));
        } catch (WpSendJsonException $e) {
            // expected
        }

        $this->assertSame('', $sub->getMeta('_p24_pending_renewal_session'));
    }

    public function testRenewalIPNAmountMismatchRejects(): void
    {
        $sessionId = 'renewal-session-uuid-789';
        $sub = $this->createSubscriptionWithPendingSession(42, $sessionId, 5000);
        \FluentCart\App\Models\Subscription::$mockResults = [$sub];

        $notification = $this->buildNotification($sessionId, 555, 1000); // Wrong amount

        try {
            $this->simulateIPN($notification);
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('Amount mismatch', $e->data['error']);
        }
    }

    public function testRenewalIPNVerificationFailureRejects(): void
    {
        $sessionId = 'renewal-session-uuid-fail';
        $sub = $this->createSubscriptionWithPendingSession(42, $sessionId, 5000);
        \FluentCart\App\Models\Subscription::$mockResults = [$sub];

        $this->mockVerifyFailure();

        try {
            $this->simulateIPN($this->buildNotification($sessionId, 555, 5000));
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('Verification failed', $e->data['error']);
        }
    }

    public function testNonRenewalIPNFallsThrough(): void
    {
        \FluentCart\App\Models\Subscription::$mockResults = [];

        $notification = $this->buildNotification('unknown-session', 555, 5000);

        try {
            $this->simulateIPN($notification);
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertSame('Transaction not found', $e->data['error']);
        }
    }

    public function testRenewalIPNRecordFailureReturns500(): void
    {
        $sessionId = 'renewal-session-uuid-err';
        $sub = $this->createSubscriptionWithPendingSession(42, $sessionId, 5000);
        \FluentCart\App\Models\Subscription::$mockResults = [$sub];

        $this->mockVerifySuccess();
        \FluentCart\App\Modules\Subscriptions\Services\SubscriptionService::$shouldFail = true;

        try {
            $this->simulateIPN($this->buildNotification($sessionId, 555, 5000));
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(500, $e->statusCode);
            $this->assertSame('Failed to record renewal', $e->data['error']);
        }
    }

    public function testRenewalIPNSchedulesNextRenewal(): void
    {
        global $_fchub_test_as_actions;

        $sessionId = 'renewal-session-schedule';
        $sub = $this->createSubscriptionWithPendingSession(42, $sessionId, 5000);
        \FluentCart\App\Models\Subscription::$mockResults = [$sub];

        $this->mockVerifySuccess();

        try {
            $this->simulateIPN($this->buildNotification($sessionId, 555, 5000));
        } catch (WpSendJsonException $e) {
            // expected
        }

        $schedules = array_filter($_fchub_test_as_actions, fn($a) => $a['type'] === 'schedule');
        $this->assertNotEmpty($schedules);
    }

    // --- Helpers ---

    private function createSubscriptionWithPendingSession(int $id, string $sessionId, int $amount): \FluentCart\App\Models\Subscription
    {
        $sub = new \FluentCart\App\Models\Subscription();
        $sub->id = $id;
        $sub->status = 'active';
        $sub->recurring_total = $amount;
        $sub->currency = 'PLN';
        $sub->updateMeta('_p24_pending_renewal_session', $sessionId);
        return $sub;
    }

    private function buildNotification(string $sessionId, int $orderId, int $amount): array
    {
        $data = [
            'merchantId'   => 383989,
            'posId'        => 383989,
            'sessionId'    => $sessionId,
            'amount'       => $amount,
            'originAmount' => $amount,
            'currency'     => 'PLN',
            'orderId'      => $orderId,
            'methodId'     => 154,
            'statement'    => 'Renewal payment',
        ];

        $signData = json_encode([
            'merchantId'   => (int) $data['merchantId'],
            'posId'        => (int) $data['posId'],
            'sessionId'    => $data['sessionId'],
            'amount'       => (int) $data['amount'],
            'originAmount' => (int) $data['originAmount'],
            'currency'     => $data['currency'],
            'orderId'      => (int) $data['orderId'],
            'methodId'     => (int) $data['methodId'],
            'statement'    => $data['statement'],
            'crc'          => $this->crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $data['sign'] = hash('sha384', $signData);

        return $data;
    }

    private function simulateIPN(array $notification): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        stream_wrapper_unregister('php');
        stream_wrapper_register('php', PhpInputStreamWrapper::class);
        PhpInputStreamWrapper::$input = json_encode($notification);
        $this->phpInputOverridden = true;

        $this->gateway->handleIPN();
    }

    private function mockVerifySuccess(): void
    {
        global $_fchub_test_wp_remote_request;

        $_fchub_test_wp_remote_request = function ($url) {
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['status' => 'success']]),
            ];
        };
    }

    private function mockVerifyFailure(): void
    {
        global $_fchub_test_wp_remote_request;

        $_fchub_test_wp_remote_request = function ($url) {
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['status' => 'error']]),
            ];
        };
    }
}
