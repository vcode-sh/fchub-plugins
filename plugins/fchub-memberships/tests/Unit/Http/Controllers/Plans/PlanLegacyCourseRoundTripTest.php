<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers\Plans;

use FChubMemberships\Http\Controllers\Plans\PlanReadController;
use FChubMemberships\Http\Controllers\Plans\PlanWriteController;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;

if (!defined('LEARNDASH_VERSION')) {
    define('LEARNDASH_VERSION', '4.0.0');
}

final class PlanLegacyCourseRoundTripTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResourceTypeRegistry::reset();

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query): ?array {
            if (!str_contains($query, 'fchub_membership_plans')) {
                return null;
            }

            return [
                'id' => 5,
                'title' => 'Historical Plan',
                'slug' => 'historical-plan',
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
                'scheduled_status' => null,
                'scheduled_at' => null,
                'created_at' => '2026-03-13 22:00:00',
                'updated_at' => '2026-03-13 22:00:00',
            ];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            if (!str_contains($query, 'fchub_membership_plan_rules')) {
                return [];
            }

            return [[
                'id' => 19,
                'plan_id' => 5,
                'provider' => 'learndash',
                'resource_type' => 'sfwd-courses',
                'resource_id' => '41',
                'drip_delay_days' => 0,
                'drip_type' => 'immediate',
                'drip_date' => null,
                'sort_order' => 0,
                'meta' => '{}',
                'created_at' => '2026-03-13 22:00:00',
                'updated_at' => '2026-03-13 22:00:00',
            ]];
        };
    }

    public function test_historical_course_loads_and_saves_with_the_canonical_type(): void
    {
        $read = PlanReadController::show(new \WP_REST_Request('GET', '/plans/5', ['id' => 5]));
        $plan = $read->get_data()['data'];

        self::assertSame('ld_course', $plan['rules'][0]['resource_type']);
        self::assertSame('Course #41', $plan['rules'][0]['resource_label']);

        $write = PlanWriteController::update(new \WP_REST_Request('POST', '/plans/5', array_merge(
            $plan,
            ['id' => 5]
        )));

        self::assertSame(200, $write->get_status());
        $ruleInserts = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'insert'
                && str_contains($query[1], 'fchub_membership_plan_rules')
        ));
        self::assertNotEmpty($ruleInserts);
        self::assertSame('ld_course', $ruleInserts[0][2]['resource_type']);
        self::assertSame('learndash', $ruleInserts[0][2]['provider']);
    }

    public function test_historical_lessons_remain_read_only_wordpress_content_rules(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            if (!str_contains($query, 'fchub_membership_plan_rules')) {
                return [];
            }

            return [[
                'id' => 20,
                'plan_id' => 5,
                'provider' => 'wordpress_core',
                'resource_type' => 'sfwd-lessons',
                'resource_id' => '51',
                'drip_delay_days' => 0,
                'drip_type' => 'immediate',
                'drip_date' => null,
                'sort_order' => 0,
                'meta' => '{}',
                'created_at' => '2026-03-13 22:00:00',
                'updated_at' => '2026-03-13 22:00:00',
            ]];
        };

        $read = PlanReadController::show(new \WP_REST_Request('GET', '/plans/5', ['id' => 5]));
        $rule = $read->get_data()['data']['rules'][0];

        self::assertSame('sfwd-lessons', $rule['resource_type']);
        self::assertSame('wordpress_core', $rule['provider']);
        self::assertTrue($rule['read_only']);
    }

    public function test_saving_other_plan_fields_without_rules_preserves_legacy_lessons(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            if (!str_contains($query, 'fchub_membership_plan_rules')) {
                return [];
            }

            return [[
                'id' => 20,
                'plan_id' => 5,
                'provider' => 'wordpress_core',
                'resource_type' => 'sfwd-lessons',
                'resource_id' => '51',
                'drip_delay_days' => 0,
                'drip_type' => 'immediate',
                'drip_date' => null,
                'sort_order' => 0,
                'meta' => '{}',
                'created_at' => '2026-03-13 22:00:00',
                'updated_at' => '2026-03-13 22:00:00',
            ]];
        };

        $read = PlanReadController::show(new \WP_REST_Request('GET', '/plans/5', ['id' => 5]));
        $payload = $read->get_data()['data'];
        unset($payload['rules']);
        $payload['title'] = 'Renamed Historical Plan';
        $GLOBALS['_fchub_test_queries'] = [];

        $write = PlanWriteController::update(new \WP_REST_Request('POST', '/plans/5', $payload));

        self::assertSame(200, $write->get_status());
        $ruleMutations = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => in_array($query[0], ['insert', 'update', 'delete'], true)
                && str_contains((string) $query[1], 'fchub_membership_plan_rules')
        ));
        self::assertSame([], $ruleMutations);
    }
}
