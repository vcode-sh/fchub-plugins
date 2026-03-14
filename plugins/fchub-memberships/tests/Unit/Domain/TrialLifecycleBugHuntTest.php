<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\TrialLifecycleService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/controller-stubs.php';

final class TrialLifecycleBugHuntTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disable trial converted email so it doesn't call get_userdata/wp_date/etc.
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_trial_converted' => 'no',
        ];
    }

    /**
     * Create a testable TrialLifecycleService with injectable dependencies.
     */
    private function createService(GrantRepository $grantRepo, PlanRepository $planRepo): TrialLifecycleService
    {
        $service = new TrialLifecycleService();

        $ref = new \ReflectionClass($service);

        $grantProp = $ref->getProperty('grantRepo');
        $grantProp->setAccessible(true);
        $grantProp->setValue($service, $grantRepo);

        $planProp = $ref->getProperty('planRepo');
        $planProp->setAccessible(true);
        $planProp->setValue($service, $planRepo);

        return $service;
    }

    /**
     * Call private convertTrial method via reflection.
     */
    private function callConvertTrial(TrialLifecycleService $service, array $grant): void
    {
        $ref = new \ReflectionMethod($service, 'convertTrial');
        $ref->setAccessible(true);
        $ref->invoke($service, $grant);
    }

    // --- BUG D: convertTrial() applies membership term cap ---

    public function test_convert_trial_applies_membership_term_for_lifetime_plan(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $planRepo = new class() extends PlanRepository {
            public function __construct() {}

            public function find(int $id): ?array
            {
                return [
                    'id' => 5,
                    'title' => 'Premium Plan',
                    'duration_type' => 'lifetime',
                    'duration_days' => 0,
                    'meta' => [
                        'membership_term' => [
                            'mode' => '1y',
                        ],
                    ],
                ];
            }
        };

        $service = $this->createService($grantRepo, $planRepo);
        $grant = [
            'id' => 1,
            'user_id' => 10,
            'plan_id' => 5,
            'status' => 'active',
            'source_ids' => [99],
            'meta' => [],
        ];

        $this->callConvertTrial($service, $grant);

        self::assertNotEmpty($updates);
        $update = $updates[0]['data'];

        // Should have expires_at set from term (not null as lifetime would normally give)
        self::assertNotNull($update['expires_at'], 'Lifetime plan with term should have an expiry date');

        // Verify term end date is stored in meta
        self::assertArrayHasKey('meta', $update);
        self::assertArrayHasKey('membership_term_ends_at', $update['meta']);

        // The term end date should be ~1 year from the stubbed current_time (2026-03-13)
        $termEndsAt = $update['meta']['membership_term_ends_at'];
        self::assertStringStartsWith('2027-03-13', $termEndsAt);
    }

    public function test_convert_trial_caps_fixed_days_at_term_end(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $planRepo = new class() extends PlanRepository {
            public function __construct() {}

            public function find(int $id): ?array
            {
                return [
                    'id' => 5,
                    'title' => 'Premium Plan',
                    'duration_type' => 'fixed_days',
                    'duration_days' => 365,
                    'meta' => [
                        'membership_term' => [
                            'mode' => 'custom',
                            'value' => 6,
                            'unit' => 'months',
                        ],
                    ],
                ];
            }
        };

        $service = $this->createService($grantRepo, $planRepo);
        $grant = [
            'id' => 1,
            'user_id' => 10,
            'plan_id' => 5,
            'status' => 'active',
            'source_ids' => [99],
            'meta' => [],
        ];

        $this->callConvertTrial($service, $grant);

        self::assertNotEmpty($updates);
        $update = $updates[0]['data'];

        // Duration is 365 days (~2027-03-13) but term is 6 months (~2026-09-13).
        // Expiry should be capped at term end (2026-09-13).
        $expiresAt = strtotime($update['expires_at']);
        $termEndsAt = strtotime($update['meta']['membership_term_ends_at']);

        self::assertLessThanOrEqual($termEndsAt, $expiresAt, 'Expiry should be capped at term end date');
        // Term ends at is ~6 months from 2026-03-13 → 2026-09-13
        self::assertStringStartsWith('2026-09-13', $update['meta']['membership_term_ends_at']);
    }

    public function test_convert_trial_no_term_lifetime_stays_null(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $planRepo = new class() extends PlanRepository {
            public function __construct() {}

            public function find(int $id): ?array
            {
                return [
                    'id' => 5,
                    'title' => 'Lifetime Plan',
                    'duration_type' => 'lifetime',
                    'duration_days' => 0,
                    'meta' => [],
                ];
            }
        };

        $service = $this->createService($grantRepo, $planRepo);
        $grant = [
            'id' => 1,
            'user_id' => 10,
            'plan_id' => 5,
            'status' => 'active',
            'source_ids' => [99],
            'meta' => [],
        ];

        $this->callConvertTrial($service, $grant);

        self::assertNotEmpty($updates);
        $update = $updates[0]['data'];

        // Lifetime with no term should remain null
        self::assertNull($update['expires_at']);
    }

    // --- BUG E: convertTrial() handles fixed_anchor duration ---

    public function test_convert_trial_handles_fixed_anchor_duration(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $planRepo = new class() extends PlanRepository {
            public function __construct() {}

            public function find(int $id): ?array
            {
                return [
                    'id' => 5,
                    'title' => 'Anchor Plan',
                    'duration_type' => 'fixed_anchor',
                    'duration_days' => 0,
                    'meta' => [
                        'billing_anchor_day' => 20,
                    ],
                ];
            }
        };

        $service = $this->createService($grantRepo, $planRepo);
        $grant = [
            'id' => 1,
            'user_id' => 10,
            'plan_id' => 5,
            'status' => 'active',
            'source_ids' => [99],
            'meta' => [],
        ];

        $this->callConvertTrial($service, $grant);

        self::assertNotEmpty($updates);
        $update = $updates[0]['data'];

        // current_time stub returns '2026-03-13 22:00:00'
        // anchor day 20 hasn't passed → next anchor is March 20
        self::assertSame('2026-03-20 23:59:59', $update['expires_at']);

        // billing_anchor_day should be stored in grant meta
        self::assertArrayHasKey('meta', $update);
        self::assertSame(20, $update['meta']['billing_anchor_day']);
    }

    public function test_convert_trial_anchor_after_anchor_day_advances_to_next_month(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $planRepo = new class() extends PlanRepository {
            public function __construct() {}

            public function find(int $id): ?array
            {
                return [
                    'id' => 5,
                    'title' => 'Anchor Plan',
                    'duration_type' => 'fixed_anchor',
                    'duration_days' => 0,
                    'meta' => [
                        'billing_anchor_day' => 10,
                    ],
                ];
            }
        };

        $service = $this->createService($grantRepo, $planRepo);
        $grant = [
            'id' => 1,
            'user_id' => 10,
            'plan_id' => 5,
            'status' => 'active',
            'source_ids' => [99],
            'meta' => [],
        ];

        $this->callConvertTrial($service, $grant);

        self::assertNotEmpty($updates);
        $update = $updates[0]['data'];

        // current_time stub is '2026-03-13 22:00:00', anchor day 10 already passed
        // → next anchor is April 10
        self::assertSame('2026-04-10 23:59:59', $update['expires_at']);
        self::assertSame(10, $update['meta']['billing_anchor_day']);
    }

    public function test_convert_trial_anchor_with_term_cap(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $planRepo = new class() extends PlanRepository {
            public function __construct() {}

            public function find(int $id): ?array
            {
                return [
                    'id' => 5,
                    'title' => 'Anchor Plan',
                    'duration_type' => 'fixed_anchor',
                    'duration_days' => 0,
                    'meta' => [
                        'billing_anchor_day' => 20,
                        'membership_term' => [
                            'mode' => 'date',
                            'date' => '2026-03-18',
                        ],
                    ],
                ];
            }
        };

        $service = $this->createService($grantRepo, $planRepo);
        $grant = [
            'id' => 1,
            'user_id' => 10,
            'plan_id' => 5,
            'status' => 'active',
            'source_ids' => [99],
            'meta' => [],
        ];

        $this->callConvertTrial($service, $grant);

        self::assertNotEmpty($updates);
        $update = $updates[0]['data'];

        // Anchor would be March 20, but term ends March 18 → capped at March 18
        self::assertSame('2026-03-18 23:59:59', $update['expires_at']);
        self::assertSame(20, $update['meta']['billing_anchor_day']);
        self::assertSame('2026-03-18 23:59:59', $update['meta']['membership_term_ends_at']);
    }

    public function test_convert_trial_fixed_days_unchanged(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            private array $capturedUpdates;

            public function __construct(array &$updates)
            {
                $this->capturedUpdates = &$updates;
            }

            public function update(int $id, array $data): bool
            {
                $this->capturedUpdates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };

        $planRepo = new class() extends PlanRepository {
            public function __construct() {}

            public function find(int $id): ?array
            {
                return [
                    'id' => 5,
                    'title' => '30 Day Plan',
                    'duration_type' => 'fixed_days',
                    'duration_days' => 30,
                    'meta' => [],
                ];
            }
        };

        $service = $this->createService($grantRepo, $planRepo);
        $grant = [
            'id' => 1,
            'user_id' => 10,
            'plan_id' => 5,
            'status' => 'active',
            'source_ids' => [99],
            'meta' => [],
        ];

        $this->callConvertTrial($service, $grant);

        self::assertNotEmpty($updates);
        $update = $updates[0]['data'];

        // 30 days from now, no term cap
        $expected = date('Y-m-d H:i:s', strtotime('+30 days'));
        self::assertSame($expected, $update['expires_at']);
        self::assertSame('subscription', $update['source_type']);
        self::assertNull($update['trial_ends_at']);
    }
}
