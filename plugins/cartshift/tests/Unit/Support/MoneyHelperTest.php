<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Support\MoneyHelper;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MoneyHelperTest extends PluginTestCase
{
    public function testToCentsStandardCurrency(): void
    {
        $this->assertSame(1999, MoneyHelper::toCents(19.99, 'USD'));
    }

    public function testToCentsZeroDecimalCurrency(): void
    {
        $this->assertSame(1000, MoneyHelper::toCents(1000, 'JPY'));
    }

    public function testToCentsEmptyPrice(): void
    {
        $this->assertSame(0, MoneyHelper::toCents(''));
    }

    public function testToCentsZeroPrice(): void
    {
        $this->assertSame(0, MoneyHelper::toCents('0'));
    }

    public function testToCentsNegativePrice(): void
    {
        $this->assertSame(-550, MoneyHelper::toCents(-5.50, 'USD'));
    }

    public function testToCentsLargeNumber(): void
    {
        $this->assertSame(9999999, MoneyHelper::toCents(99999.99, 'USD'));
    }

    public function testToCentsFloatPrecision(): void
    {
        $this->assertSame(2000, MoneyHelper::toCents(19.995, 'USD'));
    }

    public function testToCentsIntegerInput(): void
    {
        $this->assertSame(10000, MoneyHelper::toCents(100, 'USD'));
    }

    #[DataProvider('zeroDecimalCurrencyProvider')]
    public function testToCentsAllZeroDecimalCurrencies(string $currency): void
    {
        $this->assertSame(500, MoneyHelper::toCents(500, $currency));
        $this->assertSame(500, MoneyHelper::toCents('500', $currency));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function zeroDecimalCurrencyProvider(): array
    {
        $currencies = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];

        $data = [];
        foreach ($currencies as $currency) {
            $data[$currency] = [$currency];
        }

        return $data;
    }

    public function testToCentsZeroDecimalNotMultiplied(): void
    {
        // JPY 1000 should stay 1000, not become 100000
        $this->assertSame(1000, MoneyHelper::toCents(1000, 'JPY'));
        $this->assertNotSame(100000, MoneyHelper::toCents(1000, 'JPY'));
    }

    public function testToCentsLowercaseCurrencyCode(): void
    {
        // Currency codes should be case-insensitive
        $this->assertSame(500, MoneyHelper::toCents(500, 'jpy'));
    }

    public function testToCentsNoCurrencyDefaultsToStandard(): void
    {
        $this->assertSame(1999, MoneyHelper::toCents(19.99));
    }

    public function testToCentsWithCommaDecimalSeparator(): void
    {
        // PHP's floatval() treats comma as end-of-number, so "19,99" becomes 19.0
        // This documents the actual behavior — comma-separated values don't work correctly.
        $result = MoneyHelper::toCents('19,99', 'USD');
        // floatval('19,99') = 19.0, so 19.0 * 100 = 1900
        $this->assertSame(1900, $result);
    }

    public function testToCentsMaxIntBoundary(): void
    {
        // Very large price — verify no overflow or float precision catastrophe.
        $result = MoneyHelper::toCents(999999.99, 'USD');
        $this->assertSame(99999999, $result);
    }

    public function testToCentsStringZero(): void
    {
        // "0" and "0.00" should both return 0.
        $this->assertSame(0, MoneyHelper::toCents('0', 'USD'));
        $this->assertSame(0, MoneyHelper::toCents('0.00', 'USD'));
    }

    public function testToCentsWithWhitespace(): void
    {
        // Whitespace around the number — floatval handles leading whitespace.
        $result = MoneyHelper::toCents(' 19.99 ', 'USD');
        $this->assertSame(1999, $result);
    }
}
