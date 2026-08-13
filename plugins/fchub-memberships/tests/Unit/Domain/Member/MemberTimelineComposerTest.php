<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Member;

use FChubMemberships\Domain\Member\MemberTimelineComposer;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MemberTimelineComposerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_fchub_test_users'][9] = (object) ['ID' => 9, 'display_name' => 'tomrobak'];
        $GLOBALS['_fchub_test_options']['date_format'] = 'j F Y';
    }

    public function test_a_membership_without_audit_records_still_reports_when_it_started(): void
    {
        $events = $this->compose(memberships: [
            self::membership('plan:1', 'Plan A', [3, 4], [
                'created_at' => '2026-02-01 10:00:00',
                'status' => 'active',
            ]),
        ]);

        self::assertSame(['granted'], array_column($events, 'type'));
        self::assertSame('2026-02-01 10:00:00', $events[0]['date']);
        self::assertSame('Plan A granted', $events[0]['description']);
    }

    public function test_a_recorded_grant_wins_over_the_date_on_the_row(): void
    {
        $events = $this->compose(
            memberships: [
                self::membership('plan:1', 'Plan A', [3], ['created_at' => '2026-02-01 10:00:00']),
            ],
            audit: [self::record(3, 'created', '2026-02-01 10:00:05')]
        );

        self::assertSame(['granted'], array_column($events, 'type'));
        self::assertSame('Plan A granted by tomrobak', $events[0]['description']);
    }

    public function test_an_expired_membership_without_audit_records_reports_its_expiry(): void
    {
        $events = $this->compose(memberships: [
            self::membership('plan:1', 'Plan A', [3], [
                'created_at' => '2026-02-01 10:00:00',
                'expires_at' => '2026-03-31 11:41:53',
                'status' => 'expired',
            ]),
        ]);

        self::assertSame(['expired', 'granted'], array_column($events, 'type'));
        self::assertSame('2026-03-31 11:41:53', $events[0]['date']);
    }

    public function test_a_lifetime_membership_is_never_reported_as_expired(): void
    {
        $events = $this->compose(memberships: [
            self::membership('plan:1', 'Plan A', [3], [
                'created_at' => '2026-02-01 10:00:00',
                'expires_at' => null,
                'status' => 'active',
            ]),
        ]);

        self::assertSame(['granted'], array_column($events, 'type'));
    }

    public function test_a_revoked_membership_invents_no_revocation_date(): void
    {
        $events = $this->compose(memberships: [
            self::membership('plan:1', 'Plan A', [3], [
                'created_at' => '2026-02-01 10:00:00',
                'expires_at' => '2026-12-01 00:00:00',
                'status' => 'revoked',
            ]),
        ]);

        self::assertSame(['granted'], array_column($events, 'type'));
    }

    public function test_the_same_event_on_every_row_of_a_membership_appears_once(): void
    {
        $events = $this->compose(audit: [
            self::record(3, 'created', '2026-02-01 10:00:00'),
            self::record(4, 'created', '2026-02-01 10:00:00'),
        ]);

        self::assertCount(1, $events);
        self::assertSame('granted', $events[0]['type']);
        self::assertSame('plan:1', $events[0]['membership_key']);
    }

    public function test_two_different_events_in_the_same_second_both_survive(): void
    {
        $events = $this->compose(audit: [
            self::record(3, 'created', '2026-02-01 10:00:00'),
            self::record(3, 'renewed', '2026-02-01 10:00:00'),
        ]);

        self::assertSame(['granted', 'renewed'], array_column($events, 'type'));
    }

    public function test_the_same_event_on_two_memberships_survives_twice(): void
    {
        $events = $this->compose(
            memberships: [
                self::membership('plan:1', 'Plan A', [3]),
                self::membership('plan:2', 'Plan B', [5]),
            ],
            audit: [
                self::record(3, 'created', '2026-02-01 10:00:00'),
                self::record(5, 'created', '2026-02-01 10:00:00'),
            ]
        );

        self::assertCount(2, $events);
        self::assertSame(['plan:1', 'plan:2'], array_column($events, 'membership_key'));
    }

    public function test_an_extension_says_what_it_extended_to_and_who_did_it(): void
    {
        $events = $this->compose(audit: [
            self::record(3, 'extended', '2026-08-01 09:00:00', [
                'old_value' => ['expires_at' => '2026-09-12 00:00:00'],
                'new_value' => ['expires_at' => '2026-12-31 00:00:00'],
            ]),
        ]);

        self::assertSame('Plan A extended to 31 December 2026 by tomrobak', $events[0]['description']);
    }

    public function test_a_revocation_is_dated_from_its_audit_record(): void
    {
        $events = $this->compose(audit: [
            self::record(3, 'created', '2026-02-01 10:00:00'),
            self::record(3, 'revoked', '2026-08-01 09:00:00'),
        ]);

        self::assertSame('revoked', $events[0]['type']);
        self::assertSame('2026-08-01 09:00:00', $events[0]['date']);
    }

    public function test_a_pause_reports_the_reason_that_was_recorded_with_it(): void
    {
        $events = $this->compose(audit: [
            self::record(3, 'paused', '2026-08-01 09:00:00', ['context' => 'Anchor billing date overdue']),
        ]);

        self::assertSame('Plan A paused by tomrobak — Anchor billing date overdue', $events[0]['description']);
    }

    public function test_a_system_action_names_the_system_rather_than_user_zero(): void
    {
        $events = $this->compose(audit: [
            self::record(3, 'expired', '2026-08-01 09:00:00', ['actor_id' => 0, 'actor_type' => 'system']),
        ]);

        self::assertSame('Plan A expired', $events[0]['description']);
    }

    public function test_events_are_newest_first(): void
    {
        $events = $this->compose(audit: [
            self::record(3, 'created', '2026-02-01 10:00:00'),
            self::record(3, 'paused', '2026-08-01 09:00:00'),
            self::record(3, 'resumed', '2026-05-01 09:00:00'),
        ]);

        self::assertSame(['paused', 'resumed', 'granted'], array_column($events, 'type'));
    }

    public function test_drip_notifications_join_the_timeline_under_their_membership(): void
    {
        $events = $this->compose(drip: [
            ['id' => 1, 'grant_id' => 3, 'status' => 'sent', 'sent_at' => '2026-03-01 08:00:00', 'notify_at' => '2026-03-01 08:00:00'],
            ['id' => 2, 'grant_id' => 4, 'status' => 'failed', 'sent_at' => null, 'notify_at' => '2026-04-01 08:00:00'],
        ]);

        self::assertSame(['drip_failed', 'drip_sent'], array_column($events, 'type'));
        self::assertSame('plan:1', $events[0]['membership_key']);
    }

    public function test_an_audit_record_for_an_unknown_grant_is_dropped_rather_than_orphaned(): void
    {
        $events = $this->compose(audit: [self::record(99, 'created', '2026-02-01 10:00:00')]);

        self::assertSame([], $events);
    }

    public function test_an_unrecognised_audit_action_still_reaches_the_reader(): void
    {
        $events = $this->compose(audit: [self::record(3, 'grace_period_started', '2026-08-01 09:00:00')]);

        self::assertSame('grace_period_started', $events[0]['type']);
        self::assertSame('Plan A grace period started by tomrobak', $events[0]['description']);
    }

    /**
     * @param list<array<string, mixed>> $memberships
     * @param list<array<string, mixed>> $audit
     * @param list<array<string, mixed>> $drip
     * @return list<array<string, mixed>>
     */
    private function compose(array $memberships = [], array $audit = [], array $drip = []): array
    {
        $memberships = $memberships ?: [self::membership('plan:1', 'Plan A', [3, 4])];

        return (new MemberTimelineComposer())->compose($memberships, $audit, $drip);
    }

    /**
     * @param list<int> $grantIds
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function membership(string $key, string $title, array $grantIds, array $overrides = []): array
    {
        return array_merge([
            'key' => $key,
            'plan_title' => $title,
            'grant_ids' => $grantIds,
            'created_at' => null,
            'expires_at' => null,
            'status' => 'active',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function record(int $grantId, string $action, string $createdAt, array $overrides = []): array
    {
        return array_merge([
            'id' => $grantId * 100,
            'entity_type' => 'grant',
            'entity_id' => $grantId,
            'action' => $action,
            'actor_id' => 9,
            'actor_type' => 'admin',
            'old_value' => [],
            'new_value' => [],
            'context' => '',
            'created_at' => $createdAt,
        ], $overrides);
    }
}
