<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support\Enums;

use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Tests\Unit\PluginTestCase;

final class FcBillingIntervalTest extends PluginTestCase
{
    public function testMonthly(): void
    {
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('month', 1));
    }

    public function testMonthlyDefaultInterval(): void
    {
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('month'));
    }

    public function testQuarterly(): void
    {
        $this->assertSame(FcBillingInterval::Quarterly, FcBillingInterval::fromWooCommerce('month', 3));
    }

    public function testHalfYearly(): void
    {
        $this->assertSame(FcBillingInterval::HalfYearly, FcBillingInterval::fromWooCommerce('month', 6));
    }

    public function testYearly(): void
    {
        $this->assertSame(FcBillingInterval::Yearly, FcBillingInterval::fromWooCommerce('year'));
    }

    public function testDaily(): void
    {
        $this->assertSame(FcBillingInterval::Daily, FcBillingInterval::fromWooCommerce('day'));
    }

    public function testWeekly(): void
    {
        $this->assertSame(FcBillingInterval::Weekly, FcBillingInterval::fromWooCommerce('week'));
    }

    public function testDefaultMonthly(): void
    {
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('unknown-period'));
    }

    public function testMonthWithUnusualInterval(): void
    {
        // month with interval 2 should default to Monthly (not Quarterly or HalfYearly)
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('month', 2));
    }

    public function testEnumValues(): void
    {
        $this->assertSame('daily', FcBillingInterval::Daily->value);
        $this->assertSame('weekly', FcBillingInterval::Weekly->value);
        $this->assertSame('monthly', FcBillingInterval::Monthly->value);
        $this->assertSame('quarterly', FcBillingInterval::Quarterly->value);
        $this->assertSame('half_yearly', FcBillingInterval::HalfYearly->value);
        $this->assertSame('yearly', FcBillingInterval::Yearly->value);
    }

    // ──────────────────────────────────────────────
    // tryFromWooCommerce() — the exact table, no fallback arm
    // ──────────────────────────────────────────────
    //
    // fromWooCommerce() above collapses: week/2 becomes weekly, year/2 becomes
    // yearly, month/2 and month/12 become monthly, and anything unrecognised
    // becomes monthly as well. For a catalogue variation that is a cosmetic
    // wrong answer. For a subscription contract it is a customer billed twelve
    // times a year on a plan they bought once a year — so the subscription path
    // asks this method instead, and this method has exactly six answers.

    /**
     * @return list<array{0: string, 1: int, 2: FcBillingInterval}>
     */
    public static function supportedPairs(): array
    {
        return [
            ['day', 1, FcBillingInterval::Daily],
            ['week', 1, FcBillingInterval::Weekly],
            ['month', 1, FcBillingInterval::Monthly],
            ['month', 3, FcBillingInterval::Quarterly],
            ['month', 6, FcBillingInterval::HalfYearly],
            ['year', 1, FcBillingInterval::Yearly],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('supportedPairs')]
    public function testTheSixSupportedPairs(string $period, int $multiplier, FcBillingInterval $expected): void
    {
        $this->assertSame($expected, FcBillingInterval::tryFromWooCommerce($period, $multiplier));
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    public static function unsupportedPairs(): array
    {
        return [
            ['week', 2],
            ['year', 2],
            ['month', 2],
            ['month', 12],
            ['day', 2],
            ['unknown-period', 1],
            ['', 1],
            // Gated before matching begins: a zero or negative multiplier is not
            // "assume one", it is a source row nothing may bill from.
            ['month', 0],
            ['month', -1],
            ['year', 0],
            ['day', -3],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedPairs')]
    public function testEverythingElseIsUnsupported(string $period, int $multiplier): void
    {
        $this->assertNull(
            FcBillingInterval::tryFromWooCommerce($period, $multiplier),
            sprintf('%s/%d has no exact FluentCart interval and must not be collapsed into one.', $period, $multiplier),
        );
    }

    /**
     * The period is matched case-insensitively and untrimmed input is trimmed,
     * because WooCommerce meta is a stored string and a stray space is not a
     * different billing cadence. Nothing else is normalised.
     */
    public function testThePeriodIsTrimmedAndCaseInsensitive(): void
    {
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::tryFromWooCommerce(' Month ', 1));
    }

    /**
     * Every value this enum can return has to be one FluentCart 1.6.0 accepts
     * for `billing_interval`. Verified against the installed source:
     * app/Modules/Subscriptions/Services/SubscriptionService.php:941 and
     * app/Modules/MCP/Tools/ContextTools.php:63 both list exactly these six.
     */
    public function testEveryCaseIsAFluentCartBillingInterval(): void
    {
        $fluentCart = ['daily', 'weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly'];

        $this->assertSame(
            $fluentCart,
            array_map(static fn (FcBillingInterval $case): string => $case->value, FcBillingInterval::cases()),
        );
    }
}
