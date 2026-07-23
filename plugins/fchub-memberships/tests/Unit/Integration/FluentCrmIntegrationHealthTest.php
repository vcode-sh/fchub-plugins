<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\FluentCrmIntegrationHealth;
use PHPUnit\Framework\TestCase;

final class FluentCrmIntegrationHealthTest extends TestCase
{
    public function test_reports_inactive_provider_without_exposing_provider_details(): void
    {
        $health = $this->health(['active' => false, 'version' => 'private-build'], true, 'yes');

        self::assertSame('inactive', $health->status()['status']);
        self::assertSame('Install and activate FluentCRM.', $health->status()['action']);
        self::assertNull($health->status()['provider']['version']);
    }

    public function test_reports_disabled_lifecycle_sync_before_capability_state(): void
    {
        $health = $this->health(['active' => true, 'version' => '2.9.0'], false, 'no');

        self::assertSame('disabled', $health->status()['status']);
        self::assertFalse($health->status()['lifecycle_sync']);
    }

    public function test_reports_incompatible_provider_when_capability_probe_fails(): void
    {
        $health = $this->health(['active' => true, 'version' => '2.9.0'], false, 'yes');

        self::assertSame('incompatible', $health->status()['status']);
        self::assertFalse($health->status()['compatible']);
    }

    public function test_reports_healthy_catalogue_counts_from_the_registration_source(): void
    {
        $health = $this->health(['active' => true, 'version' => '2.9.0'], true, 'yes', [
            'triggers' => 16,
            'actions' => 7,
            'benchmarks' => 7,
        ]);

        $status = $health->status();

        self::assertSame('healthy', $status['status']);
        self::assertSame([
            'triggers' => 16,
            'actions' => 7,
            'benchmarks' => 7,
            'smart_codes' => 0,
            'filters' => 0,
        ], $status['catalogue']);
    }

    public function test_reports_degraded_state_for_failed_or_drifting_projections(): void
    {
        $health = $this->health(
            ['active' => true, 'version' => '2.9.0'],
            true,
            'yes',
            ['triggers' => 16, 'actions' => 7, 'benchmarks' => 7],
            ['last_reconciliation' => '2026-07-22 11:00:00', 'failed' => 0, 'drift' => 2],
            ['pending' => 3, 'failed' => 1, 'last_success_at' => '2026-07-22 10:55:00']
        );

        $status = $health->status();

        self::assertSame('degraded', $status['status']);
        self::assertSame(1, $status['failed_projections']);
        self::assertSame(3, $status['pending_projections']);
        self::assertSame(2, $status['drift']);
        self::assertSame('2026-07-22 11:00:00', $status['last_reconciliation']);
        self::assertSame('2026-07-22 10:55:00', $status['last_successful_projection']);
    }

    public function test_job_storage_failure_is_a_sanitised_degraded_unknown_state(): void
    {
        $health = new FluentCrmIntegrationHealth(
            static fn(): array => ['active' => true, 'version' => '3.1.8'],
            static fn(): bool => true,
            static fn(): array => ['fluentcrm_enabled' => 'yes'],
            static fn(): array => [],
            static fn(): array => [],
            null,
            null,
            static fn(): array => throw new \RuntimeException('table credentials private')
        );

        $status = $health->status();

        self::assertSame('degraded', $status['status']);
        self::assertSame('Repair CRM projection storage.', $status['action']);
        self::assertFalse($status['projection_jobs_readable']);
        self::assertNull($status['pending_projections']);
        self::assertNull($status['failed_projections']);
        self::assertStringNotContainsString('private', serialize($status));
    }

    public function test_manual_reconciliation_failures_remain_visible_without_a_lifecycle_job(): void
    {
        $health = $this->health(
            ['active' => true, 'version' => '3.1.8'],
            true,
            'yes',
            [],
            ['last_reconciliation' => '2026-07-22 11:00:00', 'failed' => 1, 'drift' => 0],
            ['pending' => 0, 'failed' => 0, 'last_success_at' => null]
        );

        $status = $health->status();

        self::assertSame('degraded', $status['status']);
        self::assertSame(1, $status['failed_reconciliations']);
        self::assertSame(0, $status['failed_projections']);
    }

    public function test_canonical_page_summary_survives_a_new_health_instance(): void
    {
        $stored = [];
        $writer = static function (array $summary) use (&$stored): bool {
            $stored = $summary;
            return true;
        };
        $first = $this->healthWithStorage($stored, $writer);

        $pageOne = $first->recordPage(105, 0, 100, false, 100, 1, 3);
        $restarted = $this->healthWithStorage($stored, $writer);

        self::assertTrue($restarted->canResume(100, 105));
        $complete = $restarted->recordPage(105, 100, 105, true, 5, 2, 1);

        self::assertSame([
            'watermark' => 105,
            'cursor' => 100,
            'complete' => false,
            'processed' => 100,
            'failed' => 1,
            'drift' => 3,
            'updated_at' => '2026-07-23 09:00:00',
        ], $pageOne);
        self::assertSame(105, $complete['processed']);
        self::assertSame(3, $complete['failed']);
        self::assertSame(4, $complete['drift']);
        self::assertSame(105, $complete['cursor']);
        self::assertTrue($complete['complete']);
        self::assertSame($complete, $restarted->status()['reconciliation']);
        self::assertArrayNotHasKey('results', $stored['reconciliation']);
    }

