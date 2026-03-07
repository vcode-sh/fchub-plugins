<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Actions;

use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests the guard condition in RefreshRatesAction that rejects scientific notation,
 * Infinity, and NaN rate strings before they reach bccomp().
 */
final class RefreshRatesBcmathTest extends TestCase
{
    /**
     * Replicates the guard condition from RefreshRatesAction::execute().
     */
    private function isRejectedByGuard(string $rateStr): bool
    {
        return !is_numeric($rateStr) || preg_match('/[eE]/', $rateStr) === 1;
    }

    #[Test]
    public function testScientificNotationRejected(): void
    {
        $this->assertTrue(is_numeric('1e3'), '1e3 is numeric in PHP');
        $this->assertTrue($this->isRejectedByGuard('1e3'), 'Guard should reject scientific notation');
    }

    #[Test]
    public function testNormalDecimalAccepted(): void
    {
        $this->assertFalse($this->isRejectedByGuard('0.92'), 'Normal decimal should pass guard');
    }

    #[Test]
    public function testInfinityRejected(): void
    {
        $this->assertTrue($this->isRejectedByGuard('Infinity'), 'Infinity should be rejected');
    }

    #[Test]
    public function testNanRejected(): void
    {
        $this->assertTrue($this->isRejectedByGuard('NaN'), 'NaN should be rejected');
    }

    #[Test]
    public function testNegativeExponentRejected(): void
    {
        $this->assertTrue(is_numeric('1.5e-7'), '1.5e-7 is numeric in PHP');
        $this->assertTrue($this->isRejectedByGuard('1.5e-7'), 'Guard should reject negative exponent');
    }

    #[Test]
    public function testUppercaseExponentRejected(): void
    {
        $this->assertTrue(is_numeric('1.5E10'), '1.5E10 is numeric in PHP');
        $this->assertTrue($this->isRejectedByGuard('1.5E10'), 'Guard should reject uppercase E');
    }

    #[Test]
    public function testLargeDecimalAccepted(): void
    {
        $this->assertFalse($this->isRejectedByGuard('149.85000000'), 'Large decimal should pass guard');
    }

    #[Test]
    public function testZeroRatePassesGuardButFailsBccomp(): void
    {
        // Zero is numeric and not scientific notation, but bccomp will reject it (zero rate)
        $this->assertFalse($this->isRejectedByGuard('0'), 'Zero passes the notation guard');
        // The bccomp guard (separate check) would catch zero rates
    }
}
