<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\ValueObjects;

use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class CurrencyContextTest extends TestCase
{
    #[Test]
    public function testBaseOnlyCreatesOneToOneContext(): void
    {
        $base = MockBuilder::currency('USD');
        $context = CurrencyContext::baseOnly($base);

        $this->assertTrue($context->isBaseDisplay);
        $this->assertSame('USD', $context->displayCurrency->code);
        $this->assertSame('USD', $context->baseCurrency->code);
        $this->assertSame('1.00000000', $context->rate->rate);
        $this->assertSame(ResolverSource::Fallback, $context->source);
    }

    #[Test]
    public function testBaseOnlyRateProviderIsManual(): void
    {
        $base = MockBuilder::currency('EUR');
        $context = CurrencyContext::baseOnly($base);

        $this->assertSame(RateProvider::Manual, $context->rate->provider);
    }

    /**
     * A resolver (UrlParam, UserMeta, Cookie, Geo) can genuinely match and
     * still land on the base currency — that's real information about where
     * the visitor's preference came from, not a fallback. baseOnly() must
     * preserve whichever source is passed rather than silently discarding it.
     */
    #[Test]
    #[DataProvider('resolverSourceProvider')]
    public function testBaseOnlyPreservesAnExplicitSource(ResolverSource $source): void
    {
        $base = MockBuilder::currency('USD');
        $context = CurrencyContext::baseOnly($base, $source);

        $this->assertSame($source, $context->source);
        $this->assertTrue($context->isBaseDisplay);
    }

    /**
     * @return array<string, array{ResolverSource}>
     */
    public static function resolverSourceProvider(): array
    {
        return [
            'url_param' => [ResolverSource::UrlParam],
            'user_meta' => [ResolverSource::UserMeta],
            'cookie'    => [ResolverSource::Cookie],
            'geo'       => [ResolverSource::Geo],
        ];
    }

    #[Test]
    public function testBaseOnlyDefaultsToFallbackWhenNoSourceIsPassed(): void
    {
        $base = MockBuilder::currency('USD');
        $context = CurrencyContext::baseOnly($base);

        $this->assertSame(ResolverSource::Fallback, $context->source);
    }
}
