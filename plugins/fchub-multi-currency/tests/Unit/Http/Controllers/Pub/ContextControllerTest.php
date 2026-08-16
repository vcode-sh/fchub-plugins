<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Pub;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Http\Controllers\Pub\ContextController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ContextControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // get() resolves through the resolver chain (set() does not) — reset the
        // static cache so an earlier test file's settings can't leak in here.
        ContextModule::resetChain();
    }

    #[Test]
    public function testSetReturnsBadRequestForInvalidJsonPayload(): void
    {
        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_body('{invalid-json');

        $response = $controller->set($request);

        $this->assertSame(400, $response->get_status());
    }

    #[Test]
    public function testSetHandlesInvalidDisplayCurrenciesShapeWithoutFatal(): void
    {
        $controller = new ContextController();
        $this->setOption('fchub_mc_settings', [
            'display_currencies' => 'invalid-shape',
        ]);

        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'currency' => 'EUR',
        ]);

        $response = $controller->set($request);

        $this->assertSame(422, $response->get_status());
    }

    #[Test]
    public function testSetAllowsSwitchingToBaseCurrencyEvenIfNotInDisplayList(): void
    {
        $controller = new ContextController();
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);

        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'currency' => 'USD',
        ]);

        $response = $controller->set($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('USD', $data['data']['currency']);
        $this->assertTrue($data['data']['persisted']);
        $this->assertStringContainsString('wp_fchub_mc_event_log', implode(' ', $GLOBALS['wpdb']->queries));
    }

    #[Test]
    public function testSetReportsFailureForGuestWhenCookiePersistenceIsDisabled(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'cookie_enabled'     => 'no',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);

        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = (new ContextController())->set($request);
        $data = $response->get_data();

        $this->assertSame(409, $response->get_status());
        $this->assertFalse($data['data']['persisted']);
        $this->assertSame('EUR', $data['data']['currency']);
        $this->assertStringContainsString('Cookie persistence is disabled', $data['data']['message']);
        $this->assertHookNotFired('fchub_mc/context_switched');
    }

    #[Test]
    public function testSetSucceedsForLoggedInUserWhenCookiePersistenceIsDisabled(): void
    {
        $this->setCurrentUserId(11);
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'cookie_enabled'     => 'no',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);

        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = (new ContextController())->set($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['data']['persisted']);
        $this->assertSame('EUR', $GLOBALS['wp_mock_user_meta'][11]['_fchub_mc_currency'] ?? '');
        $this->assertHookFired('fchub_mc/context_switched');
    }

    #[Test]
    public function testSetReportsFailureWhenBothPersistenceChannelsAreDisabled(): void
    {
        $this->setCurrentUserId(11);
        $this->setOption('fchub_mc_settings', [
            'base_currency'               => 'USD',
            'cookie_enabled'              => 'no',
            'account_persistence_enabled' => 'no',
            'display_currencies'          => [
                ['code' => 'EUR'],
            ],
        ]);

        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = (new ContextController())->set($request);
        $data = $response->get_data();

        $this->assertSame(409, $response->get_status());
        $this->assertFalse($data['data']['persisted']);
        $this->assertSame(ContextController::CODE_PERSISTENCE_UNAVAILABLE, $data['data']['code']);
        $this->assertStringContainsString('account persistence are disabled', $data['data']['message']);
        $this->assertHookNotFired('fchub_mc/context_switched');
    }

    /**
     * Every outcome carries a stable slug. Clients branch on the code, never on the
     * message — the message is translated, the code is not.
     *
     * @return array<string, array{0: callable(self): \WP_REST_Request, 1: int, 2: string}>
     */
    public static function outcomeCodeProvider(): array
    {
        return [
            'module disabled' => [
                static function (self $case): \WP_REST_Request {
                    $case->setOption('fchub_mc_settings', ['enabled' => 'no']);
                    $request = new \WP_REST_Request('POST', '/');
                    $request->set_json_params(['currency' => 'EUR']);

                    return $request;
                },
                403,
                ContextController::CODE_MODULE_DISABLED,
            ],
            'invalid payload' => [
                static function (self $case): \WP_REST_Request {
                    $request = new \WP_REST_Request('POST', '/');
                    $request->set_body('{invalid-json');

                    return $request;
                },
                400,
                ContextController::CODE_INVALID_PAYLOAD,
            ],
            'currency required' => [
                static function (self $case): \WP_REST_Request {
                    $request = new \WP_REST_Request('POST', '/');
                    $request->set_json_params(['currency' => '']);

                    return $request;
                },
                422,
                ContextController::CODE_CURRENCY_REQUIRED,
            ],
            'invalid currency' => [
                static function (self $case): \WP_REST_Request {
                    $case->setOption('fchub_mc_settings', [
                        'base_currency'      => 'USD',
                        'display_currencies' => [['code' => 'EUR']],
                    ]);
                    $request = new \WP_REST_Request('POST', '/');
                    $request->set_json_params(['currency' => 'XXX']);

                    return $request;
                },
                422,
                ContextController::CODE_INVALID_CURRENCY,
            ],
            'persistence unavailable' => [
                static function (self $case): \WP_REST_Request {
                    $case->setOption('fchub_mc_settings', [
                        'base_currency'      => 'USD',
                        'cookie_enabled'     => 'no',
                        'display_currencies' => [['code' => 'EUR']],
                    ]);
                    $request = new \WP_REST_Request('POST', '/');
                    $request->set_json_params(['currency' => 'EUR']);

                    return $request;
                },
                409,
                ContextController::CODE_PERSISTENCE_UNAVAILABLE,
            ],
            'saved' => [
                static function (self $case): \WP_REST_Request {
                    $case->setOption('fchub_mc_settings', [
                        'base_currency'      => 'USD',
                        'display_currencies' => [['code' => 'EUR']],
                    ]);
                    $request = new \WP_REST_Request('POST', '/');
                    $request->set_json_params(['currency' => 'EUR']);

                    return $request;
                },
                200,
                ContextController::CODE_SAVED,
            ],
        ];
    }

    #[Test]
    #[DataProvider('outcomeCodeProvider')]
    public function testEveryOutcomeCarriesItsStableCode(
        callable $buildRequest,
        int $expectedStatus,
        string $expectedCode
    ): void {
        $request = $buildRequest($this);

        $response = (new ContextController())->set($request);
        $data = $response->get_data();

        $this->assertSame($expectedStatus, $response->get_status());
        $this->assertSame($expectedCode, $data['data']['code']);
        $this->assertNotSame('', $data['data']['message']);
    }

    #[Test]
    public function testOutcomeCodesAreDistinct(): void
    {
        $codes = array_column(self::outcomeCodeProvider(), 2);

        $this->assertSame($codes, array_unique($codes), 'Two outcomes share a code, so clients cannot tell them apart.');
    }

    /**
     * GET /context carries the full price-formatting metadata (symbol, decimals,
     * position, separators, disclosure) — not just the code and rate — so a
     * client-side reconciliation fetch (currency-projection.js's reconcile(), for
     * issue #72) can fully re-render prices from this response alone.
     */
    #[Test]
    public function testGetReturnsFullPricingMetadataForTheResolvedCurrency(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'right_space'],
            ],
        ]);
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);
        $_COOKIE['fchub_mc_currency'] = 'EUR';

        $response = (new ContextController())->get(new \WP_REST_Request('GET', '/'));
        $data = $response->get_data()['data'];

        $this->assertSame(200, $response->get_status());
        $this->assertSame('EUR', $data['display_currency']);
        $this->assertSame('Euro', $data['display_currency_name']);
        $this->assertSame('USD', $data['base_currency']);
        $this->assertSame('cookie', $data['source']);
        $this->assertFalse($data['is_base_display']);
        $this->assertSame('€', $data['symbol']);
        $this->assertSame(2, $data['decimals']);
        $this->assertSame('right_space', $data['position']);
        $this->assertArrayHasKey('display_decimal_separator', $data);
        $this->assertArrayHasKey('display_thousand_separator', $data);
        $this->assertArrayHasKey('disclosure_enabled', $data);
        $this->assertArrayHasKey('disclosure_text', $data);

        unset($_COOKIE['fchub_mc_currency']);
    }

    /**
     * UrlParamResolver already reads $_GET directly with top priority in the
     * resolver chain, ahead of the cookie — so GET /context?currency=EUR resolves
     * to EUR regardless of any cookie. This is what makes the endpoint usable for
     * client-side reconciliation: it isn't dependent on the cookie round-tripping
     * through a host's caching/WAF layer.
     */
    #[Test]
    public function testGetHonoursUrlParamOverCookieForReconciliation(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
                ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'GBP',
            'rate'           => '0.79000000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);
        $_COOKIE['fchub_mc_currency'] = 'EUR';
        $_GET['currency'] = 'GBP';

        $response = (new ContextController())->get(new \WP_REST_Request('GET', '/'));
        $data = $response->get_data()['data'];

        $this->assertSame('GBP', $data['display_currency']);
        $this->assertSame('url_param', $data['source']);

        unset($_COOKIE['fchub_mc_currency'], $_GET['currency']);
    }

    #[Test]
    public function testGetIgnoresDisallowedUrlParamCurrencyAndFallsThroughToCookie(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);
        $_COOKIE['fchub_mc_currency'] = 'EUR';
        // Not in display_currencies — AllowedCurrencyCheck must reject it server-side
        // even though a client would already have filtered it against its own copy
        // of the currency list before ever making this request.
        $_GET['currency'] = 'JPY';

        $response = (new ContextController())->get(new \WP_REST_Request('GET', '/'));
        $data = $response->get_data()['data'];

        $this->assertSame('EUR', $data['display_currency']);
        $this->assertSame('cookie', $data['source']);

        unset($_COOKIE['fchub_mc_currency'], $_GET['currency']);
    }

    /**
     * Confirmed live on issue #72 (Rocket.net staging): every layer resolved the
     * right currency — localStorage held it, this endpoint returned it — but the
     * page still settled back on the stale currency, because nothing told the
     * browser this response was visitor-specific, so it silently served a cached
     * copy of GET /context?currency=X on later requests to the same URL. The
     * response varies per visitor (cookie, account, query string), so every
     * response from this controller — both endpoints, every status — must carry
     * Cache-Control: no-store. See ContextController::noStore().
     */
    #[Test]
    public function testGetResponseIsNeverCacheable(): void
    {
        $this->setOption('fchub_mc_settings', ['base_currency' => 'USD']);
        $this->setWpdbMockRow(null);

        $response = (new ContextController())->get(new \WP_REST_Request('GET', '/'));

        $this->assertSame('no-store', $response->get_headers()['Cache-Control'] ?? null);
    }

    #[Test]
    public function testSetSuccessResponseIsNeverCacheable(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = (new ContextController())->set($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('no-store', $response->get_headers()['Cache-Control'] ?? null);
    }

    #[Test]
    #[DataProvider('outcomeCodeProvider')]
    public function testEveryOutcomeIsNeverCacheable(callable $buildRequest, int $expectedStatus): void
    {
        $request = $buildRequest($this);

        $response = (new ContextController())->set($request);

        $this->assertSame($expectedStatus, $response->get_status());
        $this->assertSame('no-store', $response->get_headers()['Cache-Control'] ?? null);
    }

    /**
     * Temporary diagnostic instrumentation for issue #72's non-base-currency
     * latency investigation (see Support\Profiler, Support\CacheDiagnostics).
     * Gated on $_GET['_debug_timing'] — checked directly, not via
     * WP_REST_Request::get_param(), because the same gate also has to work on
     * a plain front-end page load (ContextModule::outputDebugTimingComment()),
     * which has no request object. Confirmed here that ordinary traffic —
     * every other test in this file, none of which touch $_GET — never sees
     * a `_profile` key.
     */
    #[Test]
    public function testGetOmitsProfileByDefault(): void
    {
        $this->setOption('fchub_mc_settings', ['base_currency' => 'USD']);
        $this->setWpdbMockRow(null);

        $response = (new ContextController())->get(new \WP_REST_Request('GET', '/'));
        $data = $response->get_data()['data'];

        $this->assertArrayNotHasKey('_profile', $data);
        $this->assertArrayNotHasKey('_notes', $data);
        $this->assertArrayNotHasKey('_is_logged_in', $data);
    }

    #[Test]
    public function testGetIncludesTimingAndCacheDiagnosticsWhenDebugTimingRequested(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€']],
        ]);
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);
        $_COOKIE['fchub_mc_currency'] = 'EUR';
        $_GET['_debug_timing'] = '1';

        $response = (new ContextController())->get(new \WP_REST_Request('GET', '/'));
        $data = $response->get_data()['data'];

        $this->assertArrayHasKey('_profile', $data);
        $labels = array_column($data['_profile'], 'label');

        // Controller-level stages always appear...
        $this->assertContains('get_start', $labels);
        $this->assertContains('chain_built', $labels);
        $this->assertContains('context_resolved', $labels);
        $this->assertContains('pricing_built', $labels);
        // ...and since EUR != base here, the rate lookup actually runs, so its
        // marks — and the DB-query-only marks one level below it — must
        // appear too. This is the specific breakdown issue #72 needs (cache
        // hit/miss vs. DB fallback vs. pure query time), not just round-trip.
        $this->assertContains('rate_lookup_start', $labels);
        $this->assertContains('rate_cache_miss', $labels, 'first lookup in this test has nothing cached yet');
        $this->assertContains('rate_db_fallback_done', $labels);
        $this->assertContains('db_query_start', $labels);
        $this->assertContains('db_query_done', $labels);

        $noteLabels = array_column($data['_notes'], 'label');
        $this->assertContains('cache_get_result', $noteLabels);
        $this->assertContains('cache_group_diagnostics', $noteLabels);
        $this->assertContains('db_query_result', $noteLabels);

        $this->assertArrayHasKey('_is_logged_in', $data);

        unset($_COOKIE['fchub_mc_currency'], $_GET['_debug_timing']);
    }
}
