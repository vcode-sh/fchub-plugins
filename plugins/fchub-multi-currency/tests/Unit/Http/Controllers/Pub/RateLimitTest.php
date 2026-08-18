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
        \FChubMultiCurrency\Domain\Services\CurrencyResolution::resetChain();

        $_GET = [];
        $_COOKIE = [];
    }

    #[Test]
    public function testFirstRequestSucceeds(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [],
        ]);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'USD']);

        $response = $controller->set($request);

        $this->assertSame(200, $response->get_status());
    }

    #[Test]
    public function testReturns429AfterRateLimitExceeded(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [],
        ]);

        // Simulate 30 requests already made by setting the transient directly
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimitKey = 'fchub_mc_rl_' . substr(md5($ip), 0, 12);
        $GLOBALS['wp_transients'][$rateLimitKey] = 30;

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'USD']);

        $response = $controller->set($request);

        $this->assertSame(429, $response->get_status());
        $data = $response->get_data();
        $this->assertStringContainsString('Too many requests', $data['data']['message']);
    }

    /**
     * The rate_unavailable branch writes an event-log row per request. Requests
     * that end there must spend limiter budget, or a scripted client can insert
     * rows for as long as it likes without ever meeting the 429.
     */
    #[Test]
    public function testRateUnavailableAttemptsCountAgainstTheLimiter(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'enabled' => true],
            ],
        ]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimitKey = 'fchub_mc_rl_' . substr(md5($ip), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = $controller->set($request);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('rate_unavailable', $response->get_data()['data']['code']);
        $this->assertSame(1, $GLOBALS['wp_transients'][$rateLimitKey] ?? 0);
    }

    #[Test]
    public function testInvalidCurrencyAttemptsCountAgainstTheLimiter(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [],
        ]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimitKey = 'fchub_mc_rl_' . substr(md5($ip), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'XXX']);

        $response = $controller->set($request);

        $this->assertSame(422, $response->get_status());
        $this->assertSame(1, $GLOBALS['wp_transients'][$rateLimitKey] ?? 0);
    }

    /**
     * Forwarding headers rotate freely — a scripted client can put a fresh,
     * valid IP in X-Forwarded-For on every request and mint a new per-IP
     * bucket each time. The socket address cannot be forged, so a coarser
     * bucket keyed on it is the ceiling that survives rotation.
     */
    #[Test]
    public function testRotatedForwardedHeadersStillMeetTheSocketBackstop(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [],
        ]);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $backstopKey = 'fchub_mc_rlb_' . substr(md5($remoteAddr), 0, 12);
        $GLOBALS['wp_transients'][$backstopKey] = 120;

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'USD']);

        $response = $controller->set($request);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $this->assertSame(429, $response->get_status());
    }

    #[Test]
    public function testTransientIncrementsOnEachRequest(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [],
        ]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimitKey = 'fchub_mc_rl_' . substr(md5($ip), 0, 12);

        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'USD']);

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
