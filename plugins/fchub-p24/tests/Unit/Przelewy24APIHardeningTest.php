<?php

namespace FChubP24\Tests\Unit;

use FChubP24\API\Przelewy24API;
use FChubP24\Tests\TestSettings;
use PHPUnit\Framework\TestCase;

class Przelewy24APIHardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        global $_fchub_test_wp_remote_request;
        $_fchub_test_wp_remote_request = null;
    }

    public function testRequestUsesConstrainedWordPressHttpOptions(): void
    {
        global $_fchub_test_wp_remote_request;
        $capturedUrl = null;
        $capturedArgs = null;
        $_fchub_test_wp_remote_request = function ($url, $args) use (&$capturedUrl, &$capturedArgs) {
            $capturedUrl = $url;
            $capturedArgs = $args;

            return [
                'response' => ['code' => 200],
                'body' => '{"data":{"status":"success"}}',
            ];
        };

        $api = new Przelewy24API($this->completeSettings());
        $api->testAccess();

        $this->assertSame('https://sandbox.przelewy24.pl/api/v1/testAccess', $capturedUrl);
        $this->assertSame(30, $capturedArgs['timeout']);
        $this->assertSame(0, $capturedArgs['redirection']);
        $this->assertTrue($capturedArgs['sslverify']);
        $this->assertSame(2 * 1024 * 1024, $capturedArgs['limit_response_size']);
    }

    public function testIncompleteCredentialsPreventEveryRequest(): void
    {
        global $_fchub_test_wp_remote_request;
        $requestCount = 0;
        $_fchub_test_wp_remote_request = function () use (&$requestCount) {
            $requestCount++;
            return ['response' => ['code' => 200], 'body' => '{}'];
        };

        $api = new Przelewy24API(new TestSettings([
            'test_merchant_id' => '383989',
            'test_shop_id' => '383989',
            'test_crc_key' => '',
            'test_api_key' => '',
        ]));

        foreach ([
            $api->testAccess(),
            $api->getPaymentMethods(),
            $api->getCardInfo(1),
            $api->chargeCard('private-token'),
            $api->refund(['requestId' => 'refund']),
            $api->registerTransaction([
                'sessionId' => 'session',
                'amount' => 100,
                'currency' => 'PLN',
            ]),
        ] as $result) {
            $this->assertArrayHasKey('error', $result);
        }

        $this->assertSame(0, $requestCount);
    }

    public function testUnapprovedHostIsRejectedBeforeNetworkAccess(): void
    {
        global $_fchub_test_wp_remote_request;
        $requestCount = 0;
        $_fchub_test_wp_remote_request = function () use (&$requestCount) {
            $requestCount++;
            return ['response' => ['code' => 200], 'body' => '{}'];
        };

        $settings = new class ([
            'test_merchant_id' => '383989',
            'test_shop_id' => '383989',
            'test_crc_key' => 'crc',
            'test_api_key' => 'api',
        ]) extends TestSettings {
            public function getBaseUrl(): string
            {
                return 'https://sandbox.przelewy24.pl.attacker.example';
            }
        };

        $result = (new Przelewy24API($settings))->testAccess();

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, $requestCount);
    }

    public function testTransportErrorsDoNotExposeCredentialsOrRawProviderContent(): void
    {
        global $_fchub_test_wp_remote_request;
        $_fchub_test_wp_remote_request = new \WP_Error(
            'transport',
            'Authorization: Basic MzgzOTg5OnNlY3JldC1hcGkta2V5 <html>proxy failure</html>'
        );

        $result = (new Przelewy24API($this->completeSettings()))->testAccess();
        $encoded = json_encode($result);

        $this->assertStringNotContainsString('secret-api-key', $encoded);
        $this->assertStringNotContainsString('Basic ', $encoded);
        $this->assertStringNotContainsString('<html>', $encoded);
    }

    public function testMalformedAndListResponsesAreRejected(): void
    {
        global $_fchub_test_wp_remote_request;
        $api = new Przelewy24API($this->completeSettings());

        $_fchub_test_wp_remote_request = [
            'response' => ['code' => 200],
            'body' => '{"data":',
        ];
        $this->assertArrayHasKey('error', $api->testAccess());

        $_fchub_test_wp_remote_request = [
            'response' => ['code' => 200],
            'body' => '["unexpected","list"]',
        ];
        $this->assertArrayHasKey('error', $api->testAccess());
    }

    private function completeSettings(): TestSettings
    {
        return new TestSettings([
            'test_merchant_id' => '383989',
            'test_shop_id' => '383989',
            'test_crc_key' => 'secret-crc-key',
            'test_api_key' => 'secret-api-key',
        ]);
    }
}
