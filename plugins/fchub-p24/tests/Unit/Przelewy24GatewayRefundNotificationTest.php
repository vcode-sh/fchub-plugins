<?php

namespace FChubP24\Tests\Unit;

use FChubP24\Gateway\Przelewy24Gateway;
use FChubP24\Tests\PhpInputStreamWrapper;
use FChubP24\Tests\TestSettings;
use FChubP24\Tests\WpSendJsonException;
use FluentCart\App\Models\OrderTransaction;
use PHPUnit\Framework\TestCase;

class Przelewy24GatewayRefundNotificationTest extends TestCase
{
    private Przelewy24Gateway $gateway;
    private bool $phpInputOverridden = false;

    protected function setUp(): void
    {
        $settings = new TestSettings([
            'test_merchant_id' => '383989',
            'test_shop_id'    => '383989',
            'test_crc_key'    => '679175ea875c2776',
            'test_api_key'    => 'ec201b57daf4a3e1f65825c04ca9b5b5',
        ]);

        $ref = new \ReflectionClass(Przelewy24Gateway::class);
        $this->gateway = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue($this->gateway, $settings);

        // Reset global test state
        global $_fchub_test_refund_calls;
        $_fchub_test_refund_calls = [];
        \FluentCart_OrderTransaction::$mockResult = null;
        \FluentCart_Order::$mockResults = [];
    }

    public function testRefundMissingFieldReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Missing 'amount' field
        $input = json_encode([
            'orderId'      => 987654321,
            'sessionId'    => 'test-uuid-12345',
            'refundsUuid'  => 'refund-uuid-abc',
            'merchantId'   => 383989,
            'currency'     => 'PLN',
            'status'       => 0,
            'sign'         => 'some-sign',
        ]);
        $this->setPhpInput($input);

        try {
            $this->gateway->handleIPN();
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('Missing field: amount', $e->data['error']);
        }
    }

    public function testRefundMissingRefundsUuidReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Has refundsUuid in the body (so it routes to refund handler)
        // but then the required keys check should catch it
        // Actually, the routing check is !empty($input['refundsUuid']),
        // so if refundsUuid is missing, it won't route to refund handler.
        // We need to test the refund handler's own validation.
        // Let's call handleRefundNotification directly via reflection.
        $input = [
            'orderId'    => 987654321,
            'sessionId'  => 'test-uuid-12345',
            'merchantId' => 383989,
            'amount'     => 2500,
            'currency'   => 'PLN',
            'status'     => 0,
            'sign'       => 'some-sign',
        ];

        $method = new \ReflectionMethod(Przelewy24Gateway::class, 'handleRefundNotification');
        $method->setAccessible(true);

        try {
            $method->invoke($this->gateway, $input);
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('Missing field: refundsUuid', $e->data['error']);
        }
    }

    public function testRefundInvalidSignatureReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $input = $this->buildRefundInput(0);
        $input['sign'] = 'invalid-sign';

        $this->setPhpInput(json_encode($input));

        try {
            $this->gateway->handleIPN();
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('Invalid signature', $e->data['error']);
        }
    }

    public function testRefundCompletedCallsRefundService(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        global $_fchub_test_refund_calls;

        // Set up mock transaction
        $mockTx = new \FluentCart_OrderTransaction();
        $mockTx->uuid = 'test-uuid-12345';
        $mockTx->id = 42;
        $mockTx->order_id = 100;
        $mockTx->status = 'succeeded';
        $mockTx->total = 5000;
        \FluentCart_OrderTransaction::$mockResult = $mockTx;

        $input = $this->buildRefundInput(0);
        $this->setPhpInput(json_encode($input));

        try {
            $this->gateway->handleIPN();
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(200, $e->statusCode);
            $this->assertSame('OK', $e->data['status']);
        }

        // Verify Refund::createOrRecordRefund was called
        $this->assertCount(1, $_fchub_test_refund_calls);
        $call = $_fchub_test_refund_calls[0];
        $this->assertSame('refund-uuid-abc', $call['refundData']['vendor_charge_id']);
        $this->assertSame('przelewy24', $call['refundData']['payment_method']);
        $this->assertSame(2500, $call['refundData']['total']);
        $this->assertSame($mockTx, $call['parentTransaction']);
    }

    public function testRefundRejectedDoesNotCallRefundService(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        global $_fchub_test_refund_calls;

        // Set up mock transaction
        $mockTx = new \FluentCart_OrderTransaction();
        $mockTx->uuid = 'test-uuid-12345';
        $mockTx->id = 42;
        $mockTx->order_id = 100;
        $mockTx->status = 'succeeded';
        $mockTx->total = 5000;
        \FluentCart_OrderTransaction::$mockResult = $mockTx;

        $input = $this->buildRefundInput(1);
        $this->setPhpInput(json_encode($input));

        try {
            $this->gateway->handleIPN();
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(200, $e->statusCode);
        }

        // Refund service should NOT have been called
        $this->assertEmpty($_fchub_test_refund_calls);
    }

    public function testRefundTransactionNotFoundReturns404(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // No mock transaction set — query will return null
        \FluentCart_OrderTransaction::$mockResult = null;

        $input = $this->buildRefundInput(0);
        $this->setPhpInput(json_encode($input));

        try {
            $this->gateway->handleIPN();
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertSame('Transaction not found', $e->data['error']);
        }
    }

    /**
     * Build a valid refund notification input with correct sign
     */
    private function buildRefundInput(int $status = 0): array
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

    private function setPhpInput(string $data): void
    {
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', PhpInputStreamWrapper::class);
        PhpInputStreamWrapper::$input = $data;
        $this->phpInputOverridden = true;
    }

    protected function tearDown(): void
    {
        if ($this->phpInputOverridden) {
            stream_wrapper_restore('php');
        }
        $this->phpInputOverridden = false;
        unset($_SERVER['REQUEST_METHOD']);
        \FluentCart_OrderTransaction::$mockResult = null;
        \FluentCart_Order::$mockResults = [];
        global $_fchub_test_refund_calls;
        $_fchub_test_refund_calls = [];
    }
}
