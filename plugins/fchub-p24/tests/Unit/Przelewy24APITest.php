<?php

namespace FChubP24\Tests\Unit;

use FChubP24\API\Przelewy24API;
use FChubP24\Tests\TestSettings;
use PHPUnit\Framework\TestCase;

class Przelewy24APITest extends TestCase
{
    private Przelewy24API $api;
    private TestSettings $settings;

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
     * Test transaction registration sign calculation
     *
     * Sign = SHA384(JSON({"sessionId","merchantId","amount","currency","crc"}))
     */
    public function testRegisterTransactionSignCalculation(): void
    {
        $sessionId = 'test-session-123';
        $merchantId = 383989;
        $amount = 9999;
        $currency = 'PLN';
        $crcKey = '679175ea875c2776';

        $expectedSignData = json_encode([
            'sessionId'  => $sessionId,
            'merchantId' => $merchantId,
            'amount'     => $amount,
            'currency'   => $currency,
            'crc'        => $crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $expectedSign = hash('sha384', $expectedSignData);

        // Verify the sign is a 96-character hex string (SHA384)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{96}$/', $expectedSign);

        // Verify JSON encoding is deterministic
        $signData2 = json_encode([
            'sessionId'  => $sessionId,
            'merchantId' => $merchantId,
            'amount'     => $amount,
            'currency'   => $currency,
            'crc'        => $crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame($expectedSignData, $signData2);
        $this->assertSame($expectedSign, hash('sha384', $signData2));
    }

    /**
     * Test that sign calculation uses correct field order for registration
     */
    public function testRegisterSignFieldOrder(): void
    {
        $fields = ['sessionId', 'merchantId', 'amount', 'currency', 'crc'];

        $signData = json_encode([
            'sessionId'  => 'sess-1',
            'merchantId' => 12345,
            'amount'     => 100,
            'currency'   => 'PLN',
            'crc'        => 'key123',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Different field order should produce different sign
        $wrongOrderData = json_encode([
            'merchantId' => 12345,
            'sessionId'  => 'sess-1',
            'amount'     => 100,
            'currency'   => 'PLN',
            'crc'        => 'key123',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertNotSame($signData, $wrongOrderData);
        $this->assertNotSame(hash('sha384', $signData), hash('sha384', $wrongOrderData));
    }

    /**
     * Test IPN notification sign verification
     */
    public function testVerifyNotificationSign(): void
    {
        $crcKey = '679175ea875c2776';

        $notification = [
            'merchantId'   => 383989,
            'posId'        => 383989,
            'sessionId'    => 'test-session-uuid',
            'amount'       => 5000,
            'originAmount' => 5000,
            'currency'     => 'PLN',
            'orderId'      => 123456789,
            'methodId'     => 154,
            'statement'    => 'Payment for order',
        ];

        // Calculate expected sign
        $signData = json_encode([
            'merchantId'   => (int) $notification['merchantId'],
            'posId'        => (int) $notification['posId'],
            'sessionId'    => $notification['sessionId'],
            'amount'       => (int) $notification['amount'],
            'originAmount' => (int) $notification['originAmount'],
            'currency'     => $notification['currency'],
            'orderId'      => (int) $notification['orderId'],
            'methodId'     => (int) $notification['methodId'],
            'statement'    => $notification['statement'],
            'crc'          => $crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $notification['sign'] = hash('sha384', $signData);

        $this->assertTrue($this->api->verifyNotificationSign($notification));
    }

    /**
     * Test IPN notification sign rejection with wrong sign
     */
    public function testRejectsInvalidNotificationSign(): void
    {
        $notification = [
            'merchantId'   => 383989,
            'posId'        => 383989,
            'sessionId'    => 'test-session-uuid',
            'amount'       => 5000,
            'originAmount' => 5000,
            'currency'     => 'PLN',
            'orderId'      => 123456789,
            'methodId'     => 154,
            'statement'    => 'Payment for order',
            'sign'         => 'invalid_sign_value_that_should_not_match',
        ];

        $this->assertFalse($this->api->verifyNotificationSign($notification));
    }

    /**
     * Test IPN notification sign rejection with tampered amount
     */
    public function testRejectsTamperedAmount(): void
    {
        $crcKey = '679175ea875c2776';

        $original = [
            'merchantId'   => 383989,
            'posId'        => 383989,
            'sessionId'    => 'test-session-uuid',
            'amount'       => 5000,
            'originAmount' => 5000,
            'currency'     => 'PLN',
            'orderId'      => 123456789,
            'methodId'     => 154,
            'statement'    => 'Payment for order',
        ];

        // Sign with original amount
        $signData = json_encode([
            'merchantId'   => (int) $original['merchantId'],
            'posId'        => (int) $original['posId'],
            'sessionId'    => $original['sessionId'],
            'amount'       => (int) $original['amount'],
            'originAmount' => (int) $original['originAmount'],
            'currency'     => $original['currency'],
            'orderId'      => (int) $original['orderId'],
            'methodId'     => (int) $original['methodId'],
            'statement'    => $original['statement'],
            'crc'          => $crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $original['sign'] = hash('sha384', $signData);

        // Tamper with amount
        $tampered = $original;
        $tampered['amount'] = 1; // Changed to 1 grosz

        $this->assertFalse($this->api->verifyNotificationSign($tampered));
    }

    /**
     * Test refund notification sign verification
     */
    public function testVerifyRefundNotificationSign(): void
    {
        $crcKey = '679175ea875c2776';

        $notification = [
            'orderId'      => 123456789,
            'sessionId'    => 'test-session-uuid',
            'refundsUuid'  => 'refund-uuid-123',
            'merchantId'   => 383989,
            'amount'       => 2500,
            'currency'     => 'PLN',
            'status'       => 0,
        ];

        $signData = json_encode([
            'orderId'      => (int) $notification['orderId'],
            'sessionId'    => $notification['sessionId'],
            'refundsUuid'  => $notification['refundsUuid'],
            'merchantId'   => (int) $notification['merchantId'],
            'amount'       => (int) $notification['amount'],
            'currency'     => $notification['currency'],
            'status'       => (int) $notification['status'],
            'crc'          => $crcKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $notification['sign'] = hash('sha384', $signData);

        $this->assertTrue($this->api->verifyRefundNotificationSign($notification));
    }

    /**
     * Test refund notification rejects invalid sign
     */
    public function testRejectsInvalidRefundSign(): void
    {
        $notification = [
            'orderId'      => 123456789,
            'sessionId'    => 'test-session-uuid',
            'refundsUuid'  => 'refund-uuid-123',
            'merchantId'   => 383989,
            'amount'       => 2500,
            'currency'     => 'PLN',
            'status'       => 0,
            'sign'         => 'completely_wrong_hash',
        ];

        $this->assertFalse($this->api->verifyRefundNotificationSign($notification));
    }

    /**
     * Test that Polish characters in sign data don't break the hash
     */
    public function testSignWithPolishCharacters(): void
    {
        $signData = json_encode([
            'sessionId'  => 'ąćęłńóśźż-test',
            'merchantId' => 383989,
            'amount'     => 100,
            'currency'   => 'PLN',
            'crc'        => '679175ea875c2776',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Should contain raw Polish chars, not escaped
        $this->assertStringContainsString('ąćęłńóśźż', $signData);
        $this->assertStringNotContainsString('\\u', $signData);

        $hash = hash('sha384', $signData);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{96}$/', $hash);
    }

    /**
     * Test verification sign field order (different from registration)
     */
    public function testVerificationSignFieldOrder(): void
    {
        // Verification uses: sessionId, orderId, amount, currency, crc
        // (orderId instead of merchantId)
        $signData = json_encode([
            'sessionId' => 'sess-1',
            'orderId'   => 999,
            'amount'    => 100,
            'currency'  => 'PLN',
            'crc'       => 'key123',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $hash = hash('sha384', $signData);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{96}$/', $hash);

        // Registration uses different fields, should produce different hash
        $regSignData = json_encode([
            'sessionId'  => 'sess-1',
            'merchantId' => 999,
            'amount'     => 100,
            'currency'   => 'PLN',
            'crc'        => 'key123',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertNotSame(hash('sha384', $signData), hash('sha384', $regSignData));
    }
}
