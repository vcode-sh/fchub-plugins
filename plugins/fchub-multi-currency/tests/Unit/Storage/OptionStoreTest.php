<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Storage;

use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class OptionStoreTest extends TestCase
{
    #[Test]
    public function testSwitcherDefaultsAreDeepMerged(): void
    {
        $this->setOption('fchub_mc_settings', [
            'switcher_defaults' => [
                'preset' => 'glass',
                'show_symbol' => 'yes',
            ],
        ]);

        $settings = (new OptionStore())->all();

        $this->assertSame('glass', $settings['switcher_defaults']['preset']);
        $this->assertSame('yes', $settings['switcher_defaults']['show_symbol']);
        $this->assertArrayHasKey('show_code', $settings['switcher_defaults']);
        $this->assertArrayHasKey('dropdown_position', $settings['switcher_defaults']);
    }

    #[Test]
    public function testFluentCartCurrencyOverridesAStalePluginBaseCurrency(): void
    {
        CurrencySettings::setMock(['currency' => 'EUR']);
        $this->setOption('fchub_mc_settings', ['base_currency' => 'PLN']);

        $settings = (new OptionStore())->all();

        $this->assertSame('EUR', $settings['base_currency']);
    }

    #[Test]
    public function testSaveCannotCreateASecondBaseCurrency(): void
    {
        CurrencySettings::setMock(['currency' => 'EUR']);
        $this->setOption('fchub_mc_settings', ['base_currency' => 'PLN']);

        (new OptionStore())->save(['base_currency' => 'GBP']);

        $saved = get_option('fchub_mc_settings');
        $this->assertSame('EUR', $saved['base_currency']);
    }
}
