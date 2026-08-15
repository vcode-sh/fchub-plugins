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
}
