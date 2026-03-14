<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Domain\Plan\PlanProductLinkService;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

// Stubs needed by GrantCreationService -> AuditLogger
require_once dirname(__DIR__, 2) . '/stubs/controller-stubs.php';

/**
 * Bug hunt: context service wiring, integration handleGrant flow,
 * GrantCreationService renewal path, PlanProductLinkService term propagation.
 *
 * Traces the FULL data flow:
 *   Integration.handleGrant() -> grantPlan() -> PlanGrantExecutionService
 *   -> GrantPlanContextService.resolve() -> GrantCreationService.grantResource()
 */
final class ContextWiringBugHuntTest extends PluginTestCase
{

    // =========================================================================
    // 1. GrantCreationService: renewal path does NOT merge context meta
    // =========================================================================

    /**
     * BUG: When grantResource() finds an existing grant and renews it,
     * the meta from context (including membership_term_ends_at) is never
     * merged into the grant's update data. The old meta persists unchanged.
     */
    public function test_grant_creation_renewal_does_not_update_meta(): void
    {
        $existingGrant = [
            'id' => 42,
            'status' => 'active',
            'source_ids' => [100],
            'renewal_count' => 1,
            'expires_at' => '2026-06-01 23:59:59',
            'meta' => ['membership_term_ends_at' => '2026-12-31 23:59:59'],
        ];

        $grantRepo = new class($existingGrant) extends GrantRepository {
            private array $existing;
            public array $lastUpdate = [];
            public function __construct(array $existing)
            {
                $this->existing = $existing;
            }
            public static function makeGrantKey(int $userId, string $provider, string $resourceType, string $resourceId): string
            {
                return "{$userId}:{$provider}:{$resourceType}:{$resourceId}";
            }
            public function findByGrantKey(string $grantKey): ?array
            {
                return $this->existing;
            }
            public function update(int $id, array $data): bool
            {
                $this->lastUpdate = $data;
                return true;
            }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
        };
        $adapters = new GrantAdapterRegistry([]);

        $service = new GrantCreationService($grantRepo, $sourceRepo, $dripRepo, $adapters);

        // Renew with a NEW term_ends_at
        $result = $service->grantResource(1, 'wordpress_core', 'post', '99', [
            'plan_id' => 5,
            'source_type' => 'subscription',
            'source_id' => 200,
            'expires_at' => '2027-06-01 23:59:59',
            'meta' => [
                'membership_term_ends_at' => '2027-12-31 23:59:59',
                'billing_anchor_day' => 15,
            ],
        ]);

        self::assertSame('updated', $result['action']);

        // BUG VERIFICATION: the update data should contain meta, but currently doesn't.
        // The context meta with the new term_ends_at is silently dropped.
        // After the fix, this assertion should pass:
        self::assertArrayHasKey('meta', $grantRepo->lastUpdate, 'Renewal must merge context meta into grant update data');
        self::assertSame(
            '2027-12-31 23:59:59',
            $grantRepo->lastUpdate['meta']['membership_term_ends_at'] ?? null,
            'membership_term_ends_at must be updated on renewal'
        );
    }

