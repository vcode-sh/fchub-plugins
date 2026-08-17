<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit;

use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class FormatPriceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the cached resolver chain between tests
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setValue(null, null);

        // Reset the cached resolved context
        \FChubMultiCurrency\Domain\Services\CurrencyContextService::reset();

        $_GET = [];
        $_COOKIE = [];
    }

    #[Test]
    public function testDoesNotCrashWhenContextIsCached(): void
    {
        // Simulate a resolved context already being cached (the $optionStore bug fix).
        // Previously, calling fchub_mc_format_price() when CurrencyContextService
        // had a cached context would crash because $optionStore was undefined.
        $this->setOption('fchub_mc_settings', [
            'enabled'          => 'yes',
            'base_currency'    => 'USD',
            'rounding_mode'    => 'half_up',
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

        // Pre-resolve context so it's cached
        $optionStore = new \FChubMultiCurrency\Storage\OptionStore();
        $chain = \FChubMultiCurrency\Bootstrap\Modules\ContextModule::buildResolverChain($optionStore);
        $service = new \FChubMultiCurrency\Domain\Services\CurrencyContextService($chain, $optionStore);
        $service->resolve();

        // This should not throw — the fix ensures $optionStore is always created
        $result = \fchub_mc_format_price(100.00);

        $this->assertIsString($result);
    }

    #[Test]
    public function testFormatsConvertedPriceWithCachedContext(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'          => 'yes',
            'base_currency'    => 'USD',
            'rounding_mode'    => 'half_up',
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

        // Set cookie to trigger EUR resolution (otherwise falls back to base USD)
        $_COOKIE['fchub_mc_currency'] = 'EUR';

        // Pre-resolve context so it's cached
        $optionStore = new \FChubMultiCurrency\Storage\OptionStore();
        $chain = \FChubMultiCurrency\Bootstrap\Modules\ContextModule::buildResolverChain($optionStore);
        $service = new \FChubMultiCurrency\Domain\Services\CurrencyContextService($chain, $optionStore);
        $service->resolve();

        $result = \fchub_mc_format_price(10000.0);

        // FluentCart prices are cents: 10000 * 0.92 = 9200 cents → EUR 92.00.
        $this->assertSame('€92.00', $result);
    }

    #[Test]
    public function testBaseCurrencyFallbackKeepsFluentCartsCentContract(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled' => 'yes',
            'base_currency' => 'USD',
            'display_currencies' => [],
        ]);

        $result = \fchub_mc_format_price(12345.0);

        $this->assertStringContainsString('123.45', $result);
    }

    #[Test]
    public function testDisplayDecimalsDoNotInheritAZeroDecimalBaseCurrency(): void
    {
        CurrencySettings::setMock([
            'currency' => 'JPY',
            'currency_position' => 'before',
            'is_zero_decimal' => true,
        ]);
        $this->setOption('fchub_mc_settings', [
            'enabled' => 'yes',
            'base_currency' => 'USD',
            'rounding_mode' => 'half_up',
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'right_space',
                'decimal_separator' => ',',
                'thousand_separator' => '.',
            ]],
        ]);
        $this->setWpdbMockRow([
            'base_currency' => 'JPY',
            'quote_currency' => 'EUR',
            'rate' => '0.00625000',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);
        $_COOKIE['fchub_mc_currency'] = 'EUR';

        $result = \fchub_mc_format_price(100000.0);

        $this->assertSame('6,25 €', $result);
    }

    #[Test]
    public function testPhpFormatterHonoursThreeDecimalDisplayCurrencies(): void
    {
        CurrencySettings::setMock(['currency' => 'USD']);
        $this->setOption('fchub_mc_settings', [
            'enabled' => 'yes',
            'base_currency' => 'USD',
            'rounding_mode' => 'half_up',
            'display_currencies' => [[
                'code' => 'KWD',
                'name' => 'Kuwaiti Dinar',
                'symbol' => 'KD',
                'decimals' => 3,
                'position' => 'left_space',
                'decimal_separator' => '.',
                'thousand_separator' => ',',
            ]],
        ]);
        $this->setWpdbMockRow([
            'base_currency' => 'USD',
            'quote_currency' => 'KWD',
            'rate' => '0.30712500',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);
        $_COOKIE['fchub_mc_currency'] = 'KWD';

        $result = \fchub_mc_format_price(12345.0);

        $this->assertSame('KD 37.915', $result);
    }

    #[Test]
    public function testPhpFormatterKeepsTheMinusBeforeTheCurrencyAndCanDisableGrouping(): void
    {
        CurrencySettings::setMock(['currency' => 'USD']);
        $this->setOption('fchub_mc_settings', [
            'enabled' => 'yes',
            'base_currency' => 'USD',
            'rounding_mode' => 'half_down',
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'left_space',
                'decimal_separator' => ',',
                'thousand_separator' => 'none',
            ]],
        ]);
        $this->setWpdbMockRow([
            'base_currency' => 'USD',
            'quote_currency' => 'EUR',
            'rate' => '1.00000000',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);
        $_COOKIE['fchub_mc_currency'] = 'EUR';

        $result = \fchub_mc_format_price(-123456.5);

        $this->assertSame('-€ 1234,56', $result);
    }
}
