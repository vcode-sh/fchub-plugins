<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static array $successes = [];
            public static array $lines = [];

            public static function success(string $message): void
            {
                self::$successes[] = $message;
            }

            public static function error(string $message): never
            {
                throw new \RuntimeException($message);
            }

            public static function line(string $message): void
            {
                self::$lines[] = $message;
            }

            public static function warning(string $message): void
            {
            }
        }
    }
}

namespace FChubMemberships\Tests\Unit\Cli {

    use FChubMemberships\CLI\GrantCommand;
    use FChubMemberships\Storage\DripScheduleRepository;
    use FChubMemberships\Storage\GrantRepository;
    use FChubMemberships\Storage\PlanRepository;
    use FChubMemberships\Storage\PlanRuleRepository;
    use FChubMemberships\Support\Clock;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class GrantCommandClockTest extends PluginTestCase
    {
        public function test_delayed_drip_write_uses_site_local_calendar_day(): void
        {
            $created = [];
            $grants = new class($created) extends GrantRepository {
                public function __construct(private array &$created)
                {
                }
                public function findByGrantKey(string $grantKey): ?array
                {
                    return null;
                }
                public function create(array $data): int
                {
                    $this->created[] = $data;
                    return 1;
                }
            };
            $plans = new class extends PlanRepository {
                public function findBySlug(string $slug): ?array
                {
                    return ['id' => 5, 'slug' => $slug, 'title' => 'Gold'];
                }
            };
            $rules = new class extends PlanRuleRepository {
                public function getByPlanId(int $planId): array
                {
                    return [[
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '55',
                        'drip_type' => 'delayed',
                        'drip_delay_days' => 1,
                        'drip_date' => null,
                    ]];
                }
            };
            $timezone = new \DateTimeZone('Europe/Warsaw');
            $clock = new Clock(new \DateTimeImmutable('2026-03-28 12:30:00', $timezone), $timezone);
            $command = new GrantCommand(null, null, null, null, $clock);
            foreach (['grantRepo' => $grants, 'planRepo' => $plans, 'ruleRepo' => $rules] as $property => $value) {
                (new \ReflectionProperty($command, $property))->setValue($command, $value);
            }
            $user = new \WP_User();
            $user->ID = 21;
            $user->user_email = 'member@example.test';
            $GLOBALS['_fchub_test_users'][21] = $user;

            $command->grant([], ['member' => '21', 'plan' => 'gold']);

            self::assertSame('2026-03-29 12:30:00', $created[0]['drip_available_at']);
        }
    }
}
