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
                    ['id' => 1, 'status' => 'active', 'meta' => []],
                    ['id' => 2, 'status' => 'paused', 'meta' => []],
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
                    ['user_id' => 10, 'plan_id' => 3, 'status' => 'active', 'meta' => []],
                    ['user_id' => 11, 'plan_id' => 4, 'status' => 'paused', 'meta' => []],
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

    public function test_anchor_ontime_renewal_snaps_to_next_anchor(): void
    {
        $grantService = new class() extends AccessGrantService {
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
                    [
                        'id' => 1,
                        'user_id' => 10,
                        'plan_id' => 3,
                        'status' => 'active',
                        'expires_at' => '2026-03-20 23:59:59',
                        'meta' => ['billing_anchor_day' => 20],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        // On-time renewal: subscription renews before anchor day
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-18 00:00:00']);

        // Should snap to April 20 (next anchor after current March 20), NOT April 18
        self::assertCount(1, $grantService->extended);
        self::assertSame('2026-04-20 23:59:59', $grantService->extended[0]['expires_at']);
        self::assertSame(10, $grantService->extended[0]['user_id']);
        self::assertSame(3, $grantService->extended[0]['plan_id']);
        self::assertSame(55, $grantService->extended[0]['source_id']);
    }

    public function test_anchor_late_payment_resumes_and_snaps_to_next_anchor(): void
    {
        // current_time stub returns '2026-03-13 22:00:00' (day 13).
        // Anchor day 10 already passed → nextAnchorDate(10, March 13) = April 10.
        $grantService = new class() extends AccessGrantService {
            public array $resumed = [];
            public array $extended = [];

            public function __construct()
            {
            }

            public function resumeGrant(int $grantId): array
            {
                $this->resumed[] = $grantId;
                return ['success' => true];
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
                    [
                        'id' => 7,
                        'user_id' => 10,
                        'plan_id' => 3,
                        'status' => 'paused',
                        'expires_at' => '2026-03-10 23:59:59',
                        'meta' => ['billing_anchor_day' => 10, 'pause_reason' => 'Anchor billing date overdue'],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        // Late payment after anchor day 10 (current_time is March 13)
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-13 00:00:00']);

        // Should resume the paused grant
        self::assertSame([7], $grantService->resumed);

        // Should snap to next anchor (April 10), NOT April 13
        self::assertCount(1, $grantService->extended);
        self::assertSame('2026-04-10 23:59:59', $grantService->extended[0]['expires_at']);
    }

    public function test_non_anchor_grants_still_use_next_billing_date(): void
    {
        $grantService = new class() extends AccessGrantService {
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
                    ['user_id' => 10, 'plan_id' => 3, 'status' => 'active', 'meta' => []],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-25 00:00:00']);

        // Non-anchor: should use next_billing_date directly
        self::assertSame('2026-04-25 00:00:00', $grantService->extended[0]['expires_at']);
    }

    public function test_mixed_anchor_and_non_anchor_grants_handled_separately(): void
    {
        $grantService = new class() extends AccessGrantService {
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
                    // Anchor grant (day 20): should snap to anchor
                    [
                        'id' => 1, 'user_id' => 10, 'plan_id' => 3,
                        'status' => 'active', 'expires_at' => '2026-03-20 23:59:59',
                        'meta' => ['billing_anchor_day' => 20],
                    ],
                    // Non-anchor grant: should use next_billing_date
                    [
                        'id' => 2, 'user_id' => 11, 'plan_id' => 4,
                        'status' => 'active', 'expires_at' => '2026-04-01 00:00:00',
                        'meta' => [],
                    ],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-15 00:00:00']);

        self::assertCount(2, $grantService->extended);
        // Anchor: nextAnchorAfter(20, March 20) = April 20
        self::assertSame('2026-04-20 23:59:59', $grantService->extended[0]['expires_at']);
        // Non-anchor: mirrors subscription next_billing_date
        self::assertSame('2026-04-15 00:00:00', $grantService->extended[1]['expires_at']);
    }

    public function test_anchor_grant_with_null_meta_treated_as_non_anchor(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $extended = [];

            public function __construct()
            {
            }

            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['expires_at' => $newExpiresAt];
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
                    // meta has keys but no billing_anchor_day
                    ['user_id' => 10, 'plan_id' => 3, 'status' => 'active',
                     'meta' => ['some_other_key' => 'value']],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-15 00:00:00']);

        // Should use next_billing_date, not anchor logic
        self::assertSame('2026-04-15 00:00:00', $grantService->extended[0]['expires_at']);
    }

    public function test_paused_non_anchor_grant_not_resumed_by_renew(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $resumed = [];
            public array $extended = [];

            public function __construct()
            {
            }

            public function resumeGrant(int $grantId): array
            {
                $this->resumed[] = $grantId;
                return ['success' => true];
            }

            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['expires_at' => $newExpiresAt];
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
                    // Paused non-anchor grant — should NOT be resumed by renew
                    ['id' => 5, 'user_id' => 10, 'plan_id' => 3,
                     'status' => 'paused', 'meta' => []],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        $service->renew((object) ['id' => 55, 'next_billing_date' => '2026-04-15 00:00:00']);

        // Non-anchor paused grant: renew() only extends active grants
        self::assertEmpty($grantService->resumed);
        self::assertEmpty($grantService->extended);
    }

    public function test_renew_with_no_next_billing_skips_non_anchor(): void
    {
        $grantService = new class() extends AccessGrantService {
            public array $extended = [];

            public function __construct()
            {
            }

            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = ['expires_at' => $newExpiresAt];
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
                    ['user_id' => 10, 'plan_id' => 3, 'status' => 'active', 'meta' => []],
                ];
            }
        };

        $service = new SubscriptionGrantLifecycleService($grantService, $grantRepo);
        // No next_billing_date on subscription
        $service->renew((object) ['id' => 55, 'next_billing_date' => null]);

        // Non-anchor with null billing date: nothing to extend
        self::assertEmpty($grantService->extended);
    }
}
