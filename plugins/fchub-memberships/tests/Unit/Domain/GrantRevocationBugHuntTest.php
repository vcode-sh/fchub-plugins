<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/controller-stubs.php';

final class GrantRevocationBugHuntTest extends PluginTestCase
{
    // --- BUG A: revokePlan() now includes paused grants ---

    public function test_revoke_plan_includes_paused_grants(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            /** @var array */
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                // Return both active and paused grants — no status filter should be present
                return [
                    [
                        'id' => 1,
                        'user_id' => $userId,
                        'plan_id' => $filters['plan_id'] ?? 5,
                        'status' => 'active',
                        'source_type' => 'subscription',
                        'source_ids' => [],
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '10',
                        'meta' => [],
                    ],
                    [
                        'id' => 2,
                        'user_id' => $userId,
                        'plan_id' => $filters['plan_id'] ?? 5,
                        'status' => 'paused',
                        'source_type' => 'subscription',
                        'source_ids' => [],
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '11',
                        'meta' => [],
                    ],
                ];
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeAllByGrant(int $grantId): bool { return true; }
            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };

        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };

        $service = new GrantRevocationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            new GrantAdapterRegistry([]),
            new GrantNotificationService()
        );

        $result = $service->revokePlan(1, 5);

        // Both active and paused grants should be revoked
        self::assertSame(2, $result['revoked']);
        self::assertSame(0, $result['retained']);

        // Verify both grants received status='revoked' updates
        $revokedIds = array_map(
            static fn(array $u): int => $u['id'],
            array_filter($updates, static fn(array $u): bool => ($u['data']['status'] ?? '') === 'revoked')
        );
        self::assertContains(1, $revokedIds, 'Active grant should be revoked');
        self::assertContains(2, $revokedIds, 'Paused grant should be revoked');
    }

    public function test_revoke_plan_skips_expired_grants(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [
                    [
                        'id' => 1,
                        'user_id' => $userId,
                        'plan_id' => 5,
                        'status' => 'expired',
                        'source_type' => 'subscription',
                        'source_ids' => [],
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '10',
                        'meta' => [],
                    ],
                ];
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeAllByGrant(int $grantId): bool { return true; }
            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };

        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };

        $service = new GrantRevocationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            new GrantAdapterRegistry([]),
            new GrantNotificationService()
        );

        $result = $service->revokePlan(1, 5);

        // expired->revoked is not a valid transition per StatusTransitionValidator
        self::assertSame(0, $result['revoked']);
        self::assertEmpty($updates);
    }

    // --- BUG B: grant_revoked hook only fires when something was actually revoked ---

    public function test_grant_revoked_hook_not_fired_when_nothing_revoked(): void
    {
        $hookFired = false;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_revoked'] = [
            static function () use (&$hookFired): void {
                $hookFired = true;
            },
        ];

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}

            public function getByUserId(int $userId, array $filters = []): array
            {
                // Return an expired grant — cannot transition to revoked
                return [
                    [
                        'id' => 1,
                        'user_id' => $userId,
                        'plan_id' => 5,
                        'status' => 'expired',
                        'source_type' => 'subscription',
                        'source_ids' => [],
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '10',
                        'meta' => [],
                    ],
                ];
            }

            public function update(int $id, array $data): bool { return true; }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeAllByGrant(int $grantId): bool { return true; }
            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };

        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };

        $service = new GrantRevocationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            new GrantAdapterRegistry([]),
            new GrantNotificationService()
        );

        $result = $service->revokePlan(1, 5);

        self::assertSame(0, $result['revoked']);
        self::assertFalse($hookFired, 'grant_revoked hook should not fire when nothing was revoked');
    }

    public function test_grant_revoked_hook_fires_when_grants_are_revoked(): void
    {
        $hookFired = false;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_revoked'] = [
            static function () use (&$hookFired): void {
                $hookFired = true;
            },
        ];

        $grantRepo = new class() extends GrantRepository {
            public function __construct() {}

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [
                    [
                        'id' => 1,
                        'user_id' => $userId,
                        'plan_id' => 5,
                        'status' => 'active',
                        'source_type' => 'subscription',
                        'source_ids' => [],
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '10',
                        'meta' => [],
                    ],
                ];
            }

            public function update(int $id, array $data): bool { return true; }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeAllByGrant(int $grantId): bool { return true; }
            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };

        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };

        $service = new GrantRevocationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            new GrantAdapterRegistry([]),
            new GrantNotificationService()
        );

        $result = $service->revokePlan(1, 5);

        self::assertSame(1, $result['revoked']);
        self::assertTrue($hookFired, 'grant_revoked hook should fire when grants were revoked');
    }

    // --- BUG C: revokeBySource() skips expired and revoked grants ---

    public function test_revoke_by_source_skips_expired_grants(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1,
                        'user_id' => 10,
                        'plan_id' => 5,
                        'status' => 'expired',
                        'source_type' => 'order',
                        'source_ids' => [100],
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '10',
                        'meta' => [],
                    ],
                ];
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeAllByGrant(int $grantId): bool { return true; }
            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };

        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };

        $service = new GrantRevocationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            new GrantAdapterRegistry([]),
            new GrantNotificationService()
        );

        $result = $service->revokeBySource(100);

        self::assertSame(0, $result['revoked']);
        self::assertSame(0, $result['retained']);
        self::assertEmpty($updates, 'Expired grant should not receive any updates');
    }

    public function test_revoke_by_source_skips_already_revoked_grants(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1,
                        'user_id' => 10,
                        'plan_id' => 5,
                        'status' => 'revoked',
                        'source_type' => 'order',
                        'source_ids' => [100],
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '10',
                        'meta' => [],
                    ],
                ];
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeAllByGrant(int $grantId): bool { return true; }
            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };

        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };

        $service = new GrantRevocationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            new GrantAdapterRegistry([]),
            new GrantNotificationService()
        );

        $result = $service->revokeBySource(100);

        self::assertSame(0, $result['revoked']);
        self::assertEmpty($updates, 'Already-revoked grant should not receive any updates');
    }

    public function test_revoke_by_source_processes_active_grant(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1,
                        'user_id' => 10,
                        'plan_id' => 5,
                        'status' => 'active',
                        'source_type' => 'order',
                        'source_ids' => [100],
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '10',
                        'meta' => [],
                    ],
                ];
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeAllByGrant(int $grantId): bool { return true; }
            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };

        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };

        $service = new GrantRevocationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            new GrantAdapterRegistry([]),
            new GrantNotificationService()
        );

        $result = $service->revokeBySource(100);

        self::assertSame(1, $result['revoked']);
        self::assertNotEmpty($updates);
        self::assertSame('revoked', $updates[0]['data']['status']);
    }

    public function test_revoke_by_source_mixed_statuses(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    [
                        'id' => 1, 'user_id' => 10, 'plan_id' => 5,
                        'status' => 'active', 'source_type' => 'order',
                        'source_ids' => [100], 'provider' => 'wordpress_core',
                        'resource_type' => 'post', 'resource_id' => '10', 'meta' => [],
                    ],
                    [
                        'id' => 2, 'user_id' => 10, 'plan_id' => 5,
                        'status' => 'expired', 'source_type' => 'order',
                        'source_ids' => [100], 'provider' => 'wordpress_core',
                        'resource_type' => 'post', 'resource_id' => '11', 'meta' => [],
                    ],
                    [
                        'id' => 3, 'user_id' => 10, 'plan_id' => 5,
                        'status' => 'paused', 'source_type' => 'order',
                        'source_ids' => [100], 'provider' => 'wordpress_core',
                        'resource_type' => 'post', 'resource_id' => '12', 'meta' => [],
                    ],
                ];
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function removeAllByGrant(int $grantId): bool { return true; }
            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };

        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
            public function deleteByGrantId(int $grantId): int { return 0; }
        };

        $service = new GrantRevocationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            new GrantAdapterRegistry([]),
            new GrantNotificationService()
        );

        $result = $service->revokeBySource(100);

        // Active (id=1) and paused (id=3) should be revoked. Expired (id=2) should be skipped.
        self::assertSame(2, $result['revoked']);

        $revokedIds = array_map(
            static fn(array $u): int => $u['id'],
            array_filter($updates, static fn(array $u): bool => ($u['data']['status'] ?? '') === 'revoked')
        );
        self::assertContains(1, $revokedIds);
        self::assertContains(3, $revokedIds);
        self::assertNotContains(2, array_column($updates, 'id'), 'Expired grant should not be updated');
    }
}
