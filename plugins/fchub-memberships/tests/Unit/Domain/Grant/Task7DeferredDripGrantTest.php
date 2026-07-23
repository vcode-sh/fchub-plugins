<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Entitlement\EntitlementService;
use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class Task7DeferredDripGrantTest extends PluginTestCase
{
    public function test_future_external_drip_projects_active_desired_edge_and_persists_exact_deferred_operation(): void
    {
        $timezone = new \DateTimeZone('UTC');
        $clock = new Clock(new \DateTimeImmutable('2026-03-14 12:30:00', $timezone), $timezone);
        $entitlements = new Task7DeferredEntitlementService();
        $operations = new Task7DeferredProviderWorker();
        $grants = new class extends GrantRepository {
            public function __construct()
            {
            }

            public function findByGrantKey(string $grantKey): ?array
            {
                return ['id' => 71, 'status' => 'active'];
            }
        };
        $service = new GrantCreationService(
            $grants,
            new class extends GrantSourceRepository {
                public function __construct()
                {
                }
            },
            new class extends DripScheduleRepository {
                public function __construct()
                {
                }
            },
            new GrantAdapterRegistry(),
            $clock,
            $entitlements,
            $operations
        );

        $result = $service->grantResource(17, 'fluentcrm', 'fluentcrm_tag', '41', [
            'plan_id' => 5,
            'source_type' => 'subscription',
            'source_id' => 91,
            'drip_rule' => ['id' => 12, 'drip_type' => 'delayed', 'drip_delay_days' => 2],
            'origin_event' => 'subscription_activated:91',
        ]);

        self::assertTrue($entitlements->projectCompatibility);
        self::assertSame('2026-03-16 12:30:00', $operations->eligibleAt?->format('Y-m-d H:i:s'));
        self::assertSame('grant', $operations->desiredAction);
        self::assertSame(0, $operations->processCalls);
        self::assertSame('pending', $result['action']);
        self::assertSame('deferred', $result['provider_outcome']->status);
        self::assertSame(71, $result['grant_id']);
    }
}

final class Task7DeferredEntitlementService extends EntitlementService
{
    public bool $projectCompatibility = false;

    public function __construct()
    {
    }

    public function findByIdentity(array $identity): ?array
    {
        return null;
    }

    public function activate(array $identity, array $attributes = [], bool $projectCompatibility = true): array
    {
        $this->projectCompatibility = $projectCompatibility;
        return [
            'action' => 'created',
            'edge' => array_merge($identity, $attributes, [
                'id' => 7,
                'lifecycle' => 'active',
            ]),
        ];
    }

    public function activateFromProviderObservation(
        array $identity,
        array $attributes,
        ?bool $providerHasAccess,
        bool $projectCompatibility = true
    ): array {
        return $this->activate($identity, $attributes, $projectCompatibility);
    }
}

final class Task7DeferredProviderWorker extends ProviderOperationWorker
{
    public ?\DateTimeImmutable $eligibleAt = null;
    public string $desiredAction = '';
    public int $processCalls = 0;

    public function __construct()
    {
    }

    public function enqueue(
        int $edgeId,
        string $desiredAction,
        string $originEvent,
        ?\DateTimeImmutable $eligibleAt = null
    ): ?array {
        $this->desiredAction = $desiredAction;
        $this->eligibleAt = $eligibleAt;
        return ['id' => 9, 'state' => 'deferred'];
    }

    public function process(int $operationId): ProviderOperationOutcome
    {
        $this->processCalls++;
        return ProviderOperationOutcome::applied();
    }
}
