<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\SubscriptionPaymentFailureService;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantRenewalProvenanceTest extends PluginTestCase
{
    public function test_matching_subscription_renewal_marks_a_single_payment_incident_recovery_on_the_post_update_snapshot(): void
    {
        $grant = [
            'id' => 31,
            'user_id' => 17,
            'plan_id' => 8,
            'provider' => 'provenance',
            'resource_type' => 'course',
            'resource_id' => '9',
            'grant_key' => GrantRepository::makeGrantKey(17, 'provenance', 'course', '9'),
            'source_ids' => [77],
            'renewal_count' => 2,
            'meta' => [
                'payment_incident' => [
                    'subscription_id' => 77,
                    'failure_count' => 1,
                    'first_failed_at' => '2026-07-20 10:00:00',
                    'last_failed_at' => '2026-07-20 10:00:00',
                    'recovered_at' => null,
                    'recovery_renewal_count' => null,
                ],
            ],
        ];
        $repository = new class($grant) extends GrantRepository {
            public function __construct(private array $grant) {}
            public function findByGrantKey(string $grantKey): ?array { return $this->grant; }
            public function update(int $id, array $data): bool { $this->grant = array_replace($this->grant, $data); return true; }
            public function find(int $id): ?array { return $this->grant; }
        };
        $service = new GrantCreationService(
            $repository,
            new class extends GrantSourceRepository { public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; } },
            new class extends DripScheduleRepository {},
            new GrantAdapterRegistry(['provenance' => GrantRenewalProvenanceAdapter::class])
        );
        $renewed = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_renewed'] = [static function (array $snapshot, int $renewalCount) use (&$renewed): void { $renewed[] = [$snapshot, $renewalCount]; }];

        $service->grantResource(17, 'provenance', 'course', '9', ['source_type' => 'subscription', 'source_id' => 77]);
        $service->grantResource(17, 'provenance', 'course', '9', ['source_type' => 'subscription', 'source_id' => 77]);

        self::assertSame(3, $renewed[0][1]);
        self::assertSame(3, $renewed[0][0]['renewal_count']);
        self::assertSame(3, $renewed[0][0]['meta']['payment_incident']['recovery_renewal_count']);
        self::assertNotEmpty($renewed[0][0]['meta']['payment_incident']['recovered_at']);
        self::assertSame(3, $renewed[1][0]['meta']['payment_incident']['recovery_renewal_count']);
    }

    public function test_payment_failure_persists_a_safe_incident_without_the_raw_provider_event(): void
    {
        $updates = [];
        $repository = new class($updates) extends GrantRepository {
            public function __construct(private array &$updates) {}
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array { return [['id' => 31, 'meta' => ['kept' => 'yes']]]; }
            public function update(int $id, array $data): bool { $this->updates[] = [$id, $data]; return true; }
        };

        (new SubscriptionPaymentFailureService($repository))->handle(['subscription' => (object) ['id' => 77, 'provider_payload' => ['secret' => 'do-not-store']]], 'subscription');

        self::assertSame('yes', $updates[0][1]['meta']['kept']);
        self::assertSame(77, $updates[0][1]['meta']['payment_incident']['subscription_id']);
        self::assertArrayNotHasKey('provider_payload', $updates[0][1]['meta']['payment_incident']);
        self::assertNull($updates[0][1]['meta']['payment_incident']['recovered_at']);
    }

    public function test_manual_and_different_subscription_renewals_do_not_consume_the_incident(): void
    {
        foreach ([
            ['source_type' => 'manual', 'source_id' => 77],
            ['source_type' => 'subscription', 'source_id' => 88],
        ] as $context) {
            [$service, $repository] = $this->renewalService();
            $service->grantResource(17, 'provenance', 'course', '9', $context);
            $incident = $repository->find(31)['meta']['payment_incident'];
            self::assertNull($incident['recovered_at']);
            self::assertNull($incident['recovery_renewal_count']);
        }
    }

    public function test_payment_failure_persistence_failure_emits_no_failure_transition(): void
    {
        $repository = new class extends GrantRepository {
            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array { return [['id' => 31, 'meta' => []]]; }
            public function update(int $id, array $data): bool { return false; }
        };
        $events = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/payment_failed'] = [static function (...$args) use (&$events): void { $events[] = $args; }];

        (new SubscriptionPaymentFailureService($repository))->handle(['subscription' => (object) ['id' => 77]], 'subscription');

        self::assertSame([], $events);
    }

    private function renewalService(): array
    {
        $grant = [
            'id' => 31, 'user_id' => 17, 'plan_id' => 8, 'provider' => 'provenance',
            'resource_type' => 'course', 'resource_id' => '9', 'source_ids' => [77],
            'renewal_count' => 2, 'meta' => ['payment_incident' => [
                'subscription_id' => 77, 'failure_count' => 1,
                'first_failed_at' => '2026-07-20 10:00:00', 'last_failed_at' => '2026-07-20 10:00:00',
                'recovered_at' => null, 'recovery_renewal_count' => null,
            ]],
        ];
        $repository = new class($grant) extends GrantRepository {
            public function __construct(private array $grant) {}
            public function findByGrantKey(string $grantKey): ?array { return $this->grant; }
            public function update(int $id, array $data): bool { $this->grant = array_replace($this->grant, $data); return true; }
            public function find(int $id): ?array { return $this->grant; }
        };
        return [new GrantCreationService(
            $repository,
            new class extends GrantSourceRepository { public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; } },
            new class extends DripScheduleRepository {},
            new GrantAdapterRegistry(['provenance' => GrantRenewalProvenanceAdapter::class])
        ), $repository];
    }
}

final class GrantRenewalProvenanceAdapter
{
    public function check(int $userId, string $resourceType, string $resourceId): bool { return false; }
    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array { return ['success' => true]; }
}
