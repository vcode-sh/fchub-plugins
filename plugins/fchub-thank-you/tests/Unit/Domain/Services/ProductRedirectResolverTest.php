<?php

declare(strict_types=1);

namespace FchubThankYou\Tests\Unit\Domain\Services;

use FchubThankYou\Domain\Enums\RedirectType;
use FchubThankYou\Domain\Services\ProductRedirectResolver;
use FchubThankYou\Domain\ValueObjects\RedirectSettings;
use FchubThankYou\Support\ProductMetaStore;
use PHPUnit\Framework\TestCase;

final class ProductRedirectResolverTest extends TestCase
{
    /**
     * @param array<int, RedirectSettings> $map productId => RedirectSettings
     */
    private function makeResolver(array $map): ProductRedirectResolver
    {
        $store = $this->createStub(ProductMetaStore::class);
        $store->method('find')
            ->willReturnCallback(
                static fn (int $id): RedirectSettings => $map[$id] ?? new RedirectSettings(enabled: false),
            );

        return new ProductRedirectResolver($store);
    }

    public function testReturnsNullWhenNoProductsHaveRedirectEnabled(): void
    {
        $resolver = $this->makeResolver([
            1 => new RedirectSettings(enabled: false),
            2 => new RedirectSettings(enabled: false, type: RedirectType::Url, url: 'https://example.com'),
        ]);

        self::assertNull($resolver->resolve([1, 2]));
    }

    public function testReturnsUrlOfFirstProductWithEnabledUrlRedirect(): void
    {
        $resolver = $this->makeResolver([
            1 => new RedirectSettings(enabled: false),
            2 => new RedirectSettings(enabled: true, type: RedirectType::Url, url: 'https://example.com/thank-you'),
        ]);

        self::assertSame('https://example.com/thank-you', $resolver->resolve([1, 2]));
    }

    public function testSkipsProductsWithEnabledButNoValidTarget(): void
    {
        $resolver = $this->makeResolver([
            1 => new RedirectSettings(enabled: true, type: RedirectType::Url),
            2 => new RedirectSettings(enabled: true, type: RedirectType::Url, url: 'https://example.com/fallback'),
        ]);

        self::assertSame('https://example.com/fallback', $resolver->resolve([1, 2]));
    }

    public function testReturnsNullForEmptyProductList(): void
    {
        $resolver = $this->makeResolver([]);

        self::assertNull($resolver->resolve([]));
    }

    public function testFirstWinsWhenMultipleProductsHaveRedirects(): void
    {
        $resolver = $this->makeResolver([
            1 => new RedirectSettings(enabled: true, type: RedirectType::Url, url: 'https://example.com/first'),
            2 => new RedirectSettings(enabled: true, type: RedirectType::Url, url: 'https://example.com/second'),
            3 => new RedirectSettings(enabled: true, type: RedirectType::Url, url: 'https://example.com/third'),
        ]);

        self::assertSame('https://example.com/first', $resolver->resolve([1, 2, 3]));
    }

    public function testResolvesPageTypeViaGetPermalink(): void
    {
        $resolver = $this->makeResolver([
            1 => new RedirectSettings(enabled: true, type: RedirectType::Page, targetId: 42),
        ]);

        // get_permalink stub returns 'http://localhost/?p=42'
        self::assertSame('http://localhost/?p=42', $resolver->resolve([1]));
    }

    public function testResolvesPostTypeViaGetPermalink(): void
    {
        $resolver = $this->makeResolver([
            1 => new RedirectSettings(enabled: true, type: RedirectType::Post, targetId: 10),
        ]);

        self::assertSame('http://localhost/?p=10', $resolver->resolve([1]));
    }

    public function testResolvesCptTypeViaGetPermalink(): void
    {
        $resolver = $this->makeResolver([
            1 => new RedirectSettings(enabled: true, type: RedirectType::Cpt, targetId: 99, postType: 'product'),
        ]);

        self::assertSame('http://localhost/?p=99', $resolver->resolve([1]));
    }

    public function testSkipsPageTypeWithoutTargetId(): void
    {
        $resolver = $this->makeResolver([
            1 => new RedirectSettings(enabled: true, type: RedirectType::Page),
            2 => new RedirectSettings(enabled: true, type: RedirectType::Url, url: 'https://example.com/fallback'),
        ]);

        self::assertSame('https://example.com/fallback', $resolver->resolve([1, 2]));
    }

    public function testBackwardCompatUrlTypeWithExistingUrl(): void
    {
        // Simulates an existing product that only had enabled + url set (pre-migration)
        $resolver = $this->makeResolver([
            1 => new RedirectSettings(enabled: true, type: RedirectType::Url, url: 'https://old-site.com/thanks'),
        ]);

        self::assertSame('https://old-site.com/thanks', $resolver->resolve([1]));
    }
}
