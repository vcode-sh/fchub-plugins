<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\SubscriptionGrantLifecycleService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class SubscriptionGrantLifecycleServiceTest extends PluginTestCase
{
    public function test_pause_only_pauses_active_subscription_grants(): void
    {
        $grantService = new class() extends AccessGrantService {
            /** @var array<int, array{id:int, reason:string}> */
            public array $paused = [];

            public function __construct()
            {
            }

            public function pauseGrant(int $grantId, string $reason = ''): array
            {
                $this->paused[] = ['id' => $grantId, 'reason' => $reason];
                return ['success' => true];
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct()
            {
            }

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    ['id' => 1, 'status' => 'active'],
                    ['id' => 2, 'status' => 'paused'],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->pause((object) ['id' => 99]);

        self::assertSame([['id' => 1, 'reason' => 'Subscription paused']], $grantService->paused);
    }

    public function test_renew_extends_only_active_grants_with_next_billing(): void
    {
        $grantService = new class() extends AccessGrantService {
            /** @var array<int, array{user_id:int, plan_id:int, expires_at:string, source_id:int}> */
            public array $extended = [];

            public function __construct()
            {
            }

            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = [
                    'user_id' => $userId,
                    'plan_id' => $planId,
                    'expires_at' => $newExpiresAt,
                    'source_id' => (int) $renewalSourceId,
                ];

                return 1;
            }
        };

        $grantRepo = new class() extends GrantRepository {
            public function __construct()
            {
            }

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [
                    ['user_id' => 10, 'plan_id' => 3, 'status' => 'active'],
                    ['user_id' => 11, 'plan_id' => 4, 'status' => 'paused'],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-01 00:00:00']);

        self::assertSame([[
            'user_id' => 10,
            'plan_id' => 3,
            'expires_at' => '2026-04-01 00:00:00',
            'source_id' => 55,
        ]], $grantService->extended);
    }
}
