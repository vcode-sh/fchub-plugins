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
            ['last_reconciliation' => '2026-07-22 11:00:00', 'failed' => 1, 'drift' => 2]
        );

        $status = $health->status();

        self::assertSame('degraded', $status['status']);
        self::assertSame(1, $status['failed_projections']);
        self::assertSame(2, $status['drift']);
        self::assertSame('2026-07-22 11:00:00', $status['last_reconciliation']);
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
        array $summary = []
    ): FluentCrmIntegrationHealth {
        return new FluentCrmIntegrationHealth(
            static fn(): array => $provider,
            static fn(): bool => $compatible,
            static fn(): array => ['fluentcrm_enabled' => $enabled],
            static fn(): array => $catalogue,
            static fn(): array => $summary
        );
    }
}
