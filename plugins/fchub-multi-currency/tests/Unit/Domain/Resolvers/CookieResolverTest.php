<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Resolvers;

use FChubMultiCurrency\Domain\Resolvers\CookieResolver;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CookieResolverTest extends TestCase
{
    #[Test]
    public function testReturnsNullWhenCookieNotSet(): void
    {
        unset($_COOKIE['fchub_mc_currency']);
        $resolver = new CookieResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertNull($result);
    }

    #[Test]
    public function testReturnsCurrencyFromCookie(): void
    {
        $_COOKIE['fchub_mc_currency'] = 'eur';
        $resolver = new CookieResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR'], ['code' => 'GBP']]);

        $this->assertSame('EUR', $result);

        unset($_COOKIE['fchub_mc_currency']);
    }

    #[Test]
    public function testReturnsNullWhenCurrencyNotEnabled(): void
    {
        $_COOKIE['fchub_mc_currency'] = 'JPY';
        $resolver = new CookieResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertNull($result);

        unset($_COOKIE['fchub_mc_currency']);
    }
}
