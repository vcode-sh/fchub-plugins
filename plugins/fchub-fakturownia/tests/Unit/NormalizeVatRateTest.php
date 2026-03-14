<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\Handler\InvoiceHandler;
use FChubFakturownia\Tests\PluginTestCase;

/**
 * Exhaustive tests for normalizeVatRate() — covers BUG 4
 *
 * Uses reflection to test the private method directly since this is a
 * critical tax calculation that needs precise boundary testing.
 */
final class NormalizeVatRateTest extends PluginTestCase
{
    private \ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSettings();

        $ref = new \ReflectionClass(InvoiceHandler::class);
        $this->method = $ref->getMethod('normalizeVatRate');
        $this->method->setAccessible(true);
    }

    private function normalize(float $rate): int|string
    {
        $handler = new InvoiceHandler(
            new \FChubFakturownia\API\FakturowniaAPI('test', 'token')
        );
        return $this->method->invoke($handler, $rate);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 4: 0% rate is a real rate, NOT exempt ('zw')
    // ──────────────────────────────────────────────────────────

    public function testZeroPercentIsNotExempt(): void
    {
        $result = $this->normalize(0.0);
        $this->assertSame(0, $result, '0% VAT is a real rate (e.g. intra-community supply), not exempt');
        $this->assertNotSame('zw', $result);
    }

    public function testNegativeRateIsExempt(): void
    {
        $result = $this->normalize(-1.0);
        $this->assertSame('zw', $result);
    }

    public function testLargeNegativeRateIsExempt(): void
    {
        $result = $this->normalize(-100.0);
        $this->assertSame('zw', $result);
    }

    // ──────────────────────────────────────────────────────────
    // Standard Polish VAT rates
    // ──────────────────────────────────────────────────────────

    public function testExactFivePercent(): void
    {
        $this->assertSame(5, $this->normalize(5.0));
    }

    public function testExactEightPercent(): void
    {
        $this->assertSame(8, $this->normalize(8.0));
    }

    public function testExactTwentyThreePercent(): void
    {
        $this->assertSame(23, $this->normalize(23.0));
    }

    // ──────────────────────────────────────────────────────────
    // Snapping to closest standard rate
    // ──────────────────────────────────────────────────────────

    public function testOnePercentSnapsToZero(): void
    {
        $this->assertSame(0, $this->normalize(1.0));
    }

    public function testTwoPercentSnapsToZero(): void
    {
        $this->assertSame(0, $this->normalize(2.0));
    }

    public function testThreePercentSnapsToFive(): void
    {
        $this->assertSame(5, $this->normalize(3.0));
    }

    public function testFourPercentSnapsToFive(): void
    {
        $this->assertSame(5, $this->normalize(4.0));
    }

    public function testSixPercentSnapsToFive(): void
    {
        $this->assertSame(5, $this->normalize(6.0));
    }

    public function testSevenPercentSnapsToEight(): void
    {
        $this->assertSame(8, $this->normalize(7.0));
    }

    public function testTenPercentSnapsToEight(): void
    {
        $this->assertSame(8, $this->normalize(10.0));
    }

    public function testFifteenPercentSnapsToEight(): void
    {
        $this->assertSame(8, $this->normalize(15.0));
    }

    public function testSixteenPercentSnapsToTwentyThree(): void
    {
        $this->assertSame(23, $this->normalize(16.0));
    }

    public function testTwentyPercentSnapsToTwentyThree(): void
    {
        $this->assertSame(23, $this->normalize(20.0));
    }

    public function testTwentyFivePercentSnapsToTwentyThree(): void
    {
        $this->assertSame(23, $this->normalize(25.0));
    }

    public function testOneHundredPercentSnapsToTwentyThree(): void
    {
        $this->assertSame(23, $this->normalize(100.0));
    }

    // ──────────────────────────────────────────────────────────
    // Floating-point edge cases
    // ──────────────────────────────────────────────────────────

    public function testVerySmallPositiveRateSnapsToZero(): void
    {
        $this->assertSame(0, $this->normalize(0.001));
    }

    public function testTinyNegativeRate(): void
    {
        $this->assertSame('zw', $this->normalize(-0.001));
    }

    public function testTwentyTwoPointNineNineSnapsToTwentyThree(): void
    {
        $this->assertSame(23, $this->normalize(22.99));
    }

    public function testSevenPointFiveSnapsToBorderline(): void
    {
        // Equidistant between 5 and 8 — depends on which is found first in the loop
        // Since [0,5,8,23], scanning order: 0(7.5), 5(2.5), 8(0.5) → 8 wins
        $this->assertSame(8, $this->normalize(7.5));
    }
}
