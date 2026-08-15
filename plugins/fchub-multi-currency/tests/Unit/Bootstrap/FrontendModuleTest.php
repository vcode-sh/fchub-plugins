<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class FrontendModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Some tests below vary cookie_enabled, which changes which resolvers
        // buildResolverChain() puts in the chain — reset the static cache so an
        // earlier test's settings can't leak into a later one.
        ContextModule::resetChain();
    }

    /**
     * @return array<string, mixed>
     */
    private function switcherSettings(): array
    {
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
    public function testRenderSwitcherShowsBaseCurrencyOptionAndSelectedCodeWhenBaseIsChosen(): void
    {
        $_GET = [];
        $_COOKIE = [];

        $this->setOption('fchub_mc_settings', $this->switcherSettings());

        // No EUR->USD rate available, so context should fall back to base (EUR).
        $this->setWpdbMockRow(null);

        $html = FrontendModule::renderSwitcher([]);

        $this->assertStringContainsString('class="fchub-mc-switcher__code">EUR</span>', $html);
        $this->assertStringContainsString('data-value="EUR"', $html);
        $this->assertStringContainsString('data-value="USD"', $html);
    }

    /**
     * guestLocalStorageEnabled gates the client-side localStorage fallback (issue #72)
     * on cookie_enabled: a site owner who disabled guest persistence outright must not
     * get it back through a side door.
     */
    #[Test]
    public function testFrontendConfigExposesGuestLocalStorageEnabledFollowingCookieSetting(): void
    {
        $settings = $this->switcherSettings();
        $settings['cookie_enabled'] = 'yes';
        $this->setOption('fchub_mc_settings', $settings);
        $this->setWpdbMockRow(null);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertTrue($config['guestLocalStorageEnabled']);
    }

    #[Test]
    public function testFrontendConfigDisablesGuestLocalStorageWhenCookiePersistenceIsDisabled(): void
    {
        $settings = $this->switcherSettings();
        $settings['cookie_enabled'] = 'no';
        $this->setOption('fchub_mc_settings', $settings);
        $this->setWpdbMockRow(null);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertFalse($config['guestLocalStorageEnabled']);
    }

    /**
     * The frontend config's `source` mirrors ResolverSource so the client can tell a
     * cookie/geo/fallback resolution (which a stripped cookie could have caused) apart
     * from an explicit url_param or a signed-in user's saved preference — the guard
     * currency-projection.js uses before ever trusting a localStorage value.
     */
    #[Test]
    public function testFrontendConfigExposesResolvedContextSource(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow(null);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertSame('default', $config['source']);
    }

    #[Test]
    public function testBuildPricingConfigCarriesPerCurrencyFormattingFields(): void
    {
        $this->setOption('fchub_mc_settings', $this->switcherSettings());
        $this->setWpdbMockRow([
            'base_currency'  => 'EUR',
            'quote_currency' => 'USD',
            'rate'           => '1.10000000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);
        $_COOKIE['fchub_mc_currency'] = 'USD';

        $optionStore = new \FChubMultiCurrency\Storage\OptionStore();
        $service = new \FChubMultiCurrency\Domain\Services\CurrencyContextService(
            \FChubMultiCurrency\Bootstrap\Modules\ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );
        $context = $service->resolve();

        $pricing = FrontendModule::buildPricingConfig($context, $optionStore);

        $this->assertSame('USD', $pricing['displayCurrency']);
        $this->assertSame('US Dollar', $pricing['displayCurrencyName']);
        $this->assertSame('$', $pricing['symbol']);
        $this->assertSame(2, $pricing['decimals']);
        $this->assertSame('left', $pricing['position']);
        $this->assertSame('cookie', $pricing['source']);
        $this->assertFalse($pricing['isBaseDisplay']);

        unset($_COOKIE['fchub_mc_currency']);
    }
}