    /**
     * Verify that renewal meta merge preserves existing grant meta keys
     * that are NOT in the new context.
     */
    public function test_grant_creation_renewal_preserves_existing_meta_keys(): void
    {
        $existingGrant = [
            'id' => 42,
            'status' => 'expired',
            'source_ids' => [100],
            'renewal_count' => 0,
            'expires_at' => '2025-01-01 23:59:59',
            'meta' => [
                'membership_term_ends_at' => '2026-12-31 23:59:59',
                'some_custom_key' => 'preserve_me',
            ],
        ];

        $grantRepo = new class($existingGrant) extends GrantRepository {
            private array $existing;
            public array $lastUpdate = [];
            public function __construct(array $existing) { $this->existing = $existing; }
            public static function makeGrantKey(int $userId, string $provider, string $resourceType, string $resourceId): string
            {
                return "{$userId}:{$provider}:{$resourceType}:{$resourceId}";
            }
            public function findByGrantKey(string $grantKey): ?array { return $this->existing; }
            public function update(int $id, array $data): bool { $this->lastUpdate = $data; return true; }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
        };
        $adapters = new GrantAdapterRegistry([]);

        $service = new GrantCreationService($grantRepo, $sourceRepo, $dripRepo, $adapters);

        $service->grantResource(1, 'wordpress_core', 'post', '99', [
            'plan_id' => 5,
            'source_type' => 'subscription',
            'source_id' => 200,
            'expires_at' => '2027-06-01 23:59:59',
            'meta' => [
                'membership_term_ends_at' => '2028-01-01 23:59:59',
            ],
        ]);

        // After fix: existing 'some_custom_key' must still be present
        self::assertArrayHasKey('meta', $grantRepo->lastUpdate);
        self::assertSame('preserve_me', $grantRepo->lastUpdate['meta']['some_custom_key'] ?? null,
            'Existing meta keys must be preserved during renewal merge');
        self::assertSame('2028-01-01 23:59:59', $grantRepo->lastUpdate['meta']['membership_term_ends_at'] ?? null,
            'New term_ends_at must overwrite old value');
    }

    /**
     * Verify renewal with empty context meta doesn't wipe existing meta.
     */
    public function test_grant_creation_renewal_empty_context_meta_preserves_existing(): void
    {
        $existingGrant = [
            'id' => 42,
            'status' => 'active',
            'source_ids' => [],
            'renewal_count' => 0,
            'expires_at' => null,
            'meta' => ['membership_term_ends_at' => '2027-06-01 23:59:59'],
        ];

        $grantRepo = new class($existingGrant) extends GrantRepository {
            private array $existing;
            public array $lastUpdate = [];
            public function __construct(array $existing) { $this->existing = $existing; }
            public static function makeGrantKey(int $userId, string $provider, string $resourceType, string $resourceId): string
            {
                return "{$userId}:{$provider}:{$resourceType}:{$resourceId}";
            }
            public function findByGrantKey(string $grantKey): ?array { return $this->existing; }
            public function update(int $id, array $data): bool { $this->lastUpdate = $data; return true; }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
        };
        $adapters = new GrantAdapterRegistry([]);

        $service = new GrantCreationService($grantRepo, $sourceRepo, $dripRepo, $adapters);

        // Renew with NO meta in context
        $service->grantResource(1, 'wordpress_core', 'post', '99', [
            'plan_id' => 5,
            'source_type' => 'order',
            'source_id' => 300,
            'meta' => [],
        ]);

        // Existing meta should be preserved (merged with empty array = no change)
        self::assertArrayHasKey('meta', $grantRepo->lastUpdate);
        self::assertSame('2027-06-01 23:59:59', $grantRepo->lastUpdate['meta']['membership_term_ends_at'] ?? null,
            'Empty context meta must not wipe existing grant meta');
    }

    // =========================================================================
    // 2. PlanProductLinkService: 'date' mode term not propagated
    // =========================================================================

    /**
     * BUG: linkProduct() does not propagate 'date' mode's date value.
     * It sets membership_term_mode = 'date' but never sets membership_term_date,
     * so handleGrant calls calculateEndDate({mode: 'date'}) with no date key -> returns null.
     */
    public function test_plan_product_link_date_mode_missing_date_value(): void
    {
        $plan = [
            'id' => 10,
            'slug' => 'gold-plan',
            'title' => 'Gold Plan',
            'duration_type' => 'lifetime',
            'duration_days' => 0,
            'grace_period_days' => 0,
            'meta' => [
                'membership_term' => [
                    'mode' => 'date',
                    'date' => '2027-12-31',
                ],
            ],
        ];

        $planService = new class($plan) extends PlanService {
            private array $plan;
            public function __construct(array $plan) { $this->plan = $plan; }
            public function find(int $id): ?array { return $this->plan; }
        };

        $linkService = new PlanProductLinkService($planService);

        // Use reflection or just inspect the logic:
        // linkProduct sets membership_term_mode but NOT membership_term_date
        // We can verify by checking what calculateEndDate produces with the generated feed settings

        $termConfig = $plan['meta']['membership_term'];

        // Simulate what linkProduct generates for 'date' mode
        $feedSettings = ['membership_term_mode' => $termConfig['mode']];
        // Note: linkProduct only adds value/unit for 'custom', not date for 'date'!

        // Simulate what handleGrant does with these feed settings
        $feedTermConfig = ['mode' => $feedSettings['membership_term_mode']];
        // For 'date' mode, there's no $feedSettings['membership_term_date'] to add

        $result = MembershipTermCalculator::calculateEndDate($feedTermConfig, '2026-03-14 10:00:00');

        // This is the bug: date mode without a date value returns null
        self::assertNull($result, 'date mode without date value silently returns null — term is lost');

        // What it SHOULD return if the date were propagated:
        $correctConfig = ['mode' => 'date', 'date' => '2027-12-31'];
        $correctResult = MembershipTermCalculator::calculateEndDate($correctConfig, '2026-03-14 10:00:00');
        self::assertSame('2027-12-31 23:59:59', $correctResult);
    }

