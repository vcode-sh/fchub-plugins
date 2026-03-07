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
        $prop->setAccessible(true);
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

        // 100 * 4.3217 = 432.17 — with None rounding and 2 decimals,
        // round($converted, 2) should produce 432.17
        $result = \fchub_mc_format_price(100.00);

        $this->assertStringContainsString('432.17', $result);
        $this->assertStringContainsString('PLN', $result);
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

        // Rate that produces many decimal places: 100 * 0.33333333 = 33.33333300
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

        // 100 * 0.33333333 = 33.333333
        // round(33.333333, 2) = 33.33 (rounds to 2 decimal places)
        $result = \fchub_mc_format_price(100.00);

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

        // 10 * 149.85 = 1498.5
        // Truncation with 0 decimals: floor(1498.5) = 1498
        $result = \fchub_mc_format_price(10.00);

        // Should contain the integer value, no decimal places
        $this->assertStringContainsString('JPY', $result);
        $this->assertMatchesRegularExpression('/1,?498/', $result);
    }

    #[Test]
    public function testNoneRoundingTruncatesNotRounds(): void
    {
        // Setup with rate that produces a value where truncation != rounding
        // 100 * 0.33337 = 33.337 — truncation gives 33.33, rounding gives 33.34
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

        // 100 * 0.33337 = 33.337 → truncated to 33.33, NOT rounded to 33.34
        $result = \fchub_mc_format_price(100.00);

        $this->assertStringContainsString('33.33', $result);
        $this->assertStringNotContainsString('33.34', $result);
    }
}
