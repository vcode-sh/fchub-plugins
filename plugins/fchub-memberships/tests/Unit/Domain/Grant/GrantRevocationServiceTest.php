<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Tests\Unit\PluginTestCase;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;

final class GrantRevocationServiceTest extends PluginTestCase
{
    public function test_grant_revocation_service_covers_grace_period_source_retention_and_grace_expiry(): void
    {
        $updates = [];
        $deletedDrips = [];

        $grants = new class($updates) extends GrantRepository {
            public function __construct(private array &$updates)
            {
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [[
                    'id' => 10,
                    'user_id' => $userId,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'source_type' => 'order',
                    'source_ids' => [77, 88],
                    'meta' => [],
                    'status' => 'active',
                ]];
            }

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [[
                    'id' => 11,
                    'user_id' => 21,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'source_type' => 'subscription',
                    'source_ids' => [$sourceId],
                    'meta' => [],
                    'status' => 'active',
                ]];
            }

            public function update(int $id, array $data): bool
            {
                $this->updates[] = [$id, $data];
                return true;
            }

            public function getDueGracePeriodGrants(int $limit = 100): array
            {
                return [[
                    'id' => 12,
                    'user_id' => 21,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'meta' => [],
                    'status' => 'active',
                    'cancellation_reason' => 'Expired grace',
                ]];
            }
        };

        $sources = new class extends GrantSourceRepository {
            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool
            {
                return true;
            }

            public function removeAllByGrant(int $grantId): bool
            {
                return true;
            }
        };

        $drips = new class($deletedDrips) extends DripScheduleRepository {
            public function __construct(private array &$deletedDrips)
            {
            }

            public function deleteByGrantId(int $grantId): int
            {
                $this->deletedDrips[] = $grantId;
                return 1;
            }
        };

        $adapterRegistry = new GrantAdapterRegistry(['wordpress_core' => GrantRevocationServiceFakeAdapter::class]);
        $GLOBALS['_fchub_test_options']['admin_email'] = 'admin@example.com';
        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Alice Example';
        $user->user_email = 'alice@example.com';
        $user->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $user;

        $notifications = new GrantNotificationService(new class extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return ['id' => $id, 'title' => 'Gold Plan', 'slug' => 'gold-plan'];
            }
        });

        $service = new GrantRevocationService($grants, $sources, $drips, $adapterRegistry, $notifications);

        $grace = $service->revokePlan(21, 5, ['grace_period_days' => 3, 'reason' => 'Canceled']);
        $source = $service->revokeBySource(77, 'subscription', ['reason' => 'Canceled']);
        $expired = $service->revokeExpiredGracePeriodGrants();

        self::assertSame(1, $grace['revoked']);
        self::assertSame(1, $source['revoked']);
        self::assertSame(1, $expired);
        self::assertNotEmpty($updates);
        self::assertContains(11, $deletedDrips);
        self::assertContains(12, $deletedDrips);
        self::assertNotEmpty($GLOBALS['_fchub_test_mails']);
    }
}

final class GrantRevocationServiceFakeAdapter
{
    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => true];
    }
}
