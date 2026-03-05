<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Resolvers;

use FChubMultiCurrency\Domain\Resolvers\UrlParamResolver;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class UrlParamResolverTest extends TestCase
{
    #[Test]
    public function testReturnsNullWhenParamNotPresent(): void
    {
        unset($_GET['currency']);
        $resolver = new UrlParamResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertNull($result);
    }

    #[Test]
    public function testReturnsCurrencyCodeWhenValid(): void
    {
        $_GET['currency'] = 'eur';
        $resolver = new UrlParamResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR'], ['code' => 'GBP']]);

        $this->assertSame('EUR', $result);

        unset($_GET['currency']);
    }

    #[Test]
    public function testReturnsNullWhenCurrencyNotEnabled(): void
    {
        $_GET['currency'] = 'JPY';
        $resolver = new UrlParamResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertNull($result);

        unset($_GET['currency']);
    }

    #[Test]
    public function testAllowsBaseCurrencyEvenWhenNotInDisplayList(): void
    {
        $_GET['currency'] = 'usd';
        $resolver = new UrlParamResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertSame('USD', $result);

        unset($_GET['currency']);
    }
}