    public function test_resume_requires_the_same_incomplete_watermark_and_cursor(): void
    {
        $stored = [];
        $writer = static function (array $summary) use (&$stored): bool {
            $stored = $summary;
            return true;
        };
        $health = $this->healthWithStorage($stored, $writer);
        $health->recordPage(205, 0, 100, false, 100, 0, 0);

        self::assertTrue($health->canResume(100, 205));
        self::assertFalse($health->canResume(99, 205));
        self::assertFalse($health->canResume(100, 206));
        self::assertFalse($health->canResume(0, 205));
    }

    public function test_empty_resumed_page_completes_without_erasing_the_previous_aggregate(): void
    {
        $stored = [];
        $writer = static function (array $summary) use (&$stored): bool {
            $stored = $summary;
            return true;
        };
        $health = $this->healthWithStorage($stored, $writer);
        $health->recordPage(105, 0, 100, false, 100, 2, 4);

        $complete = $health->recordPage(105, 100, 100, true, 0, 0, 0);

        self::assertSame(100, $complete['processed']);
        self::assertSame(2, $complete['failed']);
        self::assertSame(4, $complete['drift']);
        self::assertSame(100, $complete['cursor']);
        self::assertTrue($complete['complete']);
    }

    public function test_fresh_page_zero_resets_an_incomplete_same_watermark_summary(): void
    {
        $stored = [];
        $writer = static function (array $summary) use (&$stored): bool {
            $stored = $summary;
            return true;
        };
        $health = $this->healthWithStorage($stored, $writer);
        $health->recordPage(205, 0, 100, false, 100, 2, 4);

        $fresh = $health->recordPage(205, 0, 101, false, 100, 0, 1);

        self::assertSame(100, $fresh['processed']);
        self::assertSame(0, $fresh['failed']);
        self::assertSame(1, $fresh['drift']);
        self::assertSame(101, $fresh['cursor']);
        self::assertFalse($fresh['complete']);
    }

    public function test_default_catalogue_resolver_uses_the_fluentcrm_3_topology_and_filters_to_memberships(): void
    {
        add_filter('fluentcrm_funnel_triggers', static fn(array $triggers): array => array_merge($triggers, [
            'fchub_memberships/grant_created' => [],
            'fchub_memberships/grant_expired' => [],
            'vendor/other' => [],
        ]));
        add_filter('fluentcrm_funnel_blocks', static fn(array $blocks): array => array_merge($blocks, [
            'fchub_grant_membership' => ['category' => 'FCHub Memberships', 'type' => 'action'],
            'fchub_memberships/grant_created' => ['category' => 'FCHub Memberships', 'type' => 'benchmark'],
            'vendor_other' => ['category' => 'Other', 'type' => 'action'],
        ]));
        add_filter('fluent_crm_funnel_context_smart_codes', static fn(array $groups): array => [[
            'key' => 'membership',
            'shortcodes' => array_fill(0, 25, 'value'),
        ]]);
        add_filter('fluentcrm_advanced_filter_options', static fn(array $groups): array => [
            'fchub_memberships' => ['children' => array_fill(0, 6, [])],
        ]);

        $health = new FluentCrmIntegrationHealth(
            static fn(): array => ['active' => true, 'version' => '3.1.8'],
            static fn(): bool => true,
            static fn(): array => ['fluentcrm_enabled' => 'yes'],
            null,
            static fn(): array => []
        );

        self::assertSame([
            'triggers' => 2,
            'actions' => 1,
            'benchmarks' => 1,
            'smart_codes' => 25,
            'filters' => 6,
        ], $health->status()['catalogue']);
    }

    /** @param array{active:bool, version:string} $provider */
    private function health(
        array $provider,
        bool $compatible,
        string $enabled,
        array $catalogue = ['triggers' => 0, 'actions' => 0, 'benchmarks' => 0, 'smart_codes' => 0, 'filters' => 0],
        array $summary = [],
        array $jobSummary = []
    ): FluentCrmIntegrationHealth {
        return new FluentCrmIntegrationHealth(
            static fn(): array => $provider,
            static fn(): bool => $compatible,
            static fn(): array => ['fluentcrm_enabled' => $enabled],
            static fn(): array => $catalogue,
            static fn(): array => $summary,
            null,
            null,
            static fn(): array => $jobSummary
        );
    }

    /** @param array<string, mixed> $stored */
    private function healthWithStorage(array &$stored, callable $writer): FluentCrmIntegrationHealth
    {
        return new FluentCrmIntegrationHealth(
            static fn(): array => ['active' => true, 'version' => '3.1.8'],
            static fn(): bool => true,
            static fn(): array => ['fluentcrm_enabled' => 'yes'],
            static fn(): array => [],
            static function () use (&$stored): array {
                return $stored;
            },
            $writer,
            static fn(): string => '2026-07-23 09:00:00',
            static fn(): array => []
        );
    }
}
