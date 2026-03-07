<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Pub;

use FChubMultiCurrency\Http\Controllers\Pub\ContextController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RateLimiterIpTest extends TestCase
{
    private array $originalServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;

        // Reset cached resolver chain
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_GET = [];
        $_COOKIE = [];

        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [['code' => 'EUR']],
        ]);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    #[Test]
    public function testRateLimiterUsesRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $expectedKey = 'fchub_mc_rl_' . substr(md5('192.168.1.100'), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $controller->set($request);

        $this->assertArrayHasKey($expectedKey, $GLOBALS['wp_transients']);
        $this->assertSame(1, $GLOBALS['wp_transients'][$expectedKey]);
    }

    #[Test]
    public function testRateLimiterIgnoresXForwardedForHeader(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

        $realIpKey = 'fchub_mc_rl_' . substr(md5('10.0.0.1'), 0, 12);
        $spoofedKey = 'fchub_mc_rl_' . substr(md5('203.0.113.50'), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $controller->set($request);

        // Rate limit key should be based on REMOTE_ADDR, not X-Forwarded-For
        $this->assertArrayHasKey($realIpKey, $GLOBALS['wp_transients']);
        $this->assertArrayNotHasKey($spoofedKey, $GLOBALS['wp_transients']);
    }

    #[Test]
    public function testRateLimiterFallsBackToUnknownWhenNoRemoteAddr(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        $unknownKey = 'fchub_mc_rl_' . substr(md5('unknown'), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $controller->set($request);

        $this->assertArrayHasKey($unknownKey, $GLOBALS['wp_transients']);
    }

    #[Test]
    public function testDifferentIpsGetDifferentRateLimitBuckets(): void
    {
        $keyA = 'fchub_mc_rl_' . substr(md5('10.0.0.1'), 0, 12);
        $keyB = 'fchub_mc_rl_' . substr(md5('10.0.0.2'), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        // First IP
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $controller->set($request);

        // Reset context singleton for second call
        \FChubMultiCurrency\Domain\Services\CurrencyContextService::reset();

        // Second IP
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $controller->set($request);

        $this->assertSame(1, $GLOBALS['wp_transients'][$keyA]);
        $this->assertSame(1, $GLOBALS['wp_transients'][$keyB]);
        $this->assertNotSame($keyA, $keyB);
    }

    #[Test]
    public function testRateLimiterWorksWithIpv6Address(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';

        $expectedKey = 'fchub_mc_rl_' . substr(md5('2001:db8::1'), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = $controller->set($request);

        $this->assertSame(200, $response->get_status());
        $this->assertArrayHasKey($expectedKey, $GLOBALS['wp_transients']);
        $this->assertSame(1, $GLOBALS['wp_transients'][$expectedKey]);
    }

    #[Test]
    public function testRateLimiterWorksWithIpv6Loopback(): void
    {
        $_SERVER['REMOTE_ADDR'] = '::1';

        $expectedKey = 'fchub_mc_rl_' . substr(md5('::1'), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = $controller->set($request);

        $this->assertSame(200, $response->get_status());
        $this->assertArrayHasKey($expectedKey, $GLOBALS['wp_transients']);
        $this->assertSame(1, $GLOBALS['wp_transients'][$expectedKey]);
    }
}
