<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\ValueObjects;

use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExchangeRateTest extends TestCase
{
    #[Test]
    public function testFromArrayCreatesInstance(): void
    {
        $rate = ExchangeRate::from([
            'base_currency'  => 'usd',
            'quote_currency' => 'eur',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => '2026-01-01 12:00:00',
        ]);

        $this->assertSame('USD', $rate->baseCurrency);
        $this->assertSame('EUR', $rate->quoteCurrency);
        $this->assertSame('0.92000000', $rate->rate);
        $this->assertSame(RateProvider::Manual, $rate->provider);
    }

    #[Test]
    public function testRateAsFloatReturnsFloat(): void
    {
        $rate = ExchangeRate::from([
            'base_currency' => 'USD', 'quote_currency' => 'EUR',
            'rate' => '0.92345678', 'provider' => 'manual',
        ]);

        $this->assertEqualsWithDelta(0.92345678, $rate->rateAsFloat(), 0.00000001);
    }

    #[Test]
    public function testIsStaleReturnsTrueWhenOld(): void
    {
        $rate = ExchangeRate::from([
            'base_currency' => 'USD', 'quote_currency' => 'EUR',
            'rate' => '0.92', 'provider' => 'manual',
            'fetched_at' => date('Y-m-d H:i:s', time() - 7200),
        ]);

        $this->assertTrue($rate->isStale(3600));
    }

    #[Test]
    public function testIsStaleReturnsFalseWhenFresh(): void
    {
        $rate = ExchangeRate::from([
            'base_currency' => 'USD', 'quote_currency' => 'EUR',
            'rate' => '0.92', 'provider' => 'manual',
            'fetched_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertFalse($rate->isStale(3600));
    }
}
