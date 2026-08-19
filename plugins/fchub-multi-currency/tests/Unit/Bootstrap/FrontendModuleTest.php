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
        // No rate row in this fixture, so USD is not selectable. `allowedCurrencyCodes`
        // used to list it anyway; the table only offers what a visitor can actually get.
        $this->assertSame(['EUR'], array_keys($config['currencyTable']));
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
     * One read of the rate table serves a whole page.
     *
     * The table warms the rate cache in one read, and everything after it — the
     * config, each switcher, each block — answers from that. Two switchers on a page
     * must not cost two reads, and twenty-five currencies must not cost twenty-five.
     */
    #[Test]
    public function testAPageRenderReadsTheRateTableOnce(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow($this->rateRow());
        $this->setWpdbMockResults([
            ['base_currency' => 'EUR', 'quote_currency' => 'USD'] + $this->rateRow(),
        ]);
        FrontendModule::registerAssets();

        $GLOBALS['wpdb']->resetQueries();
        FrontendModule::enqueueProjectionAssets();
        FrontendModule::renderSwitcher([]);
        FrontendModule::renderSwitcher([]);

        // The wpdb mock records prepare() templates alongside the executed query, so
        // the unsubstituted ones are filtered out rather than counted twice.
        $reads = array_filter(
            $GLOBALS['wpdb']->queries,
            static fn(string $query): bool => str_contains($query, 'rate_history')
                && str_contains($query, 'SELECT')
                && !str_contains($query, '%i'),
        );

        $this->assertLessThanOrEqual(1, count($reads), 'A page render should read the rate table once.');
    }

    /**
     * The invariant the whole change rests on, now assertable over the whole config.
     *
     * Storefront HTML goes into a shared cache, so nothing the browser reads out of
     * it may describe whoever warmed that cache. There is no allow-list here any
     * more: every byte must match.
     */
    #[Test]
    public function testTheWholeConfigIsByteIdenticalForGuestsWithDifferentCookies(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow($this->rateRow());

        $_COOKIE[Constants::COOKIE_KEY] = 'EUR';
        $this->resetResolvedContext();
        $first = wp_json_encode(FrontendModule::buildFrontendConfig());

        $_COOKIE[Constants::COOKIE_KEY] = 'USD';
        $this->resetResolvedContext();
        $second = wp_json_encode(FrontendModule::buildFrontendConfig());

        $this->assertSame($first, $second);
    }

    /**
     * The other half: a resolved display currency in a cached document is somebody
     * else's answer, so none of these may be baked in at all.
     */
    #[Test]
    public function testConfigCarriesNoResolvedDisplayCurrency(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow($this->rateRow());
        $_COOKIE[Constants::COOKIE_KEY] = 'EUR';
        $this->resetResolvedContext();

        $config = FrontendModule::buildFrontendConfig();

        foreach ([
            'rate',
            'displayCurrency',
            'displayCurrencyName',
            'symbol',
            'position',
            'decimals',
            'isBaseDisplay',
            'resolverSource',
            'displayDecSep',
            'displayThousandSep',
            'disclosureEnabled',
            'disclosureText',
            'allowedCurrencyCodes',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $config, "Leaked visitor state: {$key}");
        }
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
     * A store that never touched the setting shows visitors its own base
     * currency: the shipped default is "follow the base", not a hardcoded USD
     * that happens to be wrong on every non-USD store.
     */
    #[Test]
    public function testAFreshStoreDefaultsTheDisplayCurrencyToItsOwnBase(): void
    {
        \FluentCart\Api\CurrencySettings::setMock(['currency' => 'EUR']);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertSame('EUR', $config['baseCurrency']);
        $this->assertSame('', $config['defaultCurrency'], 'Empty means: the browser falls through to the base.');
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

    /**
     * The locale map is a store fact — offered currencies and their zones —
     * so it is safe in cached HTML. It ships only while the merchant enabled
     * detection; an empty map otherwise keeps untouched stores byte-stable.
     */
    #[Test]
    public function testConfigShipsTheLocaleMapOnlyWhenGeoIsEnabled(): void
    {
        CurrencySettings::setMock(['currency' => 'EUR']);
        $this->setOption('fchub_mc_settings', [
            'geo_enabled' => 'yes',
            'base_currency' => 'EUR',
            'display_currencies' => [
                ['code' => 'PLN', 'name' => 'Polish Złoty', 'symbol' => 'zł', 'decimals' => 2, 'position' => 'right'],
            ],
        ]);
        $this->setWpdbMockRow([
            'base_currency' => 'EUR', 'quote_currency' => 'PLN', 'rate' => '4.30000000',
            'provider' => 'manual', 'fetched_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertTrue($config['geoEnabled']);
        $this->assertSame('PLN', $config['localeCurrencies']['Europe/Warsaw']);
        $this->assertSame('EUR', $config['localeCurrencies']['Europe/Berlin']);

        $this->setOption('fchub_mc_settings', ['geo_enabled' => 'no']);
        $config = FrontendModule::buildFrontendConfig();

        $this->assertFalse($config['geoEnabled']);
        $this->assertSame([], $config['localeCurrencies']);
    }

    /**
     * The charm rule is a store fact, so it rides the cacheable config once
     * rather than being copied into every currency-table entry.
     */
    #[Test]
    public function testFrontendConfigShipsTheCharmRuleAsAStoreFact(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings() + ['charm_rounding' => 'ending_95']);
        $this->setWpdbMockRow(null);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertSame('ending_95', $config['charmRounding']);
        foreach ($config['currencyTable'] as $entry) {
            $this->assertArrayNotHasKey('charmRounding', $entry);
        }
    }

    #[Test]
    public function testFrontendConfigFallsBackToNoCharmRounding(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow(null);

        $this->assertSame('none', FrontendModule::buildFrontendConfig()['charmRounding']);
    }
}
