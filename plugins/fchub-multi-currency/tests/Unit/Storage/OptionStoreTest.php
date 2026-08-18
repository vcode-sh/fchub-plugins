<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Storage;

use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class OptionStoreTest extends TestCase
{
    /**
     * all() merges defaults, normalizes every configured currency and asks
     * FluentCart for the base code. A storefront render asks for settings
     * dozens of times, so the merge must happen once per request, not once
     * per question.
     */
    #[Test]
    public function testRepeatedReadsMergeTheSettingsOnce(): void
    {
        $this->setOption('fchub_mc_settings', ['rounding_mode' => 'ceil']);
        $store = new OptionStore();

        $store->all();
        $store->all();
        $store->get('rounding_mode');
        $store->get('base_currency');
        (new OptionStore())->all();

        $this->assertSame(1, CurrencySettings::$reads, 'One merge per request, however many readers ask.');
    }

    #[Test]
    public function testSaveInvalidatesTheMemoizedSettings(): void
    {
        $store = new OptionStore();
        $this->assertSame('half_up', $store->get('rounding_mode'));

        $store->save(['rounding_mode' => 'floor']);

        $this->assertSame('floor', $store->get('rounding_mode'));
    }

    #[Test]
    public function testEnsureExplicitRateProviderInvalidatesTheMemoizedSettings(): void
    {
        $store = new OptionStore();
        $this->assertNull($store->get('rate_provider_explicitly_missing'));
        $before = $store->get('rate_provider');

        $store->ensureExplicitRateProvider();

        $this->assertSame('manual', $store->get('rate_provider'));
    }

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
