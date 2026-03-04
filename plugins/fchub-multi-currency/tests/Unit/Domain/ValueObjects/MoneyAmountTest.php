<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\ValueObjects;

use FChubMultiCurrency\Domain\ValueObjects\MoneyAmount;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MoneyAmountTest extends TestCase
{
    #[Test]
    public function testFromFloatConvertsToMinorUnits(): void
    {
        $amount = MoneyAmount::fromFloat(29.99, 'USD');

        $this->assertSame(2999, $amount->minorUnits);
        $this->assertSame('USD', $amount->currencyCode);
    }

    #[Test]
    public function testToFloatConvertsFromMinorUnits(): void
    {
        $amount = new MoneyAmount(minorUnits: 2999, currencyCode: 'USD');

        $this->assertEqualsWithDelta(29.99, $amount->toFloat(), 0.001);
    }

    #[Test]
    public function testZeroDecimalsCurrency(): void
    {
        $amount = MoneyAmount::fromFloat(1000, 'JPY', 0);

        $this->assertSame(1000, $amount->minorUnits);
    }

    #[Test]
    public function testCurrencyCodeIsUppercased(): void
    {
        $amount = MoneyAmount::fromFloat(10, 'eur');

        $this->assertSame('EUR', $amount->currencyCode);
    }
}
