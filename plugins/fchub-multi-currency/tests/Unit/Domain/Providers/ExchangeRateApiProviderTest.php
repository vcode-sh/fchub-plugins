<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Providers;

use FChubMultiCurrency\Domain\Providers\ExchangeRateApiProvider;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExchangeRateApiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['wp_mock_remote_response'], $GLOBALS['wp_mock_remote_body']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['wp_mock_remote_response'], $GLOBALS['wp_mock_remote_body']);
    }

    #[Test]
    public function testFetchRatesSuccess(): void
    {
        $body = json_encode([
            'result' => 'success',
            'conversion_rates' => [
                'USD' => 1,
                'EUR' => 0.92,
                'GBP' => 0.79,
            ],
        ]);

        $GLOBALS['wp_mock_remote_response'] = ['body' => $body, 'response' => ['code' => 200]];
        $GLOBALS['wp_mock_remote_body'] = $body;

        $provider = new ExchangeRateApiProvider('test-api-key');
        $rates = $provider->fetchRates('USD');

        $this->assertIsArray($rates);
        $this->assertSame('1', $rates['USD']);
        $this->assertSame('0.92', $rates['EUR']);
        $this->assertSame('0.79', $rates['GBP']);
    }

    #[Test]
    public function testFetchRatesReturnsEmptyOnWpError(): void
    {
        $GLOBALS['wp_mock_remote_response'] = new \WP_Error('http_error', 'Connection failed');

        $provider = new ExchangeRateApiProvider('test-api-key');
        $rates = $provider->fetchRates('USD');

        $this->assertSame([], $rates);
    }

    #[Test]
    public function testFetchRatesReturnsEmptyOnInvalidResponse(): void
    {
        $body = json_encode(['error' => 'invalid']);

        $GLOBALS['wp_mock_remote_response'] = ['body' => $body, 'response' => ['code' => 200]];
        $GLOBALS['wp_mock_remote_body'] = $body;

        $provider = new ExchangeRateApiProvider('test-api-key');
        $rates = $provider->fetchRates('USD');

        $this->assertSame([], $rates);
    }

    #[Test]
    public function testNameReturnsCorrectSlug(): void
    {
        $provider = new ExchangeRateApiProvider('test-api-key');
        $this->assertSame('exchange_rate_api', $provider->name());
    }
}
