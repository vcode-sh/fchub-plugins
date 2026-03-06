<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Pub;

use FChubMultiCurrency\Http\Controllers\Pub\RatesController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RatesControllerPublicTest extends TestCase
{
    #[Test]
    public function testProviderFieldIsNotExposedInPublicResponse(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
        ]);

        // Mock a rate result from the database (ARRAY_A format)
        $this->setWpdbMockResults([
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'EUR',
                'rate'           => '0.92000000',
                'provider'       => 'exchange_rate_api',
                'fetched_at'     => '2025-01-01 12:00:00',
            ],
        ]);

        $controller = new RatesController();
        $request = new \WP_REST_Request('GET', '/');

        $response = $controller->index($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('rates', $data['data']);

        foreach ($data['data']['rates'] as $rate) {
            $this->assertArrayNotHasKey(
                'provider',
                $rate,
                'Provider field must not be exposed in the public /rates response'
            );
        }
    }

    #[Test]
    public function testPublicResponseContainsExpectedFields(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
        ]);

        $this->setWpdbMockResults([
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'GBP',
                'rate'           => '0.79000000',
                'provider'       => 'ecb',
                'fetched_at'     => '2025-03-01 10:00:00',
            ],
        ]);

        $controller = new RatesController();
        $request = new \WP_REST_Request('GET', '/');

        $response = $controller->index($request);
        $data = $response->get_data();

        $this->assertSame('USD', $data['data']['base_currency']);
        $this->assertCount(1, $data['data']['rates']);

        $rate = $data['data']['rates'][0];
        $this->assertArrayHasKey('base_currency', $rate);
        $this->assertArrayHasKey('quote_currency', $rate);
        $this->assertArrayHasKey('rate', $rate);
        $this->assertArrayHasKey('fetched_at', $rate);
    }
}
