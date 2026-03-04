<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\ValueObjects;

use FChubMultiCurrency\Domain\Enums\CurrencyPosition;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CurrencyTest extends TestCase
{
    #[Test]
    public function testFromArrayCreatesInstance(): void
    {
        $currency = Currency::from([
            'code'     => 'eur',
            'name'     => 'Euro',
            'symbol'   => '€',
            'decimals' => 2,
            'position' => 'right',
        ]);

        $this->assertSame('EUR', $currency->code);
        $this->assertSame('Euro', $currency->name);
        $this->assertSame('€', $currency->symbol);
        $this->assertSame(2, $currency->decimals);
        $this->assertSame(CurrencyPosition::Right, $currency->position);
    }

    #[Test]
    public function testCodeIsUppercased(): void
    {
        $currency = Currency::from([
            'code' => 'gbp', 'name' => 'Pound', 'symbol' => '£',
        ]);

        $this->assertSame('GBP', $currency->code);
    }

    #[Test]
    public function testDefaultsDecimalsToTwo(): void
    {
        $currency = Currency::from([
            'code' => 'USD', 'name' => 'Dollar', 'symbol' => '$',
        ]);

        $this->assertSame(2, $currency->decimals);
    }

    #[Test]
    public function testIsBaseReturnsTrueForMatchingCode(): void
    {
        $currency = Currency::from([
            'code' => 'USD', 'name' => 'Dollar', 'symbol' => '$',
        ]);

        $this->assertTrue($currency->isBase('USD'));
        $this->assertTrue($currency->isBase('usd'));
    }

    #[Test]
    public function testIsBaseReturnsFalseForDifferentCode(): void
    {
        $currency = Currency::from([
            'code' => 'USD', 'name' => 'Dollar', 'symbol' => '$',
        ]);

        $this->assertFalse($currency->isBase('EUR'));
    }
}
