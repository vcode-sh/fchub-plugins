<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ClockTest extends PluginTestCase
{
    public function test_default_clock_uses_wordpress_site_time(): void
    {
        $clock = $this->clock();

        self::assertSame('2026-03-13 22:00:00 +00:00', $clock->now()->format('Y-m-d H:i:s P'));
    }

    public function test_positive_offset_is_used_for_now_parsing_and_storage(): void
    {
        $timezone = new \DateTimeZone('Asia/Kolkata');
        $clock = $this->clock(new \DateTimeImmutable('2026-01-15 10:00:00 UTC'), $timezone);

        self::assertSame('2026-01-15 15:30:00 +05:30', $clock->now()->format('Y-m-d H:i:s P'));
        self::assertSame(
            '2026-01-15 10:00:00 +05:30',
            $clock->parseLocal('2026-01-15 10:00:00')->format('Y-m-d H:i:s P')
        );
        self::assertSame(
            '2026-01-15 15:30:00',
            $clock->storage(new \DateTimeImmutable('2026-01-15 10:00:00 UTC'))
        );
    }

    public function test_negative_offset_is_used_for_local_values(): void
    {
        $timezone = new \DateTimeZone('Pacific/Honolulu');
        $clock = $this->clock(new \DateTimeImmutable('2026-01-15 10:00:00 UTC'), $timezone);

        self::assertSame('2026-01-15 00:00:00 -10:00', $clock->now()->format('Y-m-d H:i:s P'));
        self::assertSame(
            '2026-01-15 10:00:00 -10:00',
            $clock->parseLocal('2026-01-15 10:00:00')->format('Y-m-d H:i:s P')
        );
    }

    public function test_plus_days_and_minutes_use_the_injected_start_time(): void
    {
        $timezone = new \DateTimeZone('UTC');
        $clock = $this->clock(new \DateTimeImmutable('2026-01-15 10:20:30 UTC'), $timezone);

        self::assertSame('2026-01-18 10:20:30', $clock->plusDays(3)->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-15 11:05:30', $clock->plusMinutes(45)->format('Y-m-d H:i:s'));
        self::assertSame(
            '2026-02-01 09:15:00',
            $clock->plusDays(1, new \DateTimeImmutable('2026-01-31 09:15:00 UTC'))->format('Y-m-d H:i:s')
        );
    }

    public function test_calendar_day_addition_preserves_wall_time_across_spring_forward(): void
    {
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $from = new \DateTimeImmutable('2026-03-28 12:30:00', $timezone);
        $clock = $this->clock($from, $timezone);

        $result = $clock->plusDays(1);

        self::assertSame('2026-03-29 12:30:00 +02:00', $result->format('Y-m-d H:i:s P'));
        self::assertSame(23 * 60 * 60, $result->getTimestamp() - $from->getTimestamp());
    }

    public function test_calendar_day_addition_preserves_wall_time_across_fall_back(): void
    {
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $from = new \DateTimeImmutable('2026-10-24 12:30:00', $timezone);
        $clock = $this->clock($from, $timezone);

        $result = $clock->plusDays(1);

        self::assertSame('2026-10-25 12:30:00 +01:00', $result->format('Y-m-d H:i:s P'));
        self::assertSame(25 * 60 * 60, $result->getTimestamp() - $from->getTimestamp());
    }

    public function test_calendar_days_until_counts_fall_back_as_one_local_day(): void
    {
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $from = new \DateTimeImmutable('2026-10-24 12:30:00', $timezone);
        $clock = $this->clock($from, $timezone);

        self::assertSame(
            1,
            $clock->calendarDaysUntil(new \DateTimeImmutable('2026-10-25 12:30:00', $timezone))
        );
    }

    public function test_calendar_days_until_rounds_partial_future_days_up_and_clamps_past(): void
    {
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $from = new \DateTimeImmutable('2026-10-24 12:30:00', $timezone);
        $clock = $this->clock($from, $timezone);

        self::assertSame(1, $clock->calendarDaysUntil(new \DateTimeImmutable('2026-10-24 12:30:01', $timezone)));
        self::assertSame(2, $clock->calendarDaysUntil(new \DateTimeImmutable('2026-10-25 12:30:01', $timezone)));
        self::assertSame(0, $clock->calendarDaysUntil(new \DateTimeImmutable('2026-10-24 12:29:59', $timezone)));
    }

    private function clock(
        ?\DateTimeImmutable $now = null,
        ?\DateTimeZone $timezone = null
    ): Clock {
        self::assertTrue(class_exists(Clock::class), 'The lifecycle Clock must exist.');

        return new Clock($now, $timezone);
    }
}
