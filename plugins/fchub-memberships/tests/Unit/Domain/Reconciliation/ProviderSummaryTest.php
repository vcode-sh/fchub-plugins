<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Reconciliation;

use FChubMemberships\Domain\Reconciliation\ProviderReconciliationService;
use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;
use FChubMemberships\Integration\FluentCrmIntegrationHealth;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProviderSummaryTest extends PluginTestCase
{
    public function test_provider_summaries_compose_capabilities_operations_and_existing_crm_health(): void
    {
        $operations = new class extends ProviderOperationRepository {
            public function summarizeByProvider(): array
            {
                return [
                    'fluent_community' => ['pending_operations' => 2, 'failed_operations' => 0],
                    'fluentcrm' => ['pending_operations' => 0, 'failed_operations' => 1],
                ];
            }
        };
        $community = new CommunityCapabilityRegistry(
            static fn(): array => [
                'core_active' => true,
                'core_version' => '2.7.0',
                'pro_active' => false,
                'pro_version' => '2.7.0',
                'pro_certified' => false,
            ],
            static fn(string $feature): bool => $feature === 'course_module',
            static fn(string $capability): bool => true
        );
        $crm = new FluentCrmIntegrationHealth(
            static fn(): array => ['active' => true, 'version' => '3.1.8'],
            static fn(): bool => true,
            static fn(): array => ['fluentcrm_enabled' => 'yes'],
            static fn(): array => ['tags' => 2, 'lists' => 1],
            static fn(): array => [
                'last_reconciliation' => '2026-07-23 10:00:00',
                'processed' => 10,
                'failed' => 0,
                'drift' => 0,
            ],
            static fn(array $summary): bool => true,
            static fn(): string => '2026-07-23 10:00:00',
            static fn(): array => [
                'pending' => 0,
                'failed' => 0,
                'last_success_at' => '2026-07-23 09:55:00',
            ]
        );
        $service = new ProviderReconciliationService(
            operations: $operations,
            extensions: [],
            crmHealth: $crm,
            communityCapabilities: $community,
            runtimeProviderResolver: static fn(): array => [
                'wordpress_version' => '6.8.2',
                'learndash_active' => false,
                'learndash_version' => null,
            ]
        );

        $summaries = $service->providerSummaries();
        $byProvider = array_column($summaries, null, 'value');

        self::assertSame(
            ['wordpress_core', 'learndash', 'fluentcrm', 'fluent_community'],
            array_column($summaries, 'value')
        );
        foreach ($summaries as $summary) {
            self::assertSame([
                'value',
                'label',
                'status',
                'version',
                'reason',
                'capabilities',
                'pending_operations',
                'failed_operations',
                'last_successful_reconciliation',
                'repair_url',
            ], array_keys($summary));
        }
        self::assertSame('healthy', $byProvider['wordpress_core']['status']);
        self::assertSame('unverified', $byProvider['learndash']['status']);
        self::assertSame('learndash_runtime_not_certified', $byProvider['learndash']['reason']);
        self::assertSame('healthy', $byProvider['fluentcrm']['status']);
        self::assertSame(0, $byProvider['fluentcrm']['failed_operations']);
        self::assertSame('2026-07-23 09:55:00', $byProvider['fluentcrm']['last_successful_reconciliation']);
        self::assertSame('degraded', $byProvider['fluent_community']['status']);
        self::assertSame(2, $byProvider['fluent_community']['pending_operations']);
        self::assertSame('inactive', $byProvider['fluent_community']['capabilities']['badges']['status']);
        self::assertNull($byProvider['wordpress_core']['repair_url']);
        self::assertNull($byProvider['learndash']['repair_url']);
        self::assertNull($byProvider['fluentcrm']['repair_url']);
        self::assertSame(
            '/integrations?provider=fluent_community',
            $byProvider['fluent_community']['repair_url']
        );
    }

    public function test_crm_success_timestamp_is_hidden_while_drift_or_projection_failures_remain(): void
    {
        foreach ([
            'drift' => [
                'summary' => ['failed' => 0, 'drift' => 1],
                'jobs' => ['failed' => 0],
            ],
            'projection_failure' => [
                'summary' => ['failed' => 0, 'drift' => 0],
                'jobs' => ['failed' => 1],
            ],
        ] as $scenario) {
            $summaries = $this->providerSummaries($scenario['summary'], $scenario['jobs']);
            $summary = array_column($summaries, null, 'value')['fluentcrm'];

            self::assertNull($summary['last_successful_reconciliation']);
        }
    }

    public function test_healthy_and_unverified_providers_do_not_offer_repair_navigation(): void
    {
        $byProvider = array_column($this->providerSummaries([], []), null, 'value');

        foreach (['wordpress_core', 'learndash', 'fluentcrm', 'fluent_community'] as $provider) {
            self::assertNull($byProvider[$provider]['repair_url']);
        }
    }

    public function test_unavailable_configurable_providers_link_to_contextual_integration_settings(): void
    {
        foreach ([
            'inactive' => [
                'crm_provider' => ['active' => false, 'version' => null],
                'crm_compatible' => true,
                'crm_enabled' => 'yes',
                'community_environment' => ['core_active' => false],
                'community_feature' => true,
                'community_contract' => true,
            ],
            'disabled' => [
                'crm_provider' => ['active' => true, 'version' => '3.1.8'],
                'crm_compatible' => true,
                'crm_enabled' => 'no',
                'community_environment' => ['core_active' => true, 'core_version' => '2.7.0'],
                'community_feature' => false,
                'community_contract' => true,
            ],
            'incompatible' => [
                'crm_provider' => ['active' => true, 'version' => '3.1.8'],
                'crm_compatible' => false,
                'crm_enabled' => 'yes',
                'community_environment' => ['core_active' => true, 'core_version' => '2.7.0'],
                'community_feature' => true,
                'community_contract' => false,
            ],
        ] as $status => $scenario) {
            $byProvider = array_column($this->providerSummaries(
                [],
                [],
                crmProvider: $scenario['crm_provider'],
                crmCompatible: $scenario['crm_compatible'],
                crmEnabled: $scenario['crm_enabled'],
                communityEnvironment: $scenario['community_environment'],
                communityFeature: $scenario['community_feature'],
                communityContract: $scenario['community_contract']
            ), null, 'value');

            self::assertSame($status, $byProvider['fluentcrm']['status']);
            self::assertSame(
                '/settings?category=integrations&provider=fluentcrm',
                $byProvider['fluentcrm']['repair_url']
            );
            self::assertSame($status, $byProvider['fluent_community']['status']);
            self::assertSame(
                '/settings?category=integrations&provider=fluent_community',
                $byProvider['fluent_community']['repair_url']
            );
        }
    }

    public function test_healthy_crm_uses_non_negative_projection_counts_and_ignores_generic_operations(): void
    {
        $summary = array_column($this->providerSummaries(
            [],
            ['pending' => -4, 'failed' => -2],
            ['fluentcrm' => ['pending_operations' => 7, 'failed_operations' => 8]]
        ), null, 'value')['fluentcrm'];

        self::assertSame('healthy', $summary['status']);
        self::assertSame(0, $summary['pending_operations']);
        self::assertSame(0, $summary['failed_operations']);
    }

    public function test_degraded_crm_uses_canonical_projection_counts_without_double_counting(): void
    {
        $summary = array_column($this->providerSummaries(
            [],
            ['pending' => 3, 'failed' => 2],
            ['fluentcrm' => ['pending_operations' => 7, 'failed_operations' => 8]]
        ), null, 'value')['fluentcrm'];

        self::assertSame('degraded', $summary['status']);
        self::assertSame(3, $summary['pending_operations']);
        self::assertSame(2, $summary['failed_operations']);
        self::assertNull($summary['last_successful_reconciliation']);
        self::assertSame('/integrations?provider=fluentcrm', $summary['repair_url']);
    }

    public function test_unreadable_crm_projection_jobs_fail_read_safe_without_generic_operation_counts(): void
    {
        $summary = array_column($this->providerSummaries(
            [],
            [],
            ['fluentcrm' => ['pending_operations' => 7, 'failed_operations' => 8]],
            false
        ), null, 'value')['fluentcrm'];

        self::assertSame('degraded', $summary['status']);
        self::assertSame(0, $summary['pending_operations']);
        self::assertSame(0, $summary['failed_operations']);
        self::assertNull($summary['last_successful_reconciliation']);
    }

    private function providerSummaries(
        array $summaryOverrides,
        array $jobOverrides,
        array $operationSummaries = [],
        bool $jobsReadable = true,
        array $crmProvider = ['active' => true, 'version' => '3.1.8'],
        bool $crmCompatible = true,
        string $crmEnabled = 'yes',
        array $communityEnvironment = [
            'core_active' => true,
            'core_version' => '2.7.0',
            'pro_active' => false,
            'pro_version' => '2.7.0',
            'pro_certified' => false,
        ],
        bool $communityFeature = true,
        bool $communityContract = true
    ): array
    {
        $operations = new class($operationSummaries) extends ProviderOperationRepository {
            public function __construct(private array $summaries)
            {
            }

            public function summarizeByProvider(): array
            {
                return $this->summaries;
            }
        };
        $community = new CommunityCapabilityRegistry(
            static fn(): array => $communityEnvironment,
            static fn(string $feature): bool => $communityFeature,
            static fn(string $capability): bool => $communityContract
        );
        $jobSummaryResolver = $jobsReadable
            ? static fn(): array => array_replace([
                'pending' => 0,
                'failed' => 0,
                'last_success_at' => '2026-07-23 09:55:00',
            ], $jobOverrides)
            : static function (): array {
                throw new \RuntimeException('Projection job storage is unreadable.');
            };
        $crm = new FluentCrmIntegrationHealth(
            static fn(): array => $crmProvider,
            static fn(): bool => $crmCompatible,
            static fn(): array => ['fluentcrm_enabled' => $crmEnabled],
            static fn(): array => ['tags' => 2, 'lists' => 1],
            static fn(): array => array_replace([
                'last_reconciliation' => '2026-07-23 10:00:00',
                'processed' => 10,
                'failed' => 0,
                'drift' => 0,
            ], $summaryOverrides),
            static fn(array $summary): bool => true,
            static fn(): string => '2026-07-23 10:00:00',
            $jobSummaryResolver
        );
        $service = new ProviderReconciliationService(
            operations: $operations,
            extensions: [],
            crmHealth: $crm,
            communityCapabilities: $community,
            runtimeProviderResolver: static fn(): array => []
        );

        return $service->providerSummaries();
    }
}
