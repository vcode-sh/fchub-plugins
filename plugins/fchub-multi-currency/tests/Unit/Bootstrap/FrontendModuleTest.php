<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class FrontendModuleTest extends TestCase
{
    /**
     * Only the bootstrap belongs in the head, and only because it decides the
     * currency before anything paints. Everything else paints or converts, which
     * cannot happen before the body exists, so it loads at the end where it costs
     * no render-blocking request.
     */
    #[Test]
    public function testOnlyTheCurrencyDecisionLoadsInTheHead(): void
    {
        FrontendModule::registerAssets();

        $this->assertFalse($GLOBALS['wp_registered_scripts']['fchub-mc-bootstrap']['in_footer']);
        $this->assertFalse($GLOBALS['wp_registered_scripts']['fchub-mc-bootstrap']['src'], 'Inline, not a request.');
        $this->assertTrue($GLOBALS['wp_registered_scripts']['fchub-mc-context']['in_footer']);
        $this->assertSame(
            ['fchub-mc-bootstrap'],
            $GLOBALS['wp_registered_scripts']['fchub-mc-context']['deps'],
        );
        $this->assertSame(
            ['fchub-mc-context'],
            $GLOBALS['wp_registered_scripts']['fchub-mc-projection']['deps'],
        );
        $this->assertSame(
            ['fchub-mc-context'],
            $GLOBALS['wp_registered_scripts']['fchub-mc-switcher']['deps'],
        );
    }

    #[Test]
    public function testProjectionEnqueuesOnlyItsRecoveryShieldStylesWithoutAVisibleSwitcher(): void
    {
        FrontendModule::registerAssets();
        FrontendModule::enqueueProjectionAssets();

        $this->assertScriptEnqueued('fchub-mc-context');
        $this->assertScriptEnqueued('fchub-mc-projection');
        $this->assertStyleEnqueued('fchub-mc-critical');
        $this->assertNotContains('fchub-mc-switcher', $GLOBALS['wp_enqueued_styles']);
    }

    /**
     * @return array<string, mixed>
     */
    private function switcherSettings(): array
    {
        CurrencySettings::setMock(['currency' => 'EUR']);

        return [
            'enabled' => 'yes',
            'base_currency' => 'EUR',
            'default_display_currency' => 'USD',
            'display_currencies' => [
                [
                    'code' => 'USD',
                    'name' => 'US Dollar',
                    'symbol' => '$',
                    'decimals' => 2,
                    'position' => 'left',
                ],
            ],
        ];
    }

    #[Test]
    public function testFrontendConfigFollowsFluentCartDecimalSeparatorWhenCurrencySeparatorDisagrees(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow(null);

        CurrencySettings::setMock([
            'currency' => 'PLN',
            'currency_sign' => 'zł',
            'currency_position' => 'after',
            'currency_separator' => 'dot',
            'decimal_separator' => 'comma',
        ]);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertSame(',', $config['baseDecimalSep']);
        $this->assertSame('.', $config['baseThousandSep']);
    }

    #[Test]
    public function testFrontendConfigIgnoresStaleCurrencySeparatorWhenDecimalSeparatorIsDot(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow(null);

        CurrencySettings::setMock([
            'currency_separator' => 'comma',
            'decimal_separator' => 'dot',
        ]);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertSame('.', $config['baseDecimalSep']);
        $this->assertSame(',', $config['baseThousandSep']);
    }

    /**
     * An untouched store never stores the `dot` token: FluentCart defaults
     * `decimal_separator` to the character `.`, so only `comma` may flip the
     * pairing.
     */
    #[Test]
    public function testFrontendConfigTreatsFluentCartsCharacterDefaultAsTheDotPairing(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow(null);

        CurrencySettings::setMock(['decimal_separator' => '.']);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertSame('.', $config['baseDecimalSep']);
        $this->assertSame(',', $config['baseThousandSep']);
    }

    #[Test]
    public function testFrontendConfigExposesOnlyTheInputsNeededForBrowserRecovery(): void
    {
        $this->setOption('fchub_mc_settings', array_merge($this->switcherSettings(), [
            'cookie_enabled' => 'yes',
            'cookie_lifetime_days' => 30,
            'account_persistence_enabled' => 'yes',
            'url_param_key' => 'money',
        ]));
        $this->setWpdbMockRow(null);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertSame('fchub_mc_currency', $config['cookieName']);
        $this->assertTrue($config['cookiePersistenceEnabled']);
        $this->assertSame(30, $config['cookieLifetimeDays']);
        $this->assertTrue($config['accountPersistenceEnabled']);
        $this->assertFalse($config['isLoggedIn']);
        $this->assertTrue($config['projectionEnabled']);
        $this->assertSame(['EUR', 'USD'], $config['allowedCurrencyCodes']);
        $this->assertTrue($config['urlParamEnabled']);
        $this->assertSame('money', $config['urlParamKey']);
        $this->assertArrayNotHasKey('presentation', $config);
        $this->assertArrayNotHasKey('rateValue', $config);
    }

    /**
     * The rules that must apply before the first paint ship as markup, not as a
     * request. A linked stylesheet is something an optimizer may defer, strip as
     * unused, or simply deliver after the paint it was supposed to govern — and
     * every rule in this file keys off a class that appears in no document.
     */
    #[Test]
    public function testCriticalStylesArePrintedInlineRatherThanFetched(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        FrontendModule::registerAssets();
        FrontendModule::enqueueProjectionAssets();

        $this->assertStyleEnqueued('fchub-mc-critical');
        $this->assertFalse(
            $GLOBALS['wp_registered_styles']['fchub-mc-critical']['src'],
            'A critical stylesheet with a src is a request, and a request can arrive late.',
        );

        $inline = implode('', $GLOBALS['wp_inline_styles']['fchub-mc-critical'] ?? []);
        $this->assertStringContainsString('.fchub-mc-pending', $inline);
        $this->assertStringContainsString('visibility: hidden', $inline);
    }

    /**
     * The invariant this whole change rests on, asserted over the surface that can
     * satisfy it today.
     *
     * Storefront HTML goes into a shared cache, so what the browser reads out of it
     * must not describe whoever happened to warm that cache. The resolved-context
     * fields still merged in above these cannot honour that yet — the current
     * runtime consumes them — so this covers the cacheable surface and widens to the
     * whole config in the change that removes their last reader.
     */
    #[Test]
    public function testTheCacheableSurfaceIsByteIdenticalForGuestsWithDifferentCookies(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow($this->rateRow());

        $_COOKIE[Constants::COOKIE_KEY] = 'EUR';
        $this->resetResolvedContext();
        $first = $this->cacheableSurface(FrontendModule::buildFrontendConfig());

        $_COOKIE[Constants::COOKIE_KEY] = 'USD';
        $this->resetResolvedContext();
        $second = $this->cacheableSurface(FrontendModule::buildFrontendConfig());

        $this->assertSame($first, $second);
    }

    /**
     * The keys a cached document may safely carry, encoded for byte comparison.
     *
     * @param array<string, mixed> $config
     */
    private function cacheableSurface(array $config): string
    {
        return (string) wp_json_encode(array_intersect_key($config, array_flip([
            'currencyTable',
            'baseCurrency',
            'defaultCurrency',
            'allowedCurrencyCodes',
            'nonce',
            'cookieName',
            'cookiePersistenceEnabled',
            'cookieLifetimeDays',
            'accountPersistenceEnabled',
            'urlParamEnabled',
            'urlParamKey',
            'roundingMode',
            'restUrl',
            'flagBaseUrl',
        ])));
    }

    #[Test]
    public function testFrontendConfigShipsTheWholeCurrencyTable(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow($this->rateRow());
        $this->resetResolvedContext();

        $config = FrontendModule::buildFrontendConfig();

        $this->assertSame(['EUR', 'USD'], array_keys($config['currencyTable']));
        $this->assertSame('EUR', $config['baseCurrency']);
        $this->assertSame('USD', $config['defaultCurrency']);
        $this->assertSame(1.0, $config['currencyTable']['EUR']['rate'], 'Base converts at par.');
        $this->assertSame(0.92, $config['currencyTable']['USD']['rate']);
    }

    /**
     * `wp_create_nonce()` is per visitor and per twelve hours. Baking one into a
     * cached page hands every later visitor a nonce that is not theirs, and guests
     * never needed one: the context endpoint's permission callback lets them
     * through. Signed-in visitors are not served from a shared cache, so they keep
     * theirs.
     */
    #[Test]
    public function testFrontendConfigWithholdsTheRestNonceFromGuests(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow($this->rateRow());

        $this->assertSame('', FrontendModule::buildFrontendConfig()['nonce']);

        $this->setCurrentUserId(7);
        $this->resetResolvedContext();
        $this->assertNotSame('', FrontendModule::buildFrontendConfig()['nonce']);
    }

    /**
     * @return array<string, string>
     */
    private function rateRow(): array
    {
        return [
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ];
    }

    #[Test]
    public function testFrontendConfigDoesNotWaitForProjectionWhenThePluginIsDisabled(): void
    {
        $this->setOption('fchub_mc_settings', array_merge($this->switcherSettings(), [
            'enabled' => 'no',
        ]));
        $this->setWpdbMockRow(null);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertFalse($config['projectionEnabled']);
    }

    #[Test]
    public function testContextRuntimeReceivesTypedJsonInsteadOfLocalizedScalarStrings(): void
    {
        $this->setOption('fchub_mc_settings', array_merge($this->switcherSettings(), [
            'cookie_enabled' => 'yes',
            'account_persistence_enabled' => 'yes',
            'url_param_enabled' => 'yes',
        ]));
        $this->setWpdbMockRow(null);

        FrontendModule::registerAssets();
        FrontendModule::ensureContextAssetEnqueued();

        $inline = $GLOBALS['wp_inline_scripts']['fchub-mc-bootstrap']['after'][0] ?? '';
        self::assertMatchesRegularExpression('/^window\.fchubMcConfig = \{.*\};$/', $inline);

        $json = substr($inline, strlen('window.fchubMcConfig = '), -1);
        $config = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($config['cookiePersistenceEnabled']);
        self::assertTrue($config['accountPersistenceEnabled']);
        self::assertTrue($config['urlParamEnabled']);
        self::assertFalse($config['isLoggedIn']);
        self::assertArrayNotHasKey('fchubMcConfig', $GLOBALS['wp_localized_scripts']['fchub-mc-context'] ?? []);
        self::assertStringContainsString(
            'window.fchubMc =',
            $GLOBALS['wp_inline_scripts']['fchub-mc-bootstrap']['after'][1] ?? '',
            'The bootstrap source travels with the config it reads.',
        );
    }

    #[Test]
    public function testRenderSwitcherShowsBaseCurrencyOptionAndSelectedCodeWhenBaseIsChosen(): void
    {
        $_GET = [];
        $_COOKIE = [];

        $this->setOption('fchub_mc_settings', $this->switcherSettings());

        // No EUR->USD rate available, so context should fall back to base (EUR).
        $this->setWpdbMockRow(null);
        FrontendModule::registerAssets();

        $html = FrontendModule::renderSwitcher([]);

        $this->assertStringContainsString('class="fchub-mc-switcher__code">EUR</span>', $html);
        $this->assertStringContainsString('data-value="EUR"', $html);
        $this->assertStringContainsString('data-value="USD"', $html);
        $this->assertNotEmpty($GLOBALS['wp_inline_scripts']['fchub-mc-bootstrap']['after']);
    }
}
