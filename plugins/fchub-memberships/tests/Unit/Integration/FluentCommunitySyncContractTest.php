<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\FluentCommunitySync;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Http\Controllers\MemberController;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class FluentCommunitySyncContractTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            define('FLUENT_COMMUNITY_PLUGIN_VERSION', '2.7.0');
        }
    }

    public function test_register_converts_legacy_mappings_to_canonical_rules_without_duplicate_entitlements(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31', '8' => '73'],
            'fc_badge_mappings' => ['5' => 'legacy-badge-value'],
        ];

        $rules = new Task8PlanRuleRepository([
            5 => [[
                'id' => 41,
                'plan_id' => 5,
                'provider' => 'fluent_community',
                'resource_type' => 'fc_space',
                'resource_id' => '31',
            ]],
        ]);
        $resolvedIds = [];
        $sync = new FluentCommunitySync(
            $rules,
            static function (int $resourceId) use (&$resolvedIds): ?string {
                $resolvedIds[] = $resourceId;
                return [31 => 'community', 73 => 'course'][$resourceId] ?? null;
            },
            $this->optionCoordinator()
        );

        $sync->register();

        self::assertSame([31, 73], $resolvedIds);
        self::assertSame([[
            'plan_id' => 8,
            'provider' => 'fluent_community',
            'resource_type' => 'fc_course',
            'resource_id' => '73',
            'drip_type' => 'immediate',
            'drip_delay_days' => 0,
            'meta' => ['legacy_source' => 'fc_space_mappings'],
        ]], $rules->created);

        $settings = $GLOBALS['_fchub_test_options']['fchub_memberships_settings'];
        self::assertSame([], $settings['fc_space_mappings']);
        self::assertSame(['5' => 'legacy-badge-value'], $settings['fc_badge_mappings']);
        self::assertSame('yes', $settings['fc_enabled']);
        self::assertSame([], $GLOBALS['_fchub_test_action_registrations']);
    }

    public function test_register_makes_enabled_and_mapping_decisions_from_one_locked_fresh_snapshot(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'no',
            'fc_space_mappings' => [],
        ];
        $database = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31'],
            'concurrent_key' => 'fresh',
        ];
        $locked = false;
        $acquisitions = 0;
        $coordinator = new MembershipSettingsOptionCoordinator(
            static function () use (&$locked, &$acquisitions): bool {
                $acquisitions++;
                if ($locked) {
                    return false;
                }
                $locked = true;
                return true;
            },
            static function () use (&$locked): void {
                $locked = false;
            },
            static function () use (&$database, &$locked): array {
                return $locked ? $database : ['fc_enabled' => 'no', 'fc_space_mappings' => []];
            },
            static function (array $next) use (&$database): bool {
                $database = $next;
                return true;
            }
        );
        $rules = new Task8PlanRuleRepository();
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => $resourceId === 31 ? 'community' : null,
            $coordinator
        );

        $sync->register();

        self::assertSame(1, $acquisitions);
        self::assertFalse($locked);
        self::assertCount(1, $rules->created);
        self::assertSame([], $database['fc_space_mappings']);
        self::assertSame('fresh', $database['concurrent_key']);
    }

    public function test_failed_conversion_retains_created_rules_and_legacy_mappings_for_retry(): void
    {
        $legacyMappings = ['5' => '31', '8' => '73'];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => $legacyMappings,
        ];

        $rules = new Task8PlanRuleRepository();
        $rules->failOnCreateNumber = 2;
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => [31 => 'community', 73 => 'course'][$resourceId] ?? null,
            $this->optionCoordinator()
        );

        self::assertFalse($sync->migrateLegacyMappings());
        self::assertSame([], $rules->deleted);
        self::assertSame([101], $rules->successfulCreateIds);
        self::assertSame(
            $legacyMappings,
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['fc_space_mappings']
        );
    }

    public function test_unresolved_or_unsupported_provider_resource_preserves_settings_without_partial_writes(): void
    {
        $legacyMappings = ['5' => '31', '8' => '999'];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => $legacyMappings,
        ];

        $rules = new Task8PlanRuleRepository();
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => $resourceId === 31 ? 'community' : null,
            $this->optionCoordinator()
        );

        self::assertFalse($sync->migrateLegacyMappings());
        self::assertSame([], $rules->created);
        self::assertSame(
            $legacyMappings,
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['fc_space_mappings']
        );
    }

    public function test_invalid_mapping_keys_are_not_reinterpreted_as_provider_entitlements(): void
    {
        $legacyMappings = ['not-a-plan' => '31', '8' => '0'];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => $legacyMappings,
        ];

        $rules = new Task8PlanRuleRepository();
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => 'community',
            $this->optionCoordinator()
        );

        self::assertFalse($sync->migrateLegacyMappings());
        self::assertSame([], $rules->created);
        self::assertSame(
            $legacyMappings,
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['fc_space_mappings']
        );
    }

    public function test_empty_unmapped_rows_do_not_block_valid_legacy_conversion(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '', '8' => '73'],
        ];

        $rules = new Task8PlanRuleRepository();
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => $resourceId === 73 ? 'course' : null,
            $this->optionCoordinator()
        );

        self::assertTrue($sync->migrateLegacyMappings());
        self::assertSame([8], array_column($rules->created, 'plan_id'));
        self::assertSame([], $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['fc_space_mappings']);
    }

    public function test_default_resolver_reads_the_installed_base_space_type_contract(): void
    {
        if (!class_exists('FluentCommunity\\App\\Models\\BaseSpace')) {
            eval(<<<'PHP'
                namespace FluentCommunity\App\Models;

                final class BaseSpace
                {
                    public static function withoutGlobalScopes(): object
                    {
                        return new class {
                            public function find(int $resourceId): ?object
                            {
                                return $GLOBALS['_fchub_test_community_base_spaces'][$resourceId] ?? null;
                            }
                        };
                    }
                }
                PHP);
        }

        $GLOBALS['_fchub_test_community_base_spaces'] = [
            31 => (object) ['id' => 31, 'type' => 'community'],
            73 => (object) ['id' => 73, 'type' => 'course'],
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31', '8' => '73'],
        ];

        $rules = new Task8PlanRuleRepository();
        $sync = new FluentCommunitySync($rules, null, $this->optionCoordinator());

        self::assertTrue($sync->migrateLegacyMappings());
        self::assertSame(['fc_space', 'fc_course'], array_column($rules->created, 'resource_type'));
    }

    public function test_void_compatibility_boot_cannot_hide_canonical_grant_or_revoke_failures(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => [],
        ];

        (new FluentCommunitySync(
            new Task8PlanRuleRepository(),
            null,
            $this->optionCoordinator()
        ))->register();

        self::assertSame([], $GLOBALS['_fchub_test_action_registrations']);

        $grantResponse = MemberController::grantResultResponse([
            'created' => 0,
            'updated' => 0,
            'total' => 1,
            'failed' => 1,
            'errors' => [['message' => 'FluentCommunity grant failed']],
        ]);
        $revokeResponse = MemberController::revokeResultResponse([
            'success' => false,
            'partial' => false,
            'revoked' => 0,
            'retained' => 0,
            'failed' => 1,
            'errors' => [['message' => 'FluentCommunity revoke failed']],
        ]);

        self::assertSame(502, $grantResponse->get_status());
        self::assertSame('FluentCommunity grant failed', $grantResponse->get_data()['data']['errors'][0]['message']);
        self::assertSame(502, $revokeResponse->get_status());
        self::assertSame('FluentCommunity revoke failed', $revokeResponse->get_data()['data']['errors'][0]['message']);
    }

    public function test_two_competing_migrators_create_one_canonical_rule(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31'],
        ];
        $locked = false;
        $coordinator = $this->optionCoordinator($locked);
        $rules = new Task8PlanRuleRepository();
        $resolver = static fn(int $resourceId): ?string => $resourceId === 31 ? 'community' : null;
        $first = new FluentCommunitySync($rules, $resolver, $coordinator);
        $second = new FluentCommunitySync($rules, $resolver, $coordinator);
        $secondResult = null;
        $rules->onCreate = static function () use (&$secondResult, $second): void {
            $secondResult = $second->migrateLegacyMappings();
        };

        self::assertTrue($first->migrateLegacyMappings());
        self::assertFalse($secondResult);
        self::assertCount(1, $rules->created);
        self::assertSame([], $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['fc_space_mappings']);
    }

    public function test_settings_save_interleaving_retries_from_fresh_state_without_restoring_retired_mappings(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31'],
        ];
        $locked = false;
        $coordinator = $this->optionCoordinator($locked);
        $rules = new Task8PlanRuleRepository();
        $adminAttempt = null;
        $rules->onCreate = static function () use (&$adminAttempt, $coordinator): void {
            $adminAttempt = $coordinator->mutate(static function (array $settings): array {
                $settings['admin_note'] = 'fresh';
                return $settings;
            });
        };
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => 'community',
            $coordinator
        );

        self::assertTrue($sync->migrateLegacyMappings());
        self::assertFalse($adminAttempt['success']);

        $retry = $coordinator->mutate(static function (array $settings): array {
            $settings['admin_note'] = 'fresh';
            return $settings;
        });

        self::assertTrue($retry['success']);
        self::assertSame([], $retry['settings']['fc_space_mappings']);
        self::assertSame('fresh', $retry['settings']['admin_note']);
    }

    public function test_retirement_write_failure_keeps_rule_and_mapping_without_rollback_delete(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31'],
        ];
        $locked = false;
        $rules = new Task8PlanRuleRepository();
        $rules->deleteSucceeds = false;
        $coordinator = $this->optionCoordinator($locked, false);
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => 'community',
            $coordinator
        );

        self::assertFalse($sync->migrateLegacyMappings());
        self::assertCount(1, $rules->created);
        self::assertSame([], $rules->deleted);
        self::assertSame(['5' => '31'], $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['fc_space_mappings']);
    }

    public function test_retirement_retry_deduplicates_retained_rule_then_clears_mapping(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31'],
        ];
        $locked = false;
        $rules = new Task8PlanRuleRepository();
        $failedCoordinator = $this->optionCoordinator($locked, false);
        $resolver = static fn(int $resourceId): ?string => 'community';

        self::assertFalse((new FluentCommunitySync($rules, $resolver, $failedCoordinator))->migrateLegacyMappings());
        self::assertTrue((new FluentCommunitySync(
            $rules,
            $resolver,
            $this->optionCoordinator($locked, true)
        ))->migrateLegacyMappings());

        self::assertCount(1, $rules->created);
        self::assertSame([], $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['fc_space_mappings']);
    }

    public function test_plan_readiness_fails_when_its_mapping_cannot_be_converted_and_has_no_canonical_rule(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '999'],
        ];
        $sync = new FluentCommunitySync(
            new Task8PlanRuleRepository(),
            static fn(int $resourceId): ?string => null,
            $this->optionCoordinator()
        );

        $result = $sync->ensurePlanReady(5);

        self::assertFalse($result['ready']);
        self::assertSame('legacy_mapping_not_converted', $result['reason']);
    }

    public function test_plan_readiness_accepts_a_retained_canonical_rule_after_retirement_failure(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31'],
        ];
        $locked = false;
        $rules = new Task8PlanRuleRepository([
            5 => [[
                'id' => 41,
                'plan_id' => 5,
                'provider' => 'fluent_community',
                'resource_type' => 'fc_space',
                'resource_id' => '31',
            ]],
        ]);
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => 'community',
            $this->optionCoordinator($locked, false)
        );

        $result = $sync->ensurePlanReady(5);

        self::assertTrue($result['ready']);
        self::assertSame('canonical_rule_present', $result['reason']);
    }

    public function test_plan_readiness_ignores_an_unrelated_malformed_legacy_mapping(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['8' => 'not-a-resource'],
        ];
        $sync = new FluentCommunitySync(
            new Task8PlanRuleRepository(),
            static fn(int $resourceId): ?string => null,
            $this->optionCoordinator()
        );

        $result = $sync->ensurePlanReady(5);

        self::assertTrue($result['ready']);
        self::assertSame('no_legacy_mapping', $result['reason']);
    }

    public function test_plan_specific_readiness_converts_its_rule_despite_an_unrelated_invalid_mapping(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31', '8' => 'not-a-resource'],
        ];
        $rules = new Task8PlanRuleRepository();
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => $resourceId === 31 ? 'community' : null,
            $this->optionCoordinator()
        );

        $result = $sync->ensurePlanReady(5);

        self::assertTrue($result['ready']);
        self::assertCount(1, $rules->created);
        self::assertSame(['8' => 'not-a-resource'], $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['fc_space_mappings']);
    }

    public function test_plan_readiness_uses_one_locked_fresh_snapshot_for_enabled_and_mapping_decisions(): void
    {
        $database = [
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31'],
            'concurrent_key' => 'fresh',
        ];
        $locked = false;
        $acquisitions = 0;
        $coordinator = new MembershipSettingsOptionCoordinator(
            static function () use (&$locked, &$acquisitions): bool {
                $acquisitions++;
                if ($locked) {
                    return false;
                }
                $locked = true;
                return true;
            },
            static function () use (&$locked): void {
                $locked = false;
            },
            static function () use (&$database, &$locked): array {
                return $locked ? $database : ['fc_enabled' => 'no', 'fc_space_mappings' => []];
            },
            static function (array $next) use (&$database): bool {
                $database = $next;
                return true;
            }
        );
        $rules = new Task8PlanRuleRepository();
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => $resourceId === 31 ? 'community' : null,
            $coordinator
        );

        $result = $sync->ensurePlanReady(5);

        self::assertTrue($result['ready']);
        self::assertSame('canonical_rule_present', $result['reason']);
        self::assertSame(1, $acquisitions);
        self::assertFalse($locked);
        self::assertCount(1, $rules->created);
        self::assertSame([], $database['fc_space_mappings']);
        self::assertSame('fresh', $database['concurrent_key']);
    }

    private function optionCoordinator(?bool &$locked = null, bool $writeSucceeds = true): MembershipSettingsOptionCoordinator
    {
        $locked ??= false;

        return new MembershipSettingsOptionCoordinator(
            static function () use (&$locked): bool {
                if ($locked) {
                    return false;
                }
                $locked = true;
                return true;
            },
            static function () use (&$locked): void {
                $locked = false;
            },
            static function (): array {
                $settings = $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] ?? [];
                return is_array($settings) ? $settings : [];
            },
            static function (array $next) use ($writeSucceeds): bool {
                if (!$writeSucceeds) {
                    return false;
                }
                $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $next;
                return true;
            }
        );
    }
}

final class Task8PlanRuleRepository extends PlanRuleRepository
{
    /** @var array<int, list<array<string, mixed>>> */
    private array $existing;

    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var list<int> */
    public array $deleted = [];

    /** @var list<int> */
    public array $successfulCreateIds = [];

    public ?int $failOnCreateNumber = null;

    public bool $deleteSucceeds = true;

    public $onCreate = null;

    /** @param array<int, list<array<string, mixed>>> $existing */
    public function __construct(array $existing = [])
    {
        $this->existing = $existing;
    }

    public function getByPlanId(int $planId): array
    {
        return $this->existing[$planId] ?? [];
    }

    public function create(array $data): int
    {
        $this->created[] = $data;
        $number = count($this->created);

        if ($number === $this->failOnCreateNumber) {
            return 0;
        }

        $id = 100 + $number;
        $this->successfulCreateIds[] = $id;
        $this->existing[(int) $data['plan_id']][] = ['id' => $id] + $data;
        if (is_callable($this->onCreate)) {
            ($this->onCreate)();
        }

        return $id;
    }

    public function delete(int $id): bool
    {
        $this->deleted[] = $id;
        return $this->deleteSucceeds;
    }
}
