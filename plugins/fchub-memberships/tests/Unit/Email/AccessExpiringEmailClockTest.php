<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Email;

use FChubMemberships\Email\AccessExpiringEmail;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AccessExpiringEmailClockTest extends PluginTestCase
{
    public function test_pending_window_prefers_canonical_warning_days_over_legacy_notice_days(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_access_expiring' => 'no',
            'expiry_warning_days' => 7,
            'expiry_notice_days' => 2,
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;
            return [];
        };
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $clock = new Clock(new \DateTimeImmutable('2026-07-23 12:30:00', $timezone), $timezone);

        (new AccessExpiringEmail($clock))->sendPendingNotifications();

        self::assertStringContainsString("expires_at <= '2026-07-30 12:30:00'", $query);
    }

    public function test_pending_window_uses_site_local_calendar_days(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_access_expiring' => 'no',
            'expiry_notice_days' => 1,
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;
            return [];
        };
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $clock = new Clock(new \DateTimeImmutable('2026-03-28 12:30:00', $timezone), $timezone);

        (new AccessExpiringEmail($clock))->sendPendingNotifications();

        self::assertStringContainsString("expires_at > '2026-03-28 12:30:00'", $query);
        self::assertStringContainsString("expires_at <= '2026-03-29 12:30:00'", $query);
    }

    public function test_pending_payload_days_left_uses_calendar_days_and_rounds_partial_days(): void
    {
        $capturedDays = [];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_access_expiring' => 'no',
            'expiry_notice_days' => 2,
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [
            (object) ['id' => 1, 'user_id' => 21, 'plan_id' => 5, 'expires_at' => '2026-10-25 12:30:00', 'meta' => '{}'],
            (object) ['id' => 2, 'user_id' => 21, 'plan_id' => 5, 'expires_at' => '2026-10-24 12:30:01', 'meta' => '{}'],
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): object => (object) [
            'title' => 'Gold',
            'slug' => 'gold',
        ];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_expiring_soon'] = [
            static function (array $grant, int $daysLeft) use (&$capturedDays): void {
                $capturedDays[$grant['id']] = $daysLeft;
            },
        ];
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $clock = new Clock(new \DateTimeImmutable('2026-10-24 12:30:00', $timezone), $timezone);

        (new AccessExpiringEmail($clock))->sendPendingNotifications();

        self::assertSame([1 => 1, 2 => 1], $capturedDays);
    }
}
