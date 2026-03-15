<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AccessEvaluatorAdversarialTest extends PluginTestCase
{
    private function inject(AccessEvaluator $evaluator, object $grantRepo, object $ruleResolver, object $protectionRepo): void
    {
        foreach ([
            'grantRepo' => $grantRepo,
            'ruleResolver' => $ruleResolver,
            'protectionRepo' => $protectionRepo,
        ] as $property => $value) {
            $reflection = new \ReflectionProperty(AccessEvaluator::class, $property);
            $reflection->setValue($evaluator, $value);
        }
    }

    public function test_getters_prefer_specific_rules_then_plan_messages_then_settings_defaults(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'restriction_message_no_access' => 'Global no access',
            'default_redirect_url' => 'https://example.com/default-redirect',
            'show_teaser' => 'yes',
        ];

        $evaluator = new AccessEvaluator();
        $this->inject(
            $evaluator,
            new class extends GrantRepository {},
            new class extends PlanRuleResolver {
                public function findPlansWithResource(string $provider, string $resourceType, string $resourceId): array
                {
                    return $resourceId === '77' ? [5] : [];
                }
            },
            new class extends ProtectionRuleRepository {
                public function findByResource(string $resourceType, string $resourceId): ?array
                {
                    return match ($resourceId) {
                        '55' => [
                            'resource_type' => $resourceType,
                            'resource_id' => $resourceId,
                            'restriction_message' => 'Rule level message',
                            'redirect_url' => 'https://example.com/rule-redirect',
                            'show_teaser' => 'no',
                            'meta' => [],
                        ],
                        '77' => [
                            'resource_type' => $resourceType,
                            'resource_id' => $resourceId,
                            'restriction_message' => '',
                            'redirect_url' => '',
                            'show_teaser' => 'yes',
                            'meta' => [],
                        ],
                        default => null,
                    };
                }
            }
        );

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => str_contains($query, 'wp_fchub_membership_plans')
            ? [
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
                'restriction_message' => 'Plan level message',
                'redirect_url' => null,
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]
            : null;

        self::assertSame('Rule level message', $evaluator->getRestrictionMessage('post', '55'));
        self::assertSame('Plan level message', $evaluator->getRestrictionMessage('post', '77'));
        self::assertSame('Global no access', $evaluator->getRestrictionMessage('post', '88'));
        self::assertSame('https://example.com/rule-redirect', $evaluator->getRedirectUrl('post', '55'));
        self::assertSame('https://example.com/default-redirect', $evaluator->getRedirectUrl('post', '77'));
        self::assertFalse($evaluator->shouldShowTeaser('post', '55'));
        self::assertTrue($evaluator->shouldShowTeaser('post', '88'));
    }

    public function test_can_access_multiple_uses_cache_plan_rules_and_wildcards(): void
    {
        AccessEvaluator::clearCache();
        $GLOBALS['_fchub_test_transients'] = [];
        $GLOBALS['_fchub_test_user_can'][9]['manage_options'] = false;

        $grantRepoCalls = 0;
        $ruleChecks = [];

        $grantRepo = new class($grantRepoCalls) extends GrantRepository {
            public int $calls = 0;

            public function __construct(private int &$externalCalls)
            {
            }

            public function getAllUserResourceIds(int $userId): array
            {
                $this->calls++;
                $this->externalCalls++;
                return ['post' => ['55'], 'page' => ['*']];
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [['plan_id' => 5]];
            }
        };

        $ruleResolver = new class($ruleChecks) extends PlanRuleResolver {
            public array $checks = [];

            public function __construct(private array &$externalChecks)
            {
            }

            public function planHasResource(int $planId, string $provider, string $resourceType, string $resourceId): bool
            {
                $this->checks[] = [$planId, $provider, $resourceType, $resourceId];
                $this->externalChecks[] = [$planId, $provider, $resourceType, $resourceId];
                return $resourceId === '77';
            }
        };

        $protectionRepo = new class extends ProtectionRuleRepository {};

        $evaluator = new AccessEvaluator();
        $this->inject($evaluator, $grantRepo, $ruleResolver, $protectionRepo);

        $first = $evaluator->canAccessMultiple(9, ['55', '77', '88'], 'post');
        $second = $evaluator->canAccessMultiple(9, ['11', '12'], 'page');
        $third = $evaluator->canAccessMultiple(9, ['55', '77', '88'], 'post');

        self::assertSame(['55', '77'], $first);
        self::assertSame(['11', '12'], $second, 'Wildcard page grant should unlock all page resources.');
        self::assertSame(['55', '77'], $third);
        self::assertSame(1, $grantRepoCalls, 'Transient cache should prevent rebuilding the user resource map twice.');
        self::assertNotEmpty($ruleChecks);
    }

    public function test_is_protected_and_drip_progress_cover_taxonomy_and_unlock_helpers(): void
    {
        AccessEvaluator::clearCache();
        $GLOBALS['_fchub_test_user_can'][9]['manage_options'] = false;

        $post = new \WP_Post();
        $post->ID = 55;
        $post->post_type = 'post';
        $GLOBALS['_fchub_test_posts'][55] = $post;
        $GLOBALS['_fchub_test_post_types'] = ['post'];
        $GLOBALS['_fchub_test_get_object_taxonomies']['post'] = ['category'];
        $GLOBALS['_fchub_test_post_terms'][55]['category'] = [(object) ['term_id' => 3]];

        $grantRepo = new class extends GrantRepository {
            public function getByUserId(int $userId, array $filters = []): array
            {
                if (($filters['status'] ?? null) === 'active' && ($filters['plan_id'] ?? null) === 5) {
                    return [
                        ['drip_available_at' => '2026-03-20 00:00:00'],
                        ['drip_available_at' => '2026-03-18 00:00:00'],
                    ];
                }

                return [];
            }

            public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
            {
                if ($resourceId === '55') {
                    return [
                        'id' => 1,
                        'trial_ends_at' => null,
                        'drip_available_at' => null,
                    ];
                }

                return null;
            }
        };

        $ruleResolver = new class extends PlanRuleResolver {
            public function findPlansWithResource(string $provider, string $resourceType, string $resourceId): array
            {
                return $resourceType === 'category' ? [5] : [];
            }

            public function resolveUniqueRules(int $planId): array
            {
                return [
                    ['id' => 1, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55'],
                    ['id' => 2, 'provider' => 'wordpress_core', 'resource_type' => 'page', 'resource_id' => '77'],
                ];
            }
        };

        $protectionRepo = new class extends ProtectionRuleRepository {
            public function isProtected(string $resourceType, string $resourceId): bool
            {
                return false;
            }

            public function findByResource(string $resourceType, string $resourceId): ?array
            {
                if ($resourceType === 'category' && $resourceId === '3') {
                    return ['meta' => ['inheritance_mode' => 'all_posts']];
                }

                return null;
            }
        };

        $evaluator = new AccessEvaluator();

        $this->inject($evaluator, $grantRepo, $ruleResolver, $protectionRepo);

        self::assertTrue($evaluator->isProtected('wordpress_core', 'post', '55'));

        $progress = $evaluator->getDripProgress(9, 5);
        self::assertSame(2, $progress['total']);
        self::assertSame(1, $progress['unlocked']);
        self::assertSame(50.0, $progress['percentage']);
        self::assertSame('2026-03-18 00:00:00', $progress['next_unlock']);
    }

    public function test_evaluate_covers_admin_bypass_and_direct_grant_paths(): void
    {
        AccessEvaluator::clearCache();
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['admin_bypass' => 'yes'];
        $GLOBALS['_fchub_test_user_can'][1]['manage_options'] = true;

        $adminEvaluator = new AccessEvaluator();
        $this->inject(
            $adminEvaluator,
            new class extends GrantRepository {},
            new class extends PlanRuleResolver {},
            new class extends ProtectionRuleRepository {}
        );

        $admin = $adminEvaluator->evaluate(1, 'wordpress_core', 'post', '55');
        self::assertTrue($admin['allowed']);
        self::assertSame('admin_bypass', $admin['reason']);

        $GLOBALS['_fchub_test_user_can'][2]['manage_options'] = false;
        $grantRepo = new class extends GrantRepository {
            public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
            {
                if ($resourceId === 'drip-lock') {
                    return [
                        'id' => 10,
                        'trial_ends_at' => '2026-03-20 00:00:00',
                        'drip_available_at' => '2026-03-18 00:00:00',
                    ];
                }

                if ($resourceId === 'direct-open') {
                    return [
                        'id' => 11,
                        'trial_ends_at' => '2026-03-20 00:00:00',
                        'drip_available_at' => null,
                    ];
                }

                return null;
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [];
            }
        };

        $grantEvaluator = new AccessEvaluator();
        $this->inject(
            $grantEvaluator,
            $grantRepo,
            new class extends PlanRuleResolver {},
            new class extends ProtectionRuleRepository {}
        );

        $locked = $grantEvaluator->evaluate(2, 'wordpress_core', 'post', 'drip-lock');
        $open = $grantEvaluator->evaluate(2, 'wordpress_core', 'post', 'direct-open');

        self::assertFalse($locked['allowed']);
        self::assertTrue($locked['drip_locked']);
        self::assertSame('2026-03-18 00:00:00', $locked['drip_available_at']);
        self::assertTrue($locked['trial_active']);
        self::assertTrue($open['allowed']);
        self::assertSame('direct_grant', $open['reason']);
        self::assertTrue($open['trial_active']);
    }

    public function test_evaluate_covers_plan_drip_wildcard_paused_and_no_grant_paths(): void
    {
        AccessEvaluator::clearCache();
        $GLOBALS['_fchub_test_user_can'][9]['manage_options'] = false;

        $planEvaluator = new AccessEvaluator();
        $this->inject(
            $planEvaluator,
            new class extends GrantRepository {
                public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
                {
                    return null;
                }

                public function getByUserId(int $userId, array $filters = []): array
                {
                    if (($filters['status'] ?? null) === 'active') {
                        return [[
                            'plan_id' => 5,
                            'created_at' => null,
                            'trial_ends_at' => '2026-03-20 00:00:00',
                            'status' => 'active',
                        ]];
                    }

                    return [];
                }
            },
            new class extends PlanRuleResolver {
                public function planHasResource(int $planId, string $provider, string $resourceType, string $resourceId): bool
                {
                    return $resourceId === '77';
                }

                public function getDripRule(int $planId, string $provider, string $resourceType, string $resourceId): ?array
                {
                    if ($resourceId !== '77') {
                        return null;
                    }

                    return [
                        'drip_type' => 'delayed',
                        'drip_delay_days' => 2,
                    ];
                }
            },
            new class extends ProtectionRuleRepository {}
        );

        $plan = $planEvaluator->evaluate(9, 'wordpress_core', 'post', '77');
        self::assertFalse($plan['allowed']);
        self::assertTrue($plan['drip_locked']);
        self::assertStringStartsWith('2026-03-15', (string) $plan['drip_available_at']);
        self::assertTrue($plan['trial_active']);

        $wildcardEvaluator = new AccessEvaluator();
        $this->inject(
            $wildcardEvaluator,
            new class extends GrantRepository {
                public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
                {
                    if ($resourceId === '*') {
                        return ['id' => 99, 'trial_ends_at' => null, 'drip_available_at' => null];
                    }

                    return null;
                }

                public function getByUserId(int $userId, array $filters = []): array
                {
                    return [];
                }
            },
            new class extends PlanRuleResolver {},
            new class extends ProtectionRuleRepository {}
        );

        $wildcard = $wildcardEvaluator->evaluate(9, 'wordpress_core', 'page', '123');
        self::assertTrue($wildcard['allowed']);
        self::assertSame('wildcard_grant', $wildcard['reason']);

        $pausedEvaluator = new AccessEvaluator();
        $this->inject(
            $pausedEvaluator,
            new class extends GrantRepository {
                public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
                {
                    return null;
                }

                public function getByUserId(int $userId, array $filters = []): array
                {
                    if (($filters['status'] ?? null) === 'paused') {
                        return [[
                            'id' => 12,
                            'resource_type' => 'post',
                            'resource_id' => '404',
                        ]];
                    }

                    return [];
                }
            },
            new class extends PlanRuleResolver {},
            new class extends ProtectionRuleRepository {}
        );

        $paused = $pausedEvaluator->evaluate(9, 'wordpress_core', 'post', '404');
        self::assertFalse($paused['allowed']);
        self::assertSame('membership_paused', $paused['reason']);

        $none = $pausedEvaluator->evaluate(9, 'wordpress_core', 'post', '999');
        self::assertFalse($none['allowed']);
        self::assertSame('no_grant', $none['reason']);
    }
}
