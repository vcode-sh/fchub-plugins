<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class FrontendModuleTest extends TestCase
{
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
}
