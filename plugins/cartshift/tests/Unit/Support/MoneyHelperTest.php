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
        // FluentCart stores every currency as amount x 100 — Helper::toCent() has no
        // currency argument at all, and Helper::toDecimal() always divides by 100 (the
        // zero-decimal flag only changes the rendered precision). JPY 1000 must therefore
        // be stored as 100000, which FluentCart renders back as "1,000".
        $this->assertSame(100000, MoneyHelper::toCents(1000, 'JPY'));
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

    #[DataProvider('currencyExponentProvider')]
    public function testToCentsIgnoresCurrencyExponent(string $currency): void
    {
        // No currency is exempt from the x100 rule — not the ISO zero-decimal set,
        // not the ISO three-decimal set. FluentCart has no per-currency exponent.
        $this->assertSame(50000, MoneyHelper::toCents(500, $currency));
        $this->assertSame(50000, MoneyHelper::toCents('500', $currency));
    }

    /**
     * ISO zero-decimal and three-decimal currencies — the two groups that would need a
     * different exponent if FluentCart had one. It does not.
     *
     * @return array<string, array{string}>
     */
    public static function currencyExponentProvider(): array
    {
        $currencies = [
            // Zero-decimal.
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
            // Three-decimal.
            'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND',
        ];

        $data = [];
        foreach ($currencies as $currency) {
            $data[$currency] = [$currency];
        }

        return $data;
    }

    public function testToCentsZeroDecimalIsStillMultiplied(): void
    {
        // Regression guard: a previous revision special-cased zero-decimal currencies and
        // stored JPY 1000 as 1000, which FluentCart then rendered as "10" — a 100x
        // under-conversion on every amount in a JPY/KRW/VND store.
        $this->assertSame(100000, MoneyHelper::toCents(1000, 'JPY'));
        $this->assertNotSame(1000, MoneyHelper::toCents(1000, 'JPY'));
    }

    public function testToCentsThreeDecimalCurrencyUsesHundredths(): void
    {
        // KWD 1.234 -> 123. The third decimal is dropped because FluentCart's own storage
        // has nowhere to put it; matching that beats inventing a x1000 format FluentCart
        // would then divide by 100.
        $this->assertSame(123, MoneyHelper::toCents(1.234, 'KWD'));
        $this->assertSame(1235, MoneyHelper::toCents('12.345', 'BHD'));
    }

    public function testToCentsCurrencyArgumentDoesNotChangeResult(): void
    {
        $expected = MoneyHelper::toCents(19.99);

        $this->assertSame($expected, MoneyHelper::toCents(19.99, 'USD'));
        $this->assertSame($expected, MoneyHelper::toCents(19.99, 'JPY'));
        $this->assertSame($expected, MoneyHelper::toCents(19.99, 'jpy'));
        $this->assertSame($expected, MoneyHelper::toCents(19.99, 'KWD'));
        $this->assertSame($expected, MoneyHelper::toCents(19.99, 'NOT-A-CURRENCY'));
    }

    public function testToCentsNoCurrencyDefaultsToStandard(): void
    {
        $this->assertSame(1999, MoneyHelper::toCents(19.99));
    }

    public function testToCentsWithCommaDecimalSeparator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoneyHelper::toCents('19,99', 'USD');
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
        $this->expectException(\InvalidArgumentException::class);
        MoneyHelper::toCents(' 19.99 ', 'USD');
    }

    public function testStrictDecimalParserDoesNotLoseLargeIntegerPrecision(): void
    {
        self::assertSame(900719925474099101, MoneyHelper::decimalToCents('9007199254740991.01'));
    }

    public function testStrictDecimalParserRoundsWithoutBinaryFloatArithmetic(): void
    {
        self::assertSame(2000, MoneyHelper::decimalToCents('19.995'));
        self::assertSame(-2000, MoneyHelper::decimalToCents('-19.995'));
    }

    public function testStrictDecimalParserRejectsOverflow(): void
    {
        $this->expectException(\OverflowException::class);
        MoneyHelper::decimalToCents('92233720368547758.08');
    }
}
