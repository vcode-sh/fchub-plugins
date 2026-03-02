<?php

namespace FChubP24\Tests\Unit;

use FChubP24\Gateway\Przelewy24Gateway;
use FChubP24\Tests\PhpInputStreamWrapper;
use FChubP24\Tests\TestSettings;
use FChubP24\Tests\WpSendJsonException;
use PHPUnit\Framework\TestCase;

class Przelewy24GatewayIPNValidationTest extends TestCase
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

        // Use reflection to bypass the constructor that hardcodes settings
        $ref = new \ReflectionClass(Przelewy24Gateway::class);
        $this->gateway = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue($this->gateway, $settings);
    }

    public function testIPNNonPostMethodReturns405(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $this->gateway->handleIPN();
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(405, $e->statusCode);
            $this->assertSame('Method not allowed', $e->data['error']);
        }
    }

    public function testIPNEmptyBodyReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        try {
            $this->gateway->handleIPN();
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('Invalid notification', $e->data['error']);
        }
    }

    public function testIPNMissingRequiredFieldReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $input = json_encode(['sessionId' => 'test-uuid-12345']);
        $this->setPhpInput($input);

        try {
            $this->gateway->handleIPN();
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertStringContainsString('Missing field:', $e->data['error']);
            $this->assertSame('Missing field: merchantId', $e->data['error']);
        }
    }

    public function testIPNWithAllRequiredFieldsPassesValidation(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $input = json_encode([
            'merchantId'   => 383989,
            'posId'        => 383989,
            'sessionId'    => 'test-uuid-12345',
            'amount'       => 5000,
            'originAmount' => 5000,
            'currency'     => 'PLN',
            'orderId'      => 987654321,
            'methodId'     => 154,
            'statement'    => 'Payment test',
            'sign'         => 'invalid-sign',
        ]);
        $this->setPhpInput($input);

        try {
            $this->gateway->handleIPN();
            $this->fail('Expected WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('Invalid signature', $e->data['error']);
        }
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
    }
}
