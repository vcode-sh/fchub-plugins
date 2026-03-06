<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Pub;

use FChubMultiCurrency\Http\Controllers\Pub\ContextController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset cached resolver chain
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_GET = [];
        $_COOKIE = [];
    }

    #[Test]
    public function testFirstRequestSucceeds(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [['code' => 'EUR']],
        ]);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = $controller->set($request);

        $this->assertSame(200, $response->get_status());
    }

    #[Test]
    public function testReturns429AfterRateLimitExceeded(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [['code' => 'EUR']],
        ]);

        // Simulate 30 requests already made by setting the transient directly
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimitKey = 'fchub_mc_rl_' . substr(md5($ip), 0, 12);
        $GLOBALS['wp_transients'][$rateLimitKey] = 30;

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = $controller->set($request);

        $this->assertSame(429, $response->get_status());
        $data = $response->get_data();
        $this->assertStringContainsString('Too many requests', $data['data']['message']);
    }

    #[Test]
    public function testTransientIncrementsOnEachRequest(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [['code' => 'EUR']],
        ]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimitKey = 'fchub_mc_rl_' . substr(md5($ip), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        // First request — transient should be 1 after
        $controller->set($request);
        $this->assertSame(1, $GLOBALS['wp_transients'][$rateLimitKey]);

        // Reset context singleton for second call
        \FChubMultiCurrency\Domain\Services\CurrencyContextService::reset();

        // Second request — transient should be 2 after
        $controller->set($request);
        $this->assertSame(2, $GLOBALS['wp_transients'][$rateLimitKey]);
    }
}
