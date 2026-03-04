<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Resolvers;

use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ResolverChainTest extends TestCase
{
    #[Test]
    public function testReturnsFirstSuccessfulResult(): void
    {
        $context = MockBuilder::context();
        $chain = new ResolverChain();

        $chain->add(ResolverSource::UrlParam, fn() => null);
        $chain->add(ResolverSource::Cookie, fn() => $context);
        $chain->add(ResolverSource::Fallback, fn() => MockBuilder::baseOnlyContext());

        $result = $chain->resolve('USD', []);

        $this->assertSame($context, $result);
    }

    #[Test]
    public function testReturnsNullWhenNoResolverMatches(): void
    {
        $chain = new ResolverChain();

        $chain->add(ResolverSource::UrlParam, fn() => null);
        $chain->add(ResolverSource::Cookie, fn() => null);

        $result = $chain->resolve('USD', []);

        $this->assertNull($result);
    }

    #[Test]
    public function testEmptyChainReturnsNull(): void
    {
        $chain = new ResolverChain();

        $result = $chain->resolve('USD', []);

        $this->assertNull($result);
    }
}
