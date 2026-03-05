<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Resolvers;

use FChubMultiCurrency\Domain\Resolvers\UserMetaResolver;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class UserMetaResolverTest extends TestCase
{
    #[Test]
    public function testReturnsNullForGuest(): void
    {
        $this->setCurrentUserId(0);
        $resolver = new UserMetaResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertNull($result);
    }

    #[Test]
    public function testReturnsSavedPreference(): void
    {
        $this->setCurrentUserId(42);
        $this->setUserMeta(42, '_fchub_mc_currency', 'EUR');
        $resolver = new UserMetaResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertSame('EUR', $result);
    }

    #[Test]
    public function testReturnsNullWhenPreferenceNotSet(): void
    {
        $this->setCurrentUserId(42);
        $resolver = new UserMetaResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertNull($result);
    }

    #[Test]
    public function testAllowsBaseCurrencyEvenWhenNotInDisplayList(): void
    {
        $this->setCurrentUserId(42);
        $this->setUserMeta(42, '_fchub_mc_currency', 'USD');
        $resolver = new UserMetaResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertSame('USD', $result);
    }
}
