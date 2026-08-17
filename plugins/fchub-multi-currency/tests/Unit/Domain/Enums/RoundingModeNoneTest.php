<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Enums;

use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RoundingModeNoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset cached resolver chain
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setValue(null, null);

        \FChubMultiCurrency\Domain\Services\CurrencyContextService::reset();

        $_GET = [];
        $_COOKIE = [];
    }

    #[Test]
    public function testNoneRoundingProducesCorrectDecimalPlaces(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'rounding_mode'      => 'none',
            'display_currencies' => [
                ['code' => 'PLN', 'name' => 'Polish Zloty', 'symbol' => 'zl', 'decimals' => 2, 'position' => 'right'],
            ],
        ]);

        // Rate: 4.3217 (USD -> PLN)
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'PLN',
            'rate'           => '4.32170000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);

        $_COOKIE['fchub_mc_currency'] = 'PLN';

        $optionStore = new \FChubMultiCurrency\Storage\OptionStore();
        $chain = \FChubMultiCurrency\Bootstrap\Modules\ContextModule::buildResolverChain($optionStore);
        $service = new \FChubMultiCurrency\Domain\Services\CurrencyContextService($chain, $optionStore);
        $service->resolve();

        // 10000 cents * 4.3217 = 43217 cents → PLN 432.17.
        $result = \fchub_mc_format_price(10000.0);

        $this->assertSame('432.17zl', $result);
    }

    #[Test]
    public function testNoneRoundingTruncatesExtraDecimalPlaces(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'rounding_mode'      => 'none',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        // Rate that produces fractional cents: 10000 * 0.33333333 = 3333.33330000.
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.33333333',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);

        $_COOKIE['fchub_mc_currency'] = 'EUR';

        $optionStore = new \FChubMultiCurrency\Storage\OptionStore();
        $chain = \FChubMultiCurrency\Bootstrap\Modules\ContextModule::buildResolverChain($optionStore);
        $service = new \FChubMultiCurrency\Domain\Services\CurrencyContextService($chain, $optionStore);
        $service->resolve();

        $result = \fchub_mc_format_price(10000.0);

        $this->assertStringContainsString('33.33', $result);
    }

    #[Test]
    public function testNoneRoundingWithZeroDecimalsCurrency(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'rounding_mode'      => 'none',
            'display_currencies' => [
                ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0, 'position' => 'left'],
            ],
        ]);

        // Rate: 149.85 (USD -> JPY)
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'JPY',
            'rate'           => '149.85000000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);

        $_COOKIE['fchub_mc_currency'] = 'JPY';

        $optionStore = new \FChubMultiCurrency\Storage\OptionStore();
        $chain = \FChubMultiCurrency\Bootstrap\Modules\ContextModule::buildResolverChain($optionStore);
        $service = new \FChubMultiCurrency\Domain\Services\CurrencyContextService($chain, $optionStore);
        $service->resolve();

        // 1000 cents * 149.85 = 149850 cents → JPY 1498.50 in FluentCart's storage scale.
        $result = \fchub_mc_format_price(1000.0);

        $this->assertSame('¥1,498', $result);
    }

    #[Test]
    public function testNoneRoundingTruncatesNotRounds(): void
    {
        // Setup with a fractional-cent value where truncation differs from rounding.
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'rounding_mode'      => 'none',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.33337000',
            'provider'       => 'manual',
            'fetched_at'     => gmdate('Y-m-d H:i:s'),
        ]);

        $_COOKIE['fchub_mc_currency'] = 'EUR';

        $optionStore = new \FChubMultiCurrency\Storage\OptionStore();
        $chain = \FChubMultiCurrency\Bootstrap\Modules\ContextModule::buildResolverChain($optionStore);
        $service = new \FChubMultiCurrency\Domain\Services\CurrencyContextService($chain, $optionStore);
        $service->resolve();

        // 10000 cents * 0.33337 = 3333.7 cents → 33.33, not 33.34.
        $result = \fchub_mc_format_price(10000.0);

        $this->assertStringContainsString('33.33', $result);
        $this->assertStringNotContainsString('33.34', $result);
    }
}
