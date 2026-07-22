<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\Grant\PlanGrantExecutionService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProviderOutcomeOwnershipTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProviderOutcomeFakeAdapter::reset();
    }

    public function test_failed_provider_grant_is_not_persisted(): void
    {
        ProviderOutcomeFakeAdapter::$grantResult = [
            'success' => false,
            'message' => 'Provider refused assignment',
            'code' => 'provider_rejected',
        ];
        $created = [];
        $service = $this->creationService(null, $created);

        $result = $service->grantResource(17, 'test_provider', 'course', '41');

        self::assertSame('failed', $result['action']);
        self::assertSame('Provider refused assignment', $result['message']);
        self::assertSame('provider_rejected', $result['provider_result']['code']);
        self::assertSame([], $created);
    }

    public function test_new_provider_assignment_records_fchub_ownership(): void
    {
        $created = [];
        $service = $this->creationService(null, $created);

        $result = $service->grantResource(17, 'test_provider', 'course', '41');

        self::assertSame('created', $result['action']);
        self::assertSame('fchub', $created[0]['meta']['provider_access_owner']);
        self::assertSame(1, ProviderOutcomeFakeAdapter::$checkCalls);
        self::assertSame(1, ProviderOutcomeFakeAdapter::$grantCalls);
    }

    public function test_preexisting_provider_assignment_is_not_claimed_by_fchub(): void
    {
        ProviderOutcomeFakeAdapter::$hasAccess = true;
        $created = [];
        $service = $this->creationService(null, $created);

        $result = $service->grantResource(17, 'test_provider', 'course', '41');

        self::assertSame('created', $result['action']);
        self::assertSame('preexisting', $created[0]['meta']['provider_access_owner']);
        self::assertSame(1, ProviderOutcomeFakeAdapter::$grantCalls);
    }

    public function test_existing_grant_replays_provider_before_renewal_and_preserves_unknown_legacy_ownership(): void
    {
        ProviderOutcomeFakeAdapter::$hasAccess = true;
        $created = [];
        $updates = [];
        $existing = $this->grant(['meta' => []]);
        $service = $this->creationService($existing, $created, $updates);

        $result = $service->grantResource(17, 'test_provider', 'course', '41');

        self::assertSame('updated', $result['action']);
        self::assertSame(1, ProviderOutcomeFakeAdapter::$grantCalls);
        self::assertSame('unknown', $updates[0][1]['meta']['provider_access_owner']);
    }

    public function test_existing_grant_is_not_renewed_when_provider_replay_fails(): void
    {
        ProviderOutcomeFakeAdapter::$grantResult = [
            'success' => false,
            'message' => 'Renewal replay failed',
            'details' => ['resource_id' => '41'],
        ];
        $created = [];
        $updates = [];
        $service = $this->creationService($this->grant(), $created, $updates);

        $result = $service->grantResource(17, 'test_provider', 'course', '41');

        self::assertSame('failed', $result['action']);
        self::assertSame(['resource_id' => '41'], $result['provider_result']['details']);
        self::assertSame([], $updates);
    }

    public function test_new_assignment_is_compensated_when_local_persistence_fails(): void
    {
        $created = [];
        $updates = [];
        $service = $this->creationService(null, $created, $updates, 0);

        $result = $service->grantResource(17, 'test_provider', 'course', '41');

        self::assertSame('failed', $result['action']);
        self::assertSame(1, ProviderOutcomeFakeAdapter::$revokeCalls);
        self::assertTrue($result['compensation']['success']);
    }

    public function test_new_assignment_is_compensated_when_local_persistence_throws(): void
    {
        $created = [];
        $updates = [];
        $service = $this->creationService(null, $created, $updates, 71, true);

        try {
            $result = $service->grantResource(17, 'test_provider', 'course', '41');
        } catch (\RuntimeException $exception) {
            self::fail('The persistence exception escaped before provider compensation: ' . $exception->getMessage());
        }

        self::assertSame('failed', $result['action']);
        self::assertSame(1, ProviderOutcomeFakeAdapter::$revokeCalls);
        self::assertSame('Database unavailable', $result['persistence_error']['message']);
    }

    public function test_revocation_detaches_only_fchub_owned_provider_access(): void
    {
        ProviderOutcomeFakeAdapter::$hasAccess = true;
        $updates = [];
        $service = $this->revocationService([
            $this->grant(['id' => 1, 'meta' => ['provider_access_owner' => 'fchub']]),
            $this->grant(['id' => 2, 'resource_id' => '42', 'meta' => ['provider_access_owner' => 'preexisting']]),
            $this->grant(['id' => 3, 'resource_id' => '43', 'meta' => []]),
        ], $updates);

        $result = $service->revokePlan(17, 5);

        self::assertSame(3, $result['revoked']);
        self::assertSame(0, $result['failed']);
        self::assertSame(1, ProviderOutcomeFakeAdapter::$revokeCalls);
        self::assertSame([1, 2, 3], array_column($updates, 0));
    }

    public function test_failed_provider_revoke_does_not_close_or_count_the_grant_or_notify(): void
    {
        ProviderOutcomeFakeAdapter::$hasAccess = true;
        ProviderOutcomeFakeAdapter::$revokeResult = [
            'success' => false,
            'message' => 'Provider detach failed',
            'details' => ['remote_status' => 503],
        ];
        $updates = [];
        $hookCalls = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_revoked'] = [
            static function () use (&$hookCalls): void {
                $hookCalls++;
            },
        ];
        $service = $this->revocationService([
            $this->grant(['meta' => ['provider_access_owner' => 'fchub']]),
        ], $updates);

        $result = $service->revokePlan(17, 5);

        self::assertSame(0, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertSame(503, $result['errors'][0]['provider_result']['details']['remote_status']);
        self::assertSame([], $updates);
        self::assertSame(0, $hookCalls);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
    }

    public function test_provider_detach_is_compensated_when_local_revocation_throws(): void
    {
        ProviderOutcomeFakeAdapter::$hasAccess = true;
        $updates = [];
        $service = $this->revocationService([
            $this->grant(['meta' => ['provider_access_owner' => 'fchub']]),
        ], $updates, true);

        try {
            $result = $service->revokePlan(17, 5);
        } catch (\RuntimeException $exception) {
            self::fail('The persistence exception escaped before provider compensation: ' . $exception->getMessage());
        }

        self::assertSame(0, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, ProviderOutcomeFakeAdapter::$revokeCalls);
        self::assertSame(1, ProviderOutcomeFakeAdapter::$grantCalls);
        self::assertSame('Database unavailable', $result['errors'][0]['persistence_error']['message']);
    }

    public function test_plan_failure_is_reported_without_success_notification_or_hook(): void
    {
        ProviderOutcomeFakeAdapter::$grantResult = [
            'success' => false,
            'message' => 'Remote grant failed',
        ];
        $created = [];
        $creation = $this->creationService(null, $created);
        $planRepository = new class extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'title' => 'Gold Plan',
                    'slug' => 'gold-plan',
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => [],
                ];
            }
        };
        $grantRepository = new class extends GrantRepository {
            public function __construct()
            {
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [];
            }
        };
        $rules = new class extends PlanRuleResolver {
            public function resolveUniqueRules(int $planId): array
            {
                return [[
                    'id' => 9,
                    'provider' => 'test_provider',
                    'resource_type' => 'course',
                    'resource_id' => '41',
                    'drip_type' => 'immediate',
                ]];
            }
        };
        $modes = new MembershipModeService($grantRepository, $planRepository);
        $context = new GrantPlanContextService($planRepository, $grantRepository);
        $notifications = new GrantNotificationService($planRepository);
        $revocation = $this->revocationService([], $unusedUpdates);
        $hookCalls = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_created'] = [
            static function () use (&$hookCalls): void {
                $hookCalls++;
            },
        ];
        $service = new PlanGrantExecutionService(
            $rules,
            $modes,
            $context,
            $creation,
            $revocation,
            $notifications
        );

        $result = $service->grantPlan(17, 5);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['failed']);
        self::assertSame('Remote grant failed', $result['errors'][0]['message']);
        self::assertSame(0, $hookCalls);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
    }

    private function creationService(
        ?array $existing,
        array &$created,
        array &$updates = [],
        int $createResult = 71,
        bool $throwOnCreate = false
    ): GrantCreationService {
        $grants = new class($existing, $created, $updates, $createResult, $throwOnCreate) extends GrantRepository {
            public function __construct(
                private ?array $existing,
                private array &$created,
                private array &$updates,
                private int $createResult,
                private bool $throwOnCreate
            ) {
            }

            public function findByGrantKey(string $grantKey): ?array
            {
                return $this->existing;
            }

            public function create(array $data): int
            {
                $this->created[] = $data;
                if ($this->throwOnCreate) {
                    throw new \RuntimeException('Database unavailable');
                }

                return $this->createResult;
            }

            public function update(int $id, array $data): bool
            {
                $this->updates[] = [$id, $data];
                if ($this->existing !== null) {
                    $this->existing = array_replace($this->existing, $data);
                }
                return true;
            }

            public function find(int $id): ?array
            {
                return $this->existing;
            }
        };

        return new GrantCreationService(
            $grants,
            new class extends GrantSourceRepository {
                public function __construct()
                {
                }

                public function addSource(int $grantId, string $sourceType, int $sourceId): bool
                {
                    return true;
                }
            },
            new class extends DripScheduleRepository {
                public function __construct()
                {
                }
            },
            new GrantAdapterRegistry(['test_provider' => ProviderOutcomeFakeAdapter::class])
        );
    }

    private function revocationService(array $grants, ?array &$updates, bool $throwOnUpdate = false): GrantRevocationService
    {
        $updates ??= [];
        $repository = new class($grants, $updates, $throwOnUpdate) extends GrantRepository {
            public function __construct(
                private array $grants,
                private array &$updates,
                private bool $throwOnUpdate
            )
            {
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return $this->grants;
            }

            public function update(int $id, array $data): bool
            {
                if ($this->throwOnUpdate) {
                    throw new \RuntimeException('Database unavailable');
                }

                $this->updates[] = [$id, $data];
                return true;
            }
        };

        return new GrantRevocationService(
            $repository,
            new class extends GrantSourceRepository {
                public function __construct()
                {
                }

                public function removeAllByGrant(int $grantId): bool
                {
                    return true;
                }
            },
            new class extends DripScheduleRepository {
                public function __construct()
                {

                }

                public function deleteByGrantId(int $grantId): int
                {
                    return 1;
                }
            },
            new GrantAdapterRegistry(['test_provider' => ProviderOutcomeFakeAdapter::class]),
            new GrantNotificationService()
        );
    }

    private function grant(array $overrides = []): array
    {
        return array_merge([
            'id' => 71,
            'user_id' => 17,
            'plan_id' => 5,
            'provider' => 'test_provider',
            'resource_type' => 'course',
            'resource_id' => '41',
            'source_type' => 'manual',
            'source_ids' => [],
            'renewal_count' => 0,
            'expires_at' => null,
            'meta' => [],
            'status' => 'active',
        ], $overrides);
    }
}

final class ProviderOutcomeFakeAdapter
{
    public static bool $hasAccess = false;
    public static array $grantResult = ['success' => true, 'message' => 'Granted'];
    public static array $revokeResult = ['success' => true, 'message' => 'Revoked'];
    public static int $checkCalls = 0;
    public static int $grantCalls = 0;
    public static int $revokeCalls = 0;

    public static function reset(): void
    {
        self::$hasAccess = false;
        self::$grantResult = ['success' => true, 'message' => 'Granted'];
        self::$revokeResult = ['success' => true, 'message' => 'Revoked'];
        self::$checkCalls = 0;
        self::$grantCalls = 0;
        self::$revokeCalls = 0;
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        self::$checkCalls++;
        return self::$hasAccess;
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$grantCalls++;
        if (self::$grantResult['success']) {
            self::$hasAccess = true;
        }

        return self::$grantResult;
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$revokeCalls++;
        if (self::$revokeResult['success']) {
            self::$hasAccess = false;
        }

        return self::$revokeResult;
    }
}
