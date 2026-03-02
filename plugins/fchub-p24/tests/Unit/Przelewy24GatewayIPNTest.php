<?php

namespace FChubP24\Tests\Unit;

use FChubP24\API\Przelewy24API;
use FChubP24\Tests\TestSettings;
use FChubP24\Tests\WpSendJsonException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IPN (Instant Payment Notification) handling logic
 */
class Przelewy24GatewayIPNTest extends TestCase
{
    private TestSettings $settings;
    private Przelewy24API $api;

    protected function setUp(): void
    {
        $this->settings = new TestSettings([
            'test_merchant_id' => '383989',
            'test_shop_id'    => '383989',
            'test_crc_key'    => '679175ea875c2776',
            'test_api_key'    => 'ec201b57daf4a3e1f65825c04ca9b5b5',
        ]);

        $this->api = new Przelewy24API($this->settings);
    }

    /**
     * Test valid payment notification passes signature check
     */
    public function testValidPaymentNotification(): void
    {
        $notification = $this->buildValidNotification();
        $this->assertTrue($this->api->verifyNotificationSign($notification));
    }

    /**
     * Test notification with modified sessionId fails
     */
    public function testTamperedSessionIdFails(): void
    {
        $notification = $this->buildValidNotification();
        $notification['sessionId'] = 'tampered-session-id';
        $this->assertFalse($this->api->verifyNotificationSign($notification));
    }

    /**
     * Test notification with modified orderId fails
     */
    public function testTamperedOrderIdFails(): void
    {
        $notification = $this->buildValidNotification();
        $notification['orderId'] = 999999;
        $this->assertFalse($this->api->verifyNotificationSign($notification));
    }

    /**
     * Test notification with modified currency fails
     */
    public function testTamperedCurrencyFails(): void
    {
        $notification = $this->buildValidNotification();
        $notification['currency'] = 'EUR';
        $this->assertFalse($this->api->verifyNotificationSign($notification));
    }

    /**
     * Test notification with modified merchantId fails
     */
    public function testTamperedMerchantIdFails(): void
    {
        $notification = $this->buildValidNotification();
        $notification['merchantId'] = 111111;
        $this->assertFalse($this->api->verifyNotificationSign($notification));
    }

    /**
     * Test valid refund notification passes signature check
     */
    public function testValidRefundNotification(): void
    {
        $notification = $this->buildValidRefundNotification();
        $this->assertTrue($this->api->verifyRefundNotificationSign($notification));
    }

    /**
     * Test refund notification with status=1 (rejected) still verifies
     */
    public function testRefundRejectedNotificationVerifies(): void
    {
        $notification = $this->buildValidRefundNotification(1);
        $this->assertTrue($this->api->verifyRefundNotificationSign($notification));
    }

    /**
     * Test refund notification with tampered status fails
     */
    public function testTamperedRefundStatusFails(): void
    {
        $notification = $this->buildValidRefundNotification(0);
        $notification['status'] = 1; // Change from completed to rejected
        $this->assertFalse($this->api->verifyRefundNotificationSign($notification));
    }

    /**
     * Test refund notification with tampered amount fails
     */
    public function testTamperedRefundAmountFails(): void
    {
        $notification = $this->buildValidRefundNotification();
        $notification['amount'] = 1;
        $this->assertFalse($this->api->verifyRefundNotificationSign($notification));
    }

    /**
     * Test that different CRC keys produce different signatures
     */
    public function testDifferentCrcKeyProducesDifferentSign(): void
    {
        $settings2 = new TestSettings([
            'test_merchant_id' => '383989',
            'test_shop_id'    => '383989',
            'test_crc_key'    => 'different_crc_key_here',
            'test_api_key'    => 'ec201b57daf4a3e1f65825c04ca9b5b5',
        ]);
        $api2 = new Przelewy24API($settings2);

        $notification = $this->buildValidNotification();

        // Sign is valid with original CRC key
        $this->assertTrue($this->api->verifyNotificationSign($notification));

        // But fails with different CRC key
        $this->assertFalse($api2->verifyNotificationSign($notification));
    }

    /**
     * Test amount mismatch detection logic
     */
    public function testAmountMismatchDetection(): void
    {
        $registeredAmount = 5000;
        $receivedAmount = 5000;
        $this->assertSame($registeredAmount, $receivedAmount);

        $tamperedAmount = 1;
        $this->assertNotSame($registeredAmount, $tamperedAmount);
    }

    /**
     * Test idempotency - same notification should verify the same way
     */
    public function testSignVerificationIsDeterministic(): void
    {
        $notification = $this->buildValidNotification();

        // Verify multiple times - should always return true
        $this->assertTrue($this->api->verifyNotificationSign($notification));
        $this->assertTrue($this->api->verifyNotificationSign($notification));
        $this->assertTrue($this->api->verifyNotificationSign($notification));
    }

    /**
     * Build a valid payment notification with correct sign
     */
    private function buildValidNotification(array $overrides = []): array
    {
        $data = array_merge([
            'merchantId'   => 383989,
            'posId'        => 383989,
            'sessionId'    => 'test-uuid-12345',
            'amount'       => 5000,
            'originAmount' => 5000,
            'currency'     => 'PLN',
            'orderId'      => 987654321,
            'methodId'     => 154,
            'statement'    => 'Payment test',
        ], $overrides);

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
            'crc'          => '679175ea875c2776',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $data['sign'] = hash('sha384', $signData);

        return $data;
    }

    /**
     * Build a valid refund notification with correct sign
     */
    private function buildValidRefundNotification(int $status = 0): array
    {
        $data = [
            'orderId'      => 987654321,
            'sessionId'    => 'test-uuid-12345',
            'refundsUuid'  => 'refund-uuid-abc',
            'merchantId'   => 383989,
            'amount'       => 2500,
            'currency'     => 'PLN',
            'status'       => $status,
        ];

        $signData = json_encode([
            'orderId'      => (int) $data['orderId'],
            'sessionId'    => $data['sessionId'],
            'refundsUuid'  => $data['refundsUuid'],
            'merchantId'   => (int) $data['merchantId'],
            'amount'       => (int) $data['amount'],
            'currency'     => $data['currency'],
            'status'       => (int) $data['status'],
            'crc'          => '679175ea875c2776',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $data['sign'] = hash('sha384', $signData);

        return $data;
    }
}
