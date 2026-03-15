<?php

declare(strict_types=1);

namespace FluentCart\App\Models;

if (!class_exists(Subscription::class, false)) {
    class Subscription
    {
        public static function find(int $id): ?object
        {
            return (object) ['id' => $id, 'status' => 'active'];
        }
    }
}

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\TrialLifecycleService;
use FChubMemberships\Domain\Trial\TrialGrantQueryService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class TrialLifecycleServiceTest extends PluginTestCase
{
    private function inject(TrialLifecycleService $service, GrantRepository $grants, PlanRepository $plans, TrialGrantQueryService $queries): void
    {
        foreach ([
            'grantRepo' => $grants,
            'planRepo' => $plans,
            'queries' => $queries,
        ] as $property => $value) {
            $reflection = new \ReflectionProperty(TrialLifecycleService::class, $property);
            $reflection->setValue($service, $value);
        }
    }

    public function test_send_trial_expiring_notifications_marks_notified_and_skips_duplicates(): void
    {
        $marked = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => str_contains($query, 'trial_ends_at >')
            ? [
                ['id' => 1, 'user_id' => 21, 'plan_id' => 5, 'trial_ends_at' => '2026-03-16 00:00:00', 'meta' => '{}'],
                ['id' => 2, 'user_id' => 21, 'plan_id' => 5, 'trial_ends_at' => '2026-03-16 00:00:00', 'meta' => '{"trial_expiry_notified":"2026-03-10 00:00:00"}'],
            ]
            : [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?object => str_contains($query, 'SELECT title, slug')
            ? (object) ['title' => 'Gold Plan', 'slug' => 'gold-plan']
            : null;
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$marked): int {
            $marked[] = [$where['id'], json_decode($data['meta'], true)];
            return 1;
        };

        $queries = new TrialGrantQueryService();

        $service = new TrialLifecycleService();
        $this->inject($service, new class extends GrantRepository {}, new class extends PlanRepository {}, $queries);
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['trial_expiry_notice_days' => 3];
        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Alice Example';
        $user->user_email = 'alice@example.com';
        $user->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $user;
        $GLOBALS['_fchub_test_options']['admin_email'] = 'admin@example.com';

        $service->sendTrialExpiringNotifications();

        self::assertCount(1, $marked);
        self::assertSame(1, $marked[0][0]);
        self::assertCount(1, $GLOBALS['_fchub_test_mails']);
        self::assertStringContainsString('Gold Plan', $GLOBALS['_fchub_test_mails'][0][1] . $GLOBALS['_fchub_test_mails'][0][2]);
    }

    public function test_check_trial_expirations_converts_paid_trials_and_expires_unpaid_trials(): void
    {
        $updates = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => str_contains($query, 'trial_ends_at <=')
            ? [
                ['id' => 10, 'user_id' => 21, 'plan_id' => 5, 'trial_ends_at' => '2026-03-13 00:00:00', 'source_id' => 0, 'source_ids' => '[77]', 'meta' => '{}'],
                ['id' => 11, 'user_id' => 21, 'plan_id' => 6, 'trial_ends_at' => '2026-03-13 00:00:00', 'source_id' => 0, 'source_ids' => '[]', 'meta' => '{}'],
            ]
            : [];

        $queries = new TrialGrantQueryService();

        $grants = new class($updates) extends GrantRepository {
            public function __construct(private array &$updates)
            {
            }

            public function update(int $id, array $data): bool
            {
                $this->updates[] = [$id, $data];
                return true;
            }
        };

        $plans = new class extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return match ($id) {
                    5 => [
                        'id' => 5,
                        'title' => 'Gold Plan',
                        'duration_type' => 'fixed_anchor',
                        'duration_days' => null,
                        'meta' => [
                            'billing_anchor_day' => 15,
                            'membership_term' => ['mode' => 'date', 'date' => '2026-12-31'],
                        ],
                    ],
                    6 => [
                        'id' => 6,
                        'title' => 'Silver Plan',
                        'duration_type' => 'lifetime',
                        'duration_days' => null,
                        'meta' => [],
                    ],
                    default => null,
                };
            }
        };

        $service = new TrialLifecycleService();
        $this->inject($service, $grants, $plans, $queries);

        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Alice Example';
        $user->user_email = 'alice@example.com';
        $user->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $user;
        $GLOBALS['_fchub_test_options']['admin_email'] = 'admin@example.com';

        $service->checkTrialExpirations();

        self::assertSame(10, $updates[0][0]);
        self::assertSame('subscription', $updates[0][1]['source_type']);
        self::assertArrayHasKey('membership_term_ends_at', $updates[0][1]['meta']);
        self::assertSame(11, $updates[1][0]);
        self::assertSame('expired', $updates[1][1]['status']);
    }
}
