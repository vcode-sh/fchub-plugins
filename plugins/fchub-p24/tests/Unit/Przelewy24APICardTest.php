<?php

namespace FChubP24\Tests\Unit;

use FChubP24\API\Przelewy24API;
use FChubP24\Tests\TestSettings;
use PHPUnit\Framework\TestCase;

class Przelewy24APICardTest extends TestCase
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

    public function testGetCardInfoSendsCorrectEndpoint(): void
    {
        global $_fchub_test_wp_remote_request;

        $capturedUrl = '';
        $capturedArgs = [];

        $_fchub_test_wp_remote_request = function ($url, $args) use (&$capturedUrl, &$capturedArgs) {
            $capturedUrl = $url;
            $capturedArgs = $args;

            return [
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'data' => [
                        'refId'    => 'ref-123-abc',
                        'mask'     => '****1234',
                        'cardType' => 'VISA',
                        'cardDate' => '1227',
                    ],
                ]),
            ];
        };

        $result = $this->api->getCardInfo(987654321);

        $this->assertStringEndsWith('/api/v1/card/info/987654321', $capturedUrl);
        $this->assertSame('GET', $capturedArgs['method']);
        $this->assertSame('ref-123-abc', $result['data']['refId']);
        $this->assertSame('****1234', $result['data']['mask']);
        $this->assertSame('VISA', $result['data']['cardType']);
        $this->assertSame('1227', $result['data']['cardDate']);

        $_fchub_test_wp_remote_request = null;
    }

    public function testGetCardInfoUsesBasicAuth(): void
    {
        global $_fchub_test_wp_remote_request;

        $capturedArgs = [];

        $_fchub_test_wp_remote_request = function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['refId' => 'test']]),
            ];
        };

        $this->api->getCardInfo(12345);

        $expectedAuth = 'Basic ' . base64_encode('383989:ec201b57daf4a3e1f65825c04ca9b5b5');
        $this->assertSame($expectedAuth, $capturedArgs['headers']['Authorization']);

        $_fchub_test_wp_remote_request = null;
    }

    public function testGetCardInfoHandlesApiError(): void
    {
        global $_fchub_test_wp_remote_request;

        $_fchub_test_wp_remote_request = function () {
            return [
                'response' => ['code' => 404],
                'body'     => json_encode(['error' => 'Order not found']),
            ];
        };

        $result = $this->api->getCardInfo(99999);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(404, $result['code']);

        $_fchub_test_wp_remote_request = null;
    }

    public function testChargeCardSendsCorrectEndpoint(): void
    {
        global $_fchub_test_wp_remote_request;

        $capturedUrl = '';
        $capturedArgs = [];

        $_fchub_test_wp_remote_request = function ($url, $args) use (&$capturedUrl, &$capturedArgs) {
            $capturedUrl = $url;
            $capturedArgs = $args;

            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['responseCode' => 0]]),
            ];
        };

        $result = $this->api->chargeCard('test-token-abc');

        $this->assertStringEndsWith('/api/v1/card/charge', $capturedUrl);
        $this->assertSame('POST', $capturedArgs['method']);

        $sentBody = json_decode($capturedArgs['body'], true);
        $this->assertSame('test-token-abc', $sentBody['token']);
        $this->assertSame(0, $result['data']['responseCode']);

        $_fchub_test_wp_remote_request = null;
    }

    public function testChargeCardSendsOnlyTokenInBody(): void
    {
        global $_fchub_test_wp_remote_request;

        $capturedArgs = [];

        $_fchub_test_wp_remote_request = function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['responseCode' => 0]]),
            ];
        };

        $this->api->chargeCard('my-token');

        $sentBody = json_decode($capturedArgs['body'], true);
        $this->assertCount(1, $sentBody);
        $this->assertArrayHasKey('token', $sentBody);

        $_fchub_test_wp_remote_request = null;
    }

    public function testChargeCardHandlesError(): void
    {
        global $_fchub_test_wp_remote_request;

        $_fchub_test_wp_remote_request = function () {
            return [
                'response' => ['code' => 400],
                'body'     => json_encode(['error' => 'Invalid token']),
            ];
        };

        $result = $this->api->chargeCard('bad-token');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(400, $result['code']);

        $_fchub_test_wp_remote_request = null;
    }

    public function testGetCardInfoSendsToSandboxUrl(): void
    {
        global $_fchub_test_wp_remote_request;

        $capturedUrl = '';

        $_fchub_test_wp_remote_request = function ($url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => []]),
            ];
        };

        $this->api->getCardInfo(123);

        $this->assertStringStartsWith('https://sandbox.przelewy24.pl', $capturedUrl);

        $_fchub_test_wp_remote_request = null;
    }

    public function testChargeCardSendsToSandboxUrl(): void
    {
        global $_fchub_test_wp_remote_request;

        $capturedUrl = '';

        $_fchub_test_wp_remote_request = function ($url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => []]),
            ];
        };

        $this->api->chargeCard('token');

        $this->assertStringStartsWith('https://sandbox.przelewy24.pl', $capturedUrl);

        $_fchub_test_wp_remote_request = null;
    }

    public function testGetCardInfoNoBodySentForGetRequest(): void
    {
        global $_fchub_test_wp_remote_request;

        $capturedArgs = [];

        $_fchub_test_wp_remote_request = function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['data' => ['refId' => 'x']]),
            ];
        };

        $this->api->getCardInfo(123);

        $this->assertArrayNotHasKey('body', $capturedArgs);

        $_fchub_test_wp_remote_request = null;
    }
}
