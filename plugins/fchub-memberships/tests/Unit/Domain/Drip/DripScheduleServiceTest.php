<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Drip;

use FChubMemberships\Domain\Drip\DripScheduleService;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class DripScheduleServiceTest extends PluginTestCase
{
    private function inject(DripScheduleService $service, DripScheduleRepository $dripRepo, GrantRepository $grantRepo): void
    {
        $dripReflection = new \ReflectionProperty(DripScheduleService::class, 'dripRepo');
        $dripReflection->setValue($service, $dripRepo);

        $grantReflection = new \ReflectionProperty(DripScheduleService::class, 'grantRepo');
        $grantReflection->setValue($service, $grantRepo);
    }

    public function test_process_notifications_marks_missing_grants_sent_and_active_grants_processed(): void
    {
        $sent = [];
        $failed = [];
        $updates = [];

        $dripRepo = new class($sent, $failed) extends DripScheduleRepository {
            public function __construct(private array &$sent, private array &$failed)
            {
            }

            public function getPendingNotifications(int $limit = 50): array
            {
                return [
                    ['id' => 1, 'grant_id' => 10, 'user_id' => 21, 'retry_count' => 0, 'plan_rule_id' => 91],
                    ['id' => 2, 'grant_id' => 20, 'user_id' => 22, 'retry_count' => 0, 'plan_rule_id' => 92],
                ];
            }

            public function markSent(int $id): bool
            {
                $this->sent[] = $id;
                return true;
            }

            public function markFailed(int $id): bool
            {
                $this->failed[] = $id;
                return true;
            }

            public function getByGrantId(int $grantId): array
            {
                return [
                    ['id' => 201, 'status' => 'sent'],
                    ['id' => 202, 'status' => 'sent'],
                    ['id' => 203, 'status' => 'pending'],
                    ['id' => 204, 'status' => 'pending'],
                ];
            }
        };

        $grantRepo = new class($updates) extends GrantRepository {
            public function __construct(private array &$updates)
            {
            }

            public function find(int $id): ?array
            {
                return match ($id) {
                    10 => null,
                    20 => ['id' => 20, 'user_id' => 22, 'status' => 'active', 'meta' => [], 'plan_id' => 5],
                    default => null,
                };
            }

            public function update(int $id, array $data): bool
            {
                $this->updates[] = [$id, $data];
                return true;
            }
        };

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_drip_unlocked' => 'no',
        ];

        $service = new DripScheduleService();
        $this->inject($service, $dripRepo, $grantRepo);
        $processed = $service->processNotifications();

        self::assertSame(1, $processed);
        self::assertSame([1, 2], $sent);
        self::assertSame([], $failed);
        self::assertSame([20, ['meta' => ['drip_milestones_fired' => [25, 50]]]], $updates[0]);
    }

    public function test_process_notifications_handles_hook_failures_and_retry_logic(): void
    {
        $sent = [];
        $failed = [];

        $dripRepo = new class($sent, $failed) extends DripScheduleRepository {
            public function __construct(private array &$sent, private array &$failed)
            {
            }

            public function getPendingNotifications(int $limit = 50): array
            {
                return [['id' => 9, 'grant_id' => 90, 'user_id' => 21, 'retry_count' => 2, 'plan_rule_id' => 91]];
            }

            public function markSent(int $id): bool
            {
                $this->sent[] = $id;
                return true;
            }

            public function markFailed(int $id): bool
            {
                $this->failed[] = $id;
                return true;
            }

            public function getByGrantId(int $grantId): array
            {
                return [];
            }
        };

        $grantRepo = new class extends GrantRepository {
            public function find(int $id): ?array
            {
                return ['id' => 90, 'user_id' => 21, 'status' => 'active', 'meta' => [], 'plan_id' => 5];
            }
        };

        add_action('fchub_memberships/drip_unlocked', static function (): void {
            throw new \RuntimeException('boom');
        });

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_drip_unlocked' => 'no',
        ];

        $service = new DripScheduleService();
        $this->inject($service, $dripRepo, $grantRepo);
        $processed = $service->processNotifications();

        self::assertSame(0, $processed);
        self::assertSame([9], $sent);
        self::assertSame([9], $failed);
    }

    public function test_schedule_overview_retry_and_queue_helpers_cover_public_service_api(): void
    {
        $scheduled = [];

        $dripRepo = new class($scheduled) extends DripScheduleRepository {
            public function __construct(private array &$scheduled)
            {
            }

            public function schedule(array $data): int
            {
                $this->scheduled[] = $data;
                return 1;
            }

            public function getUpcomingUnlocks(string $from, string $to): array
            {
                return [['notify_at' => '2026-03-20 10:00:00']];
            }

            public function all(array $filters = []): array
            {
                return [['id' => 1, 'status' => 'pending']];
            }

            public function countPending(): int
            {
                return 3;
            }

            public function countSent(): int
            {
                return 7;
            }

            public function find(int $id): ?array
            {
                return ['id' => $id, 'grant_id' => 50, 'status' => 'failed', 'plan_rule_id' => 91, 'user_id' => 21];
            }

            public function markSent(int $id): bool
            {
                return true;
            }
        };

        $grantRepo = new class extends GrantRepository {
            public function find(int $id): ?array
            {
                return ['id' => $id, 'status' => 'active', 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'plan_id' => 5];
            }
        };

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_drip_unlocked' => 'no',
        ];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, 'SELECT * FROM wp_fchub_membership_plan_rules WHERE plan_id = 5 ORDER BY') => [
                    ['id' => 91, 'plan_id' => 5, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'drip_type' => 'delayed', 'drip_delay_days' => 3, 'drip_date' => null, 'sort_order' => 1, 'meta' => '{}'],
                    ['id' => 92, 'plan_id' => 5, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '56', 'drip_type' => 'fixed_date', 'drip_delay_days' => 0, 'drip_date' => '2026-04-01 00:00:00', 'sort_order' => 2, 'meta' => '{}'],
                    ['id' => 93, 'plan_id' => 5, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '57', 'drip_type' => 'immediate', 'drip_delay_days' => 0, 'drip_date' => null, 'sort_order' => 3, 'meta' => '{}'],
                ],
                str_contains($query, 'SELECT * FROM wp_fchub_membership_plans WHERE 1=1 AND status = \'active\'') => [[
                    'id' => 5,
                    'title' => 'Gold Plan',
                    'slug' => 'gold-plan',
                    'description' => '',
                    'status' => 'active',
                    'level' => 0,
                    'duration_type' => 'lifetime',
                    'duration_days' => null,
                    'trial_days' => 0,
                    'grace_period_days' => 0,
                    'includes_plan_ids' => '[]',
                    'restriction_message' => null,
                    'redirect_url' => null,
                    'settings' => '{}',
                    'meta' => '{}',
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ]],
                str_contains($query, 'SELECT * FROM wp_fchub_membership_plan_rules WHERE plan_id = 5 AND drip_type != \'immediate\'') => [
                    ['id' => 91, 'plan_id' => 5, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'drip_type' => 'delayed', 'drip_delay_days' => 3, 'drip_date' => null, 'sort_order' => 1, 'meta' => '{}'],
                    ['id' => 92, 'plan_id' => 5, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '56', 'drip_type' => 'fixed_date', 'drip_delay_days' => 0, 'drip_date' => '2026-04-01 00:00:00', 'sort_order' => 2, 'meta' => '{}'],
                ],
                default => [],
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => str_contains($query, 'WHERE id = 91')
            ? ['id' => 91, 'plan_id' => 5, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'drip_type' => 'delayed', 'drip_delay_days' => 3, 'drip_date' => null, 'sort_order' => 1, 'meta' => '{}']
            : (str_contains($query, 'wp_fchub_membership_plans') ? [
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => '',
                'status' => 'active',
                'level' => 0,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => null,
                'redirect_url' => null,
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ] : null);

        $service = new DripScheduleService();
        $this->inject($service, $dripRepo, $grantRepo);

        $service->scheduleForGrant(50, 5, 21);
        $overview = $service->getOverview();
        $calendar = $service->getCalendar('2026-03-01 00:00:00', '2026-03-31 23:59:59');
        $queue = $service->getNotificationQueue(['status' => 'pending']);
        $stats = $service->getQueueStats();
        $retry = $service->retry(5);

        self::assertCount(2, $scheduled);
        self::assertSame('2026-04-01 00:00:00', $scheduled[1]['notify_at']);
        self::assertSame('Gold Plan', $overview[0]['plan_title']);
        self::assertSame([['notify_at' => '2026-03-20 10:00:00']], $calendar);
        self::assertSame([['id' => 1, 'status' => 'pending']], $queue);
        self::assertSame(['pending' => 3, 'sent' => 7], $stats);
        self::assertTrue($retry);
    }

    public function test_retry_sends_drip_email_with_structured_next_item_details(): void
    {
        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Alice Example';
        $user->user_email = 'alice@example.com';
        $user->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $user;

        $currentPost = new \WP_Post();
        $currentPost->ID = 55;
        $currentPost->post_type = 'post';
        $currentPost->post_title = 'Current Lesson';
        $nextPost = new \WP_Post();
        $nextPost->ID = 56;
        $nextPost->post_type = 'post';
        $nextPost->post_title = 'Next Lesson';
        $GLOBALS['_fchub_test_posts'][55] = $currentPost;
        $GLOBALS['_fchub_test_posts'][56] = $nextPost;
        $GLOBALS['_fchub_test_post_types'] = ['post'];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_drip_unlocked' => 'yes',
        ];
        $GLOBALS['_fchub_test_options']['date_format'] = 'Y-m-d';

        $dripRepo = new class extends DripScheduleRepository {
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'grant_id' => 50,
                    'status' => 'failed',
                    'plan_rule_id' => 91,
                    'user_id' => 21,
                ];
            }

            public function markSent(int $id): bool
            {
                return true;
            }
        };

        $grantRepo = new class extends GrantRepository {
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'user_id' => 21,
                    'status' => 'active',
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'plan_id' => 5,
                ];
            }
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => match (true) {
            str_contains($query, 'WHERE id = 91') => [
                'id' => 91,
                'plan_id' => 5,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '55',
                'drip_type' => 'delayed',
                'drip_delay_days' => 2,
                'drip_date' => null,
                'sort_order' => 1,
                'meta' => '{}',
            ],
            str_contains($query, 'wp_fchub_membership_plans') => [
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => '',
                'status' => 'active',
                'level' => 0,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => null,
                'redirect_url' => null,
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
            default => null,
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => match (true) {
            str_contains($query, 'SELECT * FROM wp_fchub_membership_plan_rules WHERE plan_id = 5 ORDER BY sort_order ASC, id ASC') => [
                [
                    'id' => 91,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'drip_type' => 'delayed',
                    'drip_delay_days' => 2,
                    'drip_date' => null,
                    'sort_order' => 1,
                    'meta' => '{}',
                ],
                [
                    'id' => 92,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '56',
                    'drip_type' => 'fixed_date',
                    'drip_delay_days' => 0,
                    'drip_date' => '2026-04-01 00:00:00',
                    'sort_order' => 2,
                    'meta' => '{}',
                ],
            ],
            str_contains($query, 'SELECT * FROM wp_fchub_membership_plan_rules WHERE plan_id IN (5) ORDER BY plan_id ASC, sort_order ASC') => [],
            default => [],
        };

        $service = new DripScheduleService();
        $this->inject($service, $dripRepo, $grantRepo);

        self::assertTrue($service->retry(5));
        self::assertCount(1, $GLOBALS['_fchub_test_mails']);
        self::assertStringContainsString('Current Lesson', $GLOBALS['_fchub_test_mails'][0][1]);
        self::assertStringContainsString('Coming next:', $GLOBALS['_fchub_test_mails'][0][2]);
        self::assertStringContainsString('Next Lesson', $GLOBALS['_fchub_test_mails'][0][2]);
        self::assertStringContainsString('2026-04-01', $GLOBALS['_fchub_test_mails'][0][2]);
    }

    public function test_retry_returns_false_for_missing_or_inactive_grants(): void
    {
        $missingNotificationRepo = new class extends DripScheduleRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        };

        $service = new DripScheduleService();
        $this->inject($service, $missingNotificationRepo, new class extends GrantRepository {});
        self::assertFalse($service->retry(99));

        $failedNotificationRepo = new class extends DripScheduleRepository {
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'grant_id' => 50,
                    'status' => 'failed',
                    'plan_rule_id' => 91,
                    'user_id' => 21,
                ];
            }
        };

        $inactiveGrantRepo = new class extends GrantRepository {
            public function find(int $id): ?array
            {
                return ['id' => $id, 'status' => 'revoked'];
            }
        };

        $service = new DripScheduleService();
        $this->inject($service, $failedNotificationRepo, $inactiveGrantRepo);
        self::assertFalse($service->retry(5));
    }

    public function test_retry_succeeds_without_sending_email_when_rule_record_is_missing(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_drip_unlocked' => 'yes',
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => str_contains($query, 'WHERE id = 999')
            ? null
            : null;

        $dripRepo = new class extends DripScheduleRepository {
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'grant_id' => 50,
                    'status' => 'failed',
                    'plan_rule_id' => 999,
                    'user_id' => 21,
                ];
            }

            public function markSent(int $id): bool
            {
                return true;
            }
        };

        $grantRepo = new class extends GrantRepository {
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'user_id' => 21,
                    'status' => 'active',
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'plan_id' => 5,
                ];
            }
        };

        $service = new DripScheduleService();
        $this->inject($service, $dripRepo, $grantRepo);

        self::assertTrue($service->retry(5));
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
    }

    public function test_private_helper_methods_cover_notify_at_and_resource_url_branches(): void
    {
        $service = new DripScheduleService();

        $calculateNotifyAt = new \ReflectionMethod(DripScheduleService::class, 'calculateNotifyAt');
        $getAdapter = new \ReflectionMethod(DripScheduleService::class, 'getAdapter');
        $getResourceUrl = new \ReflectionMethod(DripScheduleService::class, 'getResourceUrl');

        $GLOBALS['_fchub_test_post_types'] = ['post'];
        $GLOBALS['_fchub_test_taxonomies'] = ['category'];

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $calculateNotifyAt->invoke($service, ['drip_type' => 'delayed', 'drip_delay_days' => 3])
        );
        self::assertSame(
            '2026-04-01 00:00:00',
            $calculateNotifyAt->invoke($service, ['drip_type' => 'fixed_date', 'drip_delay_days' => 0, 'drip_date' => '2026-04-01 00:00:00'])
        );
        self::assertNull($calculateNotifyAt->invoke($service, ['drip_type' => 'immediate', 'drip_delay_days' => 0]));

        self::assertInstanceOf(\FChubMemberships\Adapters\WordPressContentAdapter::class, $getAdapter->invoke($service, 'wordpress_core'));
        self::assertNull($getAdapter->invoke($service, 'unsupported_provider'));
        self::assertSame('https://example.com/?p=55', $getResourceUrl->invoke($service, 'post', '55'));
        self::assertSame('https://example.com/category/3', $getResourceUrl->invoke($service, 'category', '3'));
        self::assertSame('', $getResourceUrl->invoke($service, 'unknown_type', '99'));
    }
}
