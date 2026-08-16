<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Pub;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Http\Controllers\Pub\ContextController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ContextControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ContextModule::resetChain();
    }

    #[Test]
    public function testGetRecoversAnAllowedCookieCurrencyWithoutThePublicUrlResolver(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'url_param_enabled' => 'no',
            'url_param_key' => 'money',
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '&euro;',
                'decimals' => 2,
                'position' => 'right_space',
                'decimal_separator' => ',',
                'thousand_separator' => '.',
            ]],
        ]);
        $this->setWpdbMockRow([
            'base_currency' => 'USD',
            'quote_currency' => 'EUR',
            'rate' => '0.92000000',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);

        $request = new \WP_REST_Request('GET', '/');
        $request->set_param('currency', 'eur');

        $response = (new ContextController())->get($request);
        $data = $response->get_data()['data'];

        $this->assertSame(200, $response->get_status());
        $this->assertSame('EUR', $data['display_currency']);
        $this->assertSame('cookie', $data['source']);
        $this->assertSame('EUR', $data['context']['displayCurrency']);
        $this->assertSame('Euro', $data['context']['displayCurrencyName']);
        $this->assertSame('€', $data['context']['symbol']);
        $this->assertSame(0.92, $data['context']['rate']);
        $this->assertSame(',', $data['context']['displayDecSep']);
        $this->assertSame('.', $data['context']['displayThousandSep']);
        $this->assertFalse($data['context']['isBaseDisplay']);
        $this->assertArrayHasKey('presentation', $data['context']);
        $this->assertStringContainsString('alt="EUR"', $data['context']['presentation']['flag']);
        $this->assertArrayNotHasKey('rateValue', $data['context']);
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
    }

    #[Test]
    public function testExplicitRecoveryHonoursThePublicContextFilter(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'right_space',
            ]],
        ]);
        $this->setWpdbMockRow([
            'base_currency' => 'USD',
            'quote_currency' => 'EUR',
            'rate' => '0.92000000',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);
        add_filter('fchub_mc/context', static function (CurrencyContext $context): CurrencyContext {
            return new CurrencyContext(
                displayCurrency: new Currency(
                    code: $context->displayCurrency->code,
                    name: 'Filtered Euro',
                    symbol: $context->displayCurrency->symbol,
                    decimals: $context->displayCurrency->decimals,
                    position: $context->displayCurrency->position,
                ),
                baseCurrency: $context->baseCurrency,
                rate: $context->rate,
                source: $context->source,
                isBaseDisplay: $context->isBaseDisplay,
            );
        });
        $request = new \WP_REST_Request('GET', '/');
        $request->set_param('currency', 'EUR');

        $response = (new ContextController())->get($request);

        $this->assertSame('Filtered Euro', $response->get_data()['data']['context']['displayCurrencyName']);
    }

    #[Test]
    public function testGetPreservesLegacyFieldsAndAddsTheBrowserContextForNormalResolution(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'default_display_currency' => 'EUR',
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'right_space',
            ]],
        ]);
        $this->setWpdbMockRow([
            'base_currency' => 'USD',
            'quote_currency' => 'EUR',
            'rate' => '0.92000000',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);

        $response = (new ContextController())->get(new \WP_REST_Request('GET', '/'));
        $data = $response->get_data()['data'];

        $this->assertSame('EUR', $data['display_currency']);
        $this->assertSame('USD', $data['base_currency']);
        $this->assertSame('0.92000000', $data['rate']);
        $this->assertSame('default', $data['source']);
        $this->assertFalse($data['is_base_display']);
        $this->assertSame('EUR', $data['context']['displayCurrency']);
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
    }

    #[Test]
    public function testGetRecoversTheBaseCurrencyInsteadOfTreatingRateOneAsNoop(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'default_display_currency' => 'EUR',
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'right_space',
            ]],
        ]);

        $request = new \WP_REST_Request('GET', '/');
        $request->set_param('currency', 'USD');

        $response = (new ContextController())->get($request);
        $data = $response->get_data()['data'];

        $this->assertSame(200, $response->get_status());
        $this->assertSame('USD', $data['display_currency']);
        $this->assertSame('cookie', $data['source']);
        $this->assertSame(1.0, $data['context']['rate']);
        $this->assertTrue($data['context']['isBaseDisplay']);
    }

    #[Test]
    public function testGetRejectsAnUnknownRecoveryCurrencyWithoutCachingTheFailure(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'display_currencies' => [['code' => 'EUR']],
        ]);

        $request = new \WP_REST_Request('GET', '/');
        $request->set_param('currency', 'GBP');

        $response = (new ContextController())->get($request);

        $this->assertSame(422, $response->get_status());
        $this->assertSame(ContextController::CODE_INVALID_CURRENCY, $response->get_data()['data']['code']);
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
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
    public function testSetReportsFailureWhenTheCookieHeaderCannotBeWritten(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'cookie_enabled' => 'yes',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $GLOBALS['fchub_mc_setcookie_result'] = false;
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['currency' => 'EUR']);

        $response = (new ContextController())->set($request);
        $data = $response->get_data()['data'];

        $this->assertSame(409, $response->get_status());
        $this->assertSame(ContextController::CODE_PERSISTENCE_UNAVAILABLE, $data['code']);
        $this->assertFalse($data['persisted']);
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
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
    }

    #[Test]
    public function testOutcomeCodesAreDistinct(): void
    {
        $codes = array_column(self::outcomeCodeProvider(), 2);

        $this->assertSame($codes, array_unique($codes), 'Two outcomes share a code, so clients cannot tell them apart.');
    }
}
