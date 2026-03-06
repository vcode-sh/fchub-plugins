<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit;

use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FormatPriceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the cached resolver chain between tests
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
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

        $result = \fchub_mc_format_price(100.00);

        // 100.00 * 0.92 = 92.00 → formatted as "EUR 92.00"
        $this->assertStringContainsString('92.00', $result);
        $this->assertStringContainsString('EUR', $result);
    }
}