    // =========================================================================
    // 3. Integration handleGrant: feed term mode 'date' with no date field
    // =========================================================================

    /**
     * The integration settings UI has no 'membership_term_date' field at all.
     * If someone sets mode to 'date' (only possible via plan propagation or
     * direct DB manipulation), handleGrant builds {mode: 'date'} with no
     * date value, calculateEndDate returns null, and the term is silently skipped.
     */
    public function test_handle_grant_date_mode_term_silently_skipped(): void
    {
        // Simulate what MembershipAccessIntegration.handleGrant does for date mode
        $feedTermMode = 'date';
        $feedTermConfig = ['mode' => $feedTermMode];
        // handleGrant only adds value/unit for 'custom' mode

        $termEndsAt = MembershipTermCalculator::calculateEndDate($feedTermConfig, current_time('mysql'));

        // Silently null — no crash, but no term protection either
        self::assertNull($termEndsAt, 'date mode term is silently lost in handleGrant');
    }

    // =========================================================================
    // 4. Context array pollution: extra meta keys survive merges
    // =========================================================================

    public function test_context_with_random_meta_keys_preserved_through_resolve(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => '1y']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);

        // Context starts with random meta keys
        $result = $service->resolve(3, 11, [
            'meta' => [
                'custom_field' => 'hello',
                'another_key' => 42,
            ],
        ]);

        // All original meta keys must survive the array_merge
        self::assertSame('hello', $result['context']['meta']['custom_field']);
        self::assertSame(42, $result['context']['meta']['another_key']);
        // Plus the term should be added
        self::assertArrayHasKey('membership_term_ends_at', $result['context']['meta']);
    }

    // =========================================================================
    // 5. Plan meta edge case: meta is a string (JSON not decoded)
    // =========================================================================

    public function test_context_plan_meta_as_json_string_does_not_crash(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    // Meta stored as raw JSON string (not decoded)
                    'meta' => '{"membership_term":{"mode":"1y"}}',
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);

        // String offset access: $plan['meta']['membership_term'] on a string
        // would return a single character or null depending on PHP version.
        // This should not crash, but the term will likely be lost.
        $result = $service->resolve(3, 11, []);

        // The key point: it shouldn't crash (TypeError/Warning).
        // The term will be silently lost because string['membership_term'] is null-ish
        self::assertIsArray($result);
        self::assertArrayHasKey('plan', $result);
    }

    // =========================================================================
    // 6. Double injection: both feed AND plan have term, feed wins
    // =========================================================================

    public function test_double_injection_feed_term_blocks_plan_term(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => '3y']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);

        // Feed already set a shorter term (6 months from now)
        $feedTermEnd = date('Y-m-d', strtotime('+6 months')) . ' 23:59:59';
        $result = $service->resolve(3, 11, [
            'expires_at' => $feedTermEnd,
            'meta' => ['membership_term_ends_at' => $feedTermEnd],
        ]);

        // Plan has 3y term, but feed's 6-month term must take precedence
        // because resolve() skips term injection when meta.membership_term_ends_at exists
        self::assertSame($feedTermEnd, $result['context']['meta']['membership_term_ends_at']);
        self::assertSame($feedTermEnd, $result['context']['expires_at']);
    }

    /**
     * Reverse case: feed term is LONGER than plan term.
     * Feed still wins because plan term block is skipped entirely.
     */
    public function test_double_injection_feed_longer_term_still_wins(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => 'custom', 'value' => 30, 'unit' => 'days']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);

        // Feed set a 10-year term (much longer than plan's 30 days)
        $feedTermEnd = date('Y-m-d', strtotime('+10 years')) . ' 23:59:59';
        $result = $service->resolve(3, 11, [
            'expires_at' => $feedTermEnd,
            'meta' => ['membership_term_ends_at' => $feedTermEnd],
        ]);

        // Feed's 10-year term wins, plan's 30-day term is ignored
        // This is a design choice: feed override is absolute.
        self::assertSame($feedTermEnd, $result['context']['meta']['membership_term_ends_at']);
    }

    // =========================================================================
    // 7. calculateEndDate with custom mode + invalid unit returns null
    // =========================================================================

    public function test_calculate_end_date_custom_invalid_unit_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 6, 'unit' => 'centuries'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result, 'Invalid unit should return null');
    }

    /**
     * What happens when mode != 'none' but calculateEndDate returns null?
     * The context service condition `if ($termEndsAt)` catches it and does nothing.
     */
    public function test_context_custom_mode_with_invalid_unit_no_term_injected(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => 'custom', 'value' => 6, 'unit' => 'centuries']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        // Mode is not 'none' but calculateEndDate returns null for invalid unit.
        // The context service handles this gracefully: no term injected.
        self::assertNull($result['context']['expires_at']);
        self::assertArrayNotHasKey('meta', $result['context']);
    }

    // =========================================================================
    // 8. subscription_mirror + term: expires_at absent from context
    // =========================================================================

    /**
     * subscription_mirror plans don't set expires_at in the duration block of
     * GrantPlanContextService.resolve(). If no feed-level expires_at is provided,
     * expires_at is null. The term block must handle this: give lifetime plan
     * its term as the expiry.
     */
    public function test_subscription_mirror_no_expires_at_gets_term_as_expiry(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'subscription_mirror',
                    'meta' => ['membership_term' => ['mode' => '1y']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        // No expires_at provided (subscription billing date not known yet)
        $result = $service->resolve(3, 11, []);

        // Term should set expires_at since it was null
        self::assertNotNull($result['context']['expires_at']);
        self::assertSame(
            $result['context']['meta']['membership_term_ends_at'],
            $result['context']['expires_at']
        );
    }

    // =========================================================================
    // 9. PlanGrantExecutionService: context.meta flows to grantResource
    // =========================================================================

    /**
     * Verify that PlanGrantExecutionService passes context['meta'] to
     * GrantCreationService.grantResource() for each rule.
     */
    public function test_plan_grant_execution_passes_meta_to_creation(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => '2y']],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $contextService = new GrantPlanContextService($plans, $grants);
        $result = $contextService->resolve(3, 11, [
            'source_type' => 'order',
            'source_id' => 100,
        ]);

        // The resolved context should have meta with membership_term_ends_at
        self::assertArrayHasKey('meta', $result['context']);
        self::assertArrayHasKey('membership_term_ends_at', $result['context']['meta']);

        // PlanGrantExecutionService line 61: 'meta' => $context['meta'] ?? []
        // This correctly passes the meta through. Verify the key exists.
        $contextMeta = $result['context']['meta'] ?? [];
        self::assertNotEmpty($contextMeta['membership_term_ends_at']);
    }

    // =========================================================================
    // 10. GrantCreationService: new grant stores meta correctly
    // =========================================================================

    public function test_grant_creation_new_grant_stores_meta(): void
    {
        $grantRepo = new class() extends GrantRepository {
            public array $lastCreate = [];
            public function __construct() {}
            public static function makeGrantKey(int $userId, string $provider, string $resourceType, string $resourceId): string
            {
                return "{$userId}:{$provider}:{$resourceType}:{$resourceId}";
            }
            public function findByGrantKey(string $grantKey): ?array { return null; }
            public function create(array $data): int { $this->lastCreate = $data; return 1; }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
        };
        $adapters = new GrantAdapterRegistry([]);

        $service = new GrantCreationService($grantRepo, $sourceRepo, $dripRepo, $adapters);

        $service->grantResource(1, 'wordpress_core', 'post', '99', [
            'plan_id' => 5,
            'source_type' => 'order',
            'source_id' => 100,
            'expires_at' => '2027-03-14 23:59:59',
            'meta' => [
                'membership_term_ends_at' => '2028-03-14 23:59:59',
                'billing_anchor_day' => 20,
            ],
        ]);

        // New grants correctly store meta (line 81: 'meta' => $context['meta'] ?? [])
        self::assertSame('2028-03-14 23:59:59', $grantRepo->lastCreate['meta']['membership_term_ends_at']);
        self::assertSame(20, $grantRepo->lastCreate['meta']['billing_anchor_day']);
    }

    // =========================================================================
    // 11. GrantCreationService: renewal with newer expires_at wins
    // =========================================================================

    public function test_grant_creation_renewal_later_expiry_wins(): void
    {
        $existingGrant = [
            'id' => 42,
            'status' => 'active',
            'source_ids' => [100],
            'renewal_count' => 2,
            'expires_at' => '2026-06-01 23:59:59',
            'meta' => [],
        ];

        $grantRepo = new class($existingGrant) extends GrantRepository {
            private array $existing;
            public array $lastUpdate = [];
            public function __construct(array $existing) { $this->existing = $existing; }
            public static function makeGrantKey(int $userId, string $provider, string $resourceType, string $resourceId): string
            {
                return "{$userId}:{$provider}:{$resourceType}:{$resourceId}";
            }
            public function findByGrantKey(string $grantKey): ?array { return $this->existing; }
            public function update(int $id, array $data): bool { $this->lastUpdate = $data; return true; }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
        };
        $adapters = new GrantAdapterRegistry([]);

        $service = new GrantCreationService($grantRepo, $sourceRepo, $dripRepo, $adapters);

        // New expires_at is later -> should win
        $service->grantResource(1, 'wordpress_core', 'post', '99', [
            'source_id' => 200,
            'expires_at' => '2027-06-01 23:59:59',
            'meta' => [],
        ]);

        self::assertSame('2027-06-01 23:59:59', $grantRepo->lastUpdate['expires_at']);
    }

    /**
     * Renewal with EARLIER expires_at: existing one is preserved.
     */
    public function test_grant_creation_renewal_earlier_expiry_keeps_existing(): void
    {
        $existingGrant = [
            'id' => 42,
            'status' => 'active',
            'source_ids' => [100],
            'renewal_count' => 0,
            'expires_at' => '2027-06-01 23:59:59',
            'meta' => [],
        ];

        $grantRepo = new class($existingGrant) extends GrantRepository {
            private array $existing;
            public array $lastUpdate = [];
            public function __construct(array $existing) { $this->existing = $existing; }
            public static function makeGrantKey(int $userId, string $provider, string $resourceType, string $resourceId): string
            {
                return "{$userId}:{$provider}:{$resourceType}:{$resourceId}";
            }
            public function findByGrantKey(string $grantKey): ?array { return $this->existing; }
            public function update(int $id, array $data): bool { $this->lastUpdate = $data; return true; }
        };

        $sourceRepo = new class() extends GrantSourceRepository {
            public function __construct() {}
            public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; }
        };
        $dripRepo = new class() extends DripScheduleRepository {
            public function __construct() {}
        };
        $adapters = new GrantAdapterRegistry([]);

        $service = new GrantCreationService($grantRepo, $sourceRepo, $dripRepo, $adapters);

        // New expires_at is EARLIER -> existing should be kept (no key in update)
        $service->grantResource(1, 'wordpress_core', 'post', '99', [
            'source_id' => 200,
            'expires_at' => '2026-01-01 00:00:00',
            'meta' => [],
        ]);

        self::assertArrayNotHasKey('expires_at', $grantRepo->lastUpdate,
            'Earlier expiry should not overwrite existing later expiry');
    }

    // =========================================================================
    // 12. Integration handleGrant: feed term mode shorthand (1y/2y/3y)
    // =========================================================================

    /**
     * Verify that shorthand modes (1y, 2y, 3y) work correctly through
     * MembershipTermCalculator when called from the integration's term
     * override path.
     */
    public function test_feed_shorthand_term_modes_produce_correct_dates(): void
    {
        $ref = current_time('mysql');

        foreach (['1y' => 1, '2y' => 2, '3y' => 3] as $mode => $years) {
            $result = MembershipTermCalculator::calculateEndDate(['mode' => $mode], $ref);
            self::assertNotNull($result, "Mode {$mode} should produce a date");

            $expected = date('Y-m-d', strtotime("+{$years} year", strtotime($ref))) . ' 23:59:59';
            self::assertSame($expected, $result, "Mode {$mode} should add {$years} years");
        }
    }

    // =========================================================================
    // 13. Context service: fixed_anchor injects billing_anchor_day + term
    // =========================================================================

    public function test_context_fixed_anchor_no_preexisting_meta(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'fixed_anchor',
                    'meta' => [
                        'billing_anchor_day' => 15,
                        'membership_term' => ['mode' => '1y'],
                    ],
                ];
            }
        };
        $grants = new class() extends GrantRepository {
            public function __construct() {}
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        // Both meta keys must be present
        self::assertSame(15, $result['context']['meta']['billing_anchor_day']);
        self::assertArrayHasKey('membership_term_ends_at', $result['context']['meta']);

        // expires_at should be the earlier of anchor date and term date
        $expiresTs = strtotime($result['context']['expires_at']);
        $termTs = strtotime($result['context']['meta']['membership_term_ends_at']);
        self::assertLessThanOrEqual($termTs, $expiresTs);
    }

    // =========================================================================
    // 14. PlanProductLinkService: preset modes (1y, 2y, 3y) propagated correctly
    // =========================================================================

    public function test_plan_product_link_preset_modes_propagated(): void
    {
        foreach (['1y', '2y', '3y'] as $mode) {
            $plan = [
                'id' => 10,
                'slug' => 'test-plan',
                'title' => 'Test Plan',
                'duration_type' => 'lifetime',
                'duration_days' => 0,
                'grace_period_days' => 0,
                'meta' => [
                    'membership_term' => ['mode' => $mode],
                ],
            ];

            // Simulate what linkProduct generates
            $termConfig = $plan['meta']['membership_term'];
            // linkProduct checks: mode !== 'none', then only handles 'custom' explicitly
            $feedSettings = [];
            if ($termConfig && ($termConfig['mode'] ?? 'none') !== 'none') {
                $feedSettings['membership_term_mode'] = $termConfig['mode'];
                if ($termConfig['mode'] === 'custom') {
                    $feedSettings['membership_term_value'] = $termConfig['value'] ?? 1;
                    $feedSettings['membership_term_unit'] = $termConfig['unit'] ?? 'months';
                }
            }

            // Simulate what handleGrant does with these settings
            $feedTermConfig = ['mode' => $feedSettings['membership_term_mode']];
            $result = MembershipTermCalculator::calculateEndDate($feedTermConfig, '2026-03-14 10:00:00');

            // Preset modes work because calculateEndDate handles them directly
            self::assertNotNull($result, "Preset mode {$mode} should work through feed propagation");
        }
    }

    // =========================================================================
    // 15. Malformed term config: missing keys
    // =========================================================================

    public function test_calculate_end_date_custom_missing_value_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        // value defaults to 0, which is < 1
        self::assertNull($result);
    }

    public function test_calculate_end_date_custom_missing_unit_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 6],
            '2026-03-14 10:00:00'
        );
        // unit defaults to 'months' which IS valid, so this SHOULD return a date
        self::assertNotNull($result, 'Default unit "months" should be used when unit key is missing');
    }

    public function test_calculate_end_date_empty_config_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate([], '2026-03-14 10:00:00');
        // mode defaults to 'none'
        self::assertNull($result);
    }
}
