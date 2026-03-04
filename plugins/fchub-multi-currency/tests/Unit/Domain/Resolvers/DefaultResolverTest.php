<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Resolvers;

use FChubMultiCurrency\Domain\Resolvers\DefaultResolver;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DefaultResolverTest extends TestCase
{
    #[Test]
    public function testAlwaysReturnsBaseCurrency(): void
    {
        $resolver = new DefaultResolver();

        $result = $resolver->resolve('USD', [['code' => 'EUR']]);

        $this->assertSame('USD', $result);
    }

    #[Test]
    public function testReturnsWhateverBaseIsGiven(): void
    {
        $resolver = new DefaultResolver();

        $this->assertSame('PLN', $resolver->resolve('PLN', []));
    }
}
