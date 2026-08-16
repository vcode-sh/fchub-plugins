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
        $this->setOption(Constants::OPTION_SETTINGS, [
            'base_currency' => 'USD',
            'stale_threshold_hrs' => 24,
            'display_currencies' => [['code' => 'EUR'], ['code' => 'GBP']],
        ]);
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
        $this->assertSame('manual', $data['data']['provider']);
        $this->assertSame(['EUR', 'GBP'], $data['data']['quote_currencies']);
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
        $this->setOption(Constants::OPTION_SETTINGS, [
            'base_currency'     => 'USD',
            'rate_provider'     => 'exchange_rate_api',
            'rate_provider_api_key' => 'api-key',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $this->mockExchangeRateApi(['USD' => '1.00000000', 'EUR' => '0.92000000']);

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
        $this->setOption(Constants::OPTION_SETTINGS, [
            'base_currency'     => 'USD',
            'rate_provider'     => 'exchange_rate_api',
            'rate_provider_api_key' => 'api-key',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $this->mockExchangeRateApi([]);

        $controller = new RatesAdminController();
        $response = $controller->refresh(new \WP_REST_Request('POST', '/'));
        $data = $response->get_data();

        $this->assertSame(500, $response->get_status());
        $this->assertFalse($data['data']['status']);
        $this->assertSame('Failed to refresh exchange rates. Check the logs for details.', $data['data']['message']);
    }

    #[Test]
    public function testRefreshRejectsManualProviderWithoutRewritingHistoricalRates(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, [
            'base_currency' => 'USD',
            'rate_provider' => 'manual',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $this->setWpdbMockResults([[
            'base_currency' => 'USD',
            'quote_currency' => 'EUR',
            'rate' => '0.92000000',
            'provider' => 'manual',
            'fetched_at' => '2026-01-01 00:00:00',
        ]]);

        $response = (new RatesAdminController())->refresh(new \WP_REST_Request('POST', '/'));

        $this->assertSame(409, $response->get_status());
        $this->assertSame(
            'Manual rates are saved explicitly and cannot be refreshed.',
            $response->get_data()['data']['message'],
        );
        $this->assertSame([], array_filter(
            $GLOBALS['wpdb']->queries,
            static fn(string $query): bool => str_contains($query, 'INSERT INTO'),
        ));
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testManualSaveRejectsAnInvalidPayloadAtTheHttpBoundary(): void
    {
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['rates' => 'not-an-object']);

        $response = (new RatesAdminController())->saveManual($request);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('Provide manual rates as a currency-to-rate object.', $response->get_data()['data']['message']);
    }

    #[Test]
    public function testManualSaveReturnsValidatedCurrentRates(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, [
            'base_currency' => 'USD',
            'rate_provider' => 'manual',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['rates' => ['EUR' => '0.92000000']]);

        $response = (new RatesAdminController())->saveManual($request);
        $data = $response->get_data()['data'];

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['status']);
        $this->assertSame('USD', $data['base_currency']);
        $this->assertSame('EUR', $data['rates'][0]['quote_currency']);
        $this->assertSame('0.92000000', $data['rates'][0]['rate']);
    }

    /**
     * @param array<string, string> $rates
     */
    private function mockExchangeRateApi(array $rates): void
    {
        $body = json_encode([
            'result' => 'success',
            'conversion_rates' => $rates,
        ]);
        $GLOBALS['wp_mock_remote_response'] = [
            'body' => $body,
            'response' => ['code' => 200],
        ];
        $GLOBALS['wp_mock_remote_body'] = $body;
    }
}
