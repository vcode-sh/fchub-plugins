<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Resolvers;

use FChubMultiCurrency\Domain\Resolvers\CookieResolver;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests the AllowedCurrencyCheck trait via CookieResolver (which uses it).
 */
final class AllowedCurrencyCheckTest extends TestCase
{
    private function callIsAllowed(string $code, string $baseCurrency, array $currencies): bool
    {
        // Use reflection to test the private trait method via CookieResolver
        $resolver = new CookieResolver();
        $ref = new \ReflectionMethod($resolver, 'isAllowedCurrency');
        $ref->setAccessible(true);

        return $ref->invoke($resolver, $code, $baseCurrency, $currencies);
    }

    #[Test]
    public function testBaseCurrencyCodeIsAlwaysAllowed(): void
    {
        $result = $this->callIsAllowed('USD', 'USD', []);

        $this->assertTrue($result, 'Base currency should always be allowed');
    }

    #[Test]
    public function testCurrencyInDisplayListIsAllowed(): void
    {
        $currencies = [
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£'],
        ];

        $this->assertTrue($this->callIsAllowed('EUR', 'USD', $currencies));
        $this->assertTrue($this->callIsAllowed('GBP', 'USD', $currencies));
    }

    #[Test]
    public function testCurrencyNotInListIsDisallowed(): void
    {
        $currencies = [
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'],
        ];

        $result = $this->callIsAllowed('JPY', 'USD', $currencies);

        $this->assertFalse($result, 'Currency not in the display list should be disallowed');
    }

    #[Test]
    public function testCaseInsensitiveBaseCurrencyMatch(): void
    {
        // Base currency check uses strtoupper on both sides
        $result = $this->callIsAllowed('USD', 'usd', []);

        $this->assertTrue($result, 'Base currency comparison should be case-insensitive');
    }

    #[Test]
    public function testSkipsNonArrayCurrencyEntries(): void
    {
        $currencies = [
            'invalid_string',
            ['code' => 'EUR', 'name' => 'Euro'],
        ];

        $this->assertTrue($this->callIsAllowed('EUR', 'USD', $currencies));
        $this->assertFalse($this->callIsAllowed('GBP', 'USD', $currencies));
    }
}
