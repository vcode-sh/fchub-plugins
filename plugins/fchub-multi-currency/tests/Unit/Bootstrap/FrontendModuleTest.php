<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class FrontendModuleTest extends TestCase
{
    #[Test]
    public function testProjectionAndSwitcherLoadAfterTheSharedContextRecoveryRuntime(): void
    {
        FrontendModule::registerAssets();

        $this->assertArrayHasKey('fchub-mc-context', $GLOBALS['wp_registered_scripts']);
        $this->assertSame(
            ['fchub-mc-context'],
            $GLOBALS['wp_registered_scripts']['fchub-mc-projection']['deps'],
        );
        $this->assertSame(
            ['fchub-mc-context'],
            $GLOBALS['wp_registered_scripts']['fchub-mc-switcher']['deps'],
        );
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
    public function testFrontendConfigExposesOnlyTheInputsNeededForCookieRecovery(): void
    {
        $this->setOption('fchub_mc_settings', array_merge($this->switcherSettings(), [
            'cookie_enabled' => 'yes',
            'account_persistence_enabled' => 'yes',
            'url_param_key' => 'money',
        ]));
        $this->setWpdbMockRow(null);

        $config = FrontendModule::buildFrontendConfig();

        $this->assertSame('fchub_mc_currency', $config['cookieName']);
        $this->assertTrue($config['cookiePersistenceEnabled']);
        $this->assertTrue($config['accountPersistenceEnabled']);
        $this->assertFalse($config['isLoggedIn']);
        $this->assertSame('default', $config['resolverSource']);
        $this->assertSame(['EUR', 'USD'], $config['allowedCurrencyCodes']);
        $this->assertTrue($config['urlParamEnabled']);
        $this->assertSame('money', $config['urlParamKey']);
        $this->assertArrayNotHasKey('presentation', $config);
        $this->assertArrayNotHasKey('rateValue', $config);
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

        $inline = $GLOBALS['wp_inline_scripts']['fchub-mc-context']['before'][0] ?? '';
        self::assertMatchesRegularExpression('/^window\.fchubMcConfig = \{.*\};$/', $inline);

        $json = substr($inline, strlen('window.fchubMcConfig = '), -1);
        $config = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($config['cookiePersistenceEnabled']);
        self::assertTrue($config['accountPersistenceEnabled']);
        self::assertTrue($config['urlParamEnabled']);
        self::assertFalse($config['isLoggedIn']);
        self::assertArrayNotHasKey('fchubMcConfig', $GLOBALS['wp_localized_scripts']['fchub-mc-context'] ?? []);
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
        $this->assertNotEmpty($GLOBALS['wp_inline_scripts']['fchub-mc-context']['before']);
    }
}
