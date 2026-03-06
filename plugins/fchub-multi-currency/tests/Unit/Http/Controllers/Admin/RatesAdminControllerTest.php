<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Admin;

use FChubMultiCurrency\Http\Controllers\Admin\RatesAdminController;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RatesAdminControllerTest extends TestCase
{
    #[Test]
    public function testIndexReturnsFormattedRates(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, ['base_currency' => 'USD', 'stale_threshold_hrs' => 24]);
        $this->setWpdbMockResults([
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'EUR',
                'rate'           => '0.92000000',
                'provider'       => 'manual',
                'fetched_at'     => date('Y-m-d H:i:s'),
            ],
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'GBP',
                'rate'           => '0.79000000',
                'provider'       => 'manual',
                'fetched_at'     => date('Y-m-d H:i:s'),
            ],
        ]);

        $controller = new RatesAdminController();
        $response = $controller->index(new \WP_REST_Request('GET', '/'));
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('USD', $data['data']['base_currency']);
        $this->assertCount(2, $data['data']['rates']);
        $this->assertSame('EUR', $data['data']['rates'][0]['quote_currency']);
        $this->assertSame('0.92000000', $data['data']['rates'][0]['rate']);
        $this->assertSame('manual', $data['data']['rates'][0]['provider']);
        $this->assertArrayHasKey('is_stale', $data['data']['rates'][0]);
        $this->assertSame('GBP', $data['data']['rates'][1]['quote_currency']);
    }

    #[Test]
    public function testIndexDefaultsToUsdBaseCurrency(): void
    {
        $this->setWpdbMockResults([]);

        $controller = new RatesAdminController();
        $response = $controller->index(new \WP_REST_Request('GET', '/'));
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('USD', $data['data']['base_currency']);
    }

    #[Test]
    public function testIndexIncludesStaleFlagBasedOnThreshold(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, ['base_currency' => 'USD', 'stale_threshold_hrs' => 24]);
        $staleFetchedAt = date('Y-m-d H:i:s', time() - (25 * 3600));
        $this->setWpdbMockResults([
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'EUR',
                'rate'           => '0.92000000',
                'provider'       => 'manual',
                'fetched_at'     => $staleFetchedAt,
            ],
        ]);

        $controller = new RatesAdminController();
        $response = $controller->index(new \WP_REST_Request('GET', '/'));
        $data = $response->get_data();

        $this->assertTrue($data['data']['rates'][0]['is_stale']);
    }

    #[Test]
    public function testRefreshReturnsSuccessWhenRatesAreRefreshed(): void
    {
        // Use manual provider so no HTTP calls are made.
        // ManualProvider::fetchRates calls findAllLatest (get_results) once,
        // then RefreshRatesAction loops display_currencies and inserts each.
        $this->setOption(Constants::OPTION_SETTINGS, [
            'base_currency'     => 'USD',
            'rate_provider'     => 'manual',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $this->setWpdbMockResults([
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'EUR',
                'rate'           => '0.92000000',
                'provider'       => 'manual',
                'fetched_at'     => date('Y-m-d H:i:s'),
            ],
        ]);

        $controller = new RatesAdminController();
        $response = $controller->refresh(new \WP_REST_Request('POST', '/'));
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['data']['status']);
        $this->assertSame('Exchange rates refreshed successfully.', $data['data']['message']);
    }

    #[Test]
    public function testRefreshReturns500WhenActionFails(): void
    {
        // Manual provider returns empty rates → RefreshRatesAction returns false.
        $this->setOption(Constants::OPTION_SETTINGS, [
            'base_currency'     => 'USD',
            'rate_provider'     => 'manual',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $this->setWpdbMockResults([]);

        $controller = new RatesAdminController();
        $response = $controller->refresh(new \WP_REST_Request('POST', '/'));
        $data = $response->get_data();

        $this->assertSame(500, $response->get_status());
        $this->assertFalse($data['data']['status']);
        $this->assertSame('Failed to refresh exchange rates. Check the logs for details.', $data['data']['message']);
    }
}
