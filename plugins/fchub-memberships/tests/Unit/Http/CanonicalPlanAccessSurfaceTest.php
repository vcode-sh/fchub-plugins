<?php

declare(strict_types=1);

namespace {
    if (!function_exists('shortcode_atts')) {
        function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array
        {
            return array_merge($pairs, array_intersect_key($atts, $pairs));
        }
    }

    if (!function_exists('do_shortcode')) {
        function do_shortcode(string $content): string
        {
            return $content;
        }
    }
}

namespace FChubMemberships\Tests\Unit\Http {

    use FChubMemberships\Domain\Access\ResourceAccessPolicy;
    use FChubMemberships\Domain\AccessEvaluator;
    use FChubMemberships\Frontend\Shortcodes;
    use FChubMemberships\Http\AccessCheckController;
    use FChubMemberships\Storage\GrantRepository;
    use FChubMemberships\Storage\PlanRepository;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class CanonicalPlanAccessSurfaceTest extends PluginTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            AccessEvaluator::clearCache();
            GrantRepository::clearRequestCache();
            PlanRepository::clearCache();
            $GLOBALS['_fchub_test_current_user_can'] = false;
            $GLOBALS['_fchub_test_current_user_id'] = 21;
            $GLOBALS['_fchub_test_current_user'] = (object) [
                'ID' => 21,
                'user_email' => 'member@example.com',
            ];
        }

        public function test_plan_api_denies_active_aggregate_that_starts_in_the_future(): void
        {
            $this->installScenario([], [
                $this->grantRow([
                    'starts_at' => '2026-03-14 00:00:00',
                ]),
            ]);

            $response = $this->checkPlanApi('plan-a');

            self::assertFalse($response['has_access']);
            self::assertSame('no_active_grant', $response['reason']);
            self::assertSame([], $response['grants']);
            self::assertNull($response['drip_status']);
        }

        public function test_plan_api_denies_active_aggregate_that_has_expired(): void
        {
            $this->installScenario([], [
                $this->grantRow([
                    'expires_at' => '2026-03-13 21:59:59',
                ]),
            ]);

            $response = $this->checkPlanApi('plan-a');

            self::assertFalse($response['has_access']);
            self::assertSame('no_active_grant', $response['reason']);
            self::assertSame([], $response['grants']);
            self::assertNull($response['drip_status']);
        }

        public function test_paused_typed_edge_cannot_fall_back_to_its_active_compatibility_mirror(): void
        {
            $this->installScenario([
                $this->edgeRow([
                    'access_status' => 'paused',
                ]),
            ], [
                $this->grantRow([
                    'source_type' => 'order',
                ]),
            ]);

            $response = $this->checkPlanApi('plan-a');

            self::assertFalse($response['has_access']);
            self::assertSame([], $response['grants']);
        }

        public function test_paused_manual_typed_edge_cannot_fall_back_to_its_manual_compatibility_mirror(): void
        {
            $this->installScenario([
                $this->edgeRow([
                    'access_status' => 'paused',
                ]),
            ], [
                $this->grantRow([
                    'source_type' => 'manual',
                ]),
            ]);

            $response = $this->checkPlanApi('plan-a');

            self::assertFalse($response['has_access']);
            self::assertSame([], $response['grants']);
        }

        public function test_api_and_resource_qualified_shortcode_agree_on_stacked_plan_identity(): void
        {
            $this->installScenario([
                $this->edgeRow([
                    'id' => 302,
                    'plan_id' => 2,
                ]),
            ], [
                $this->grantRow([
                    'id' => 99,
                    'plan_id' => 1,
                    'source_type' => 'order',
                ]),
            ], [
                2 => $this->planRow(2, 'plan-b'),
            ]);

            $api = $this->checkPlanApi('plan-b');
            $shortcode = Shortcodes::renderRestrict([
                'plan' => 'plan-b',
                'resource_type' => 'post',
                'resource_id' => '55',
            ], 'STACKED CONTENT');

            self::assertTrue($api['has_access']);
            self::assertSame(2, $api['grants'][0]['plan_id']);
            self::assertSame([
                'id', 'plan_id', 'status', 'starts_at', 'expires_at', 'drip_available_at',
                'resource_type', 'resource_id',
            ], array_keys($api['grants'][0]));
            self::assertSame('STACKED CONTENT', $shortcode);
        }

        public function test_resource_qualified_shortcode_does_not_grant_the_wrong_representative_plan(): void
        {
            $this->installScenario([
                $this->edgeRow([
                    'id' => 302,
                    'plan_id' => 2,
                ]),
            ], [
                $this->grantRow([
                    'id' => 99,
                    'plan_id' => 1,
                    'source_type' => 'order',
                ]),
            ], [
                2 => $this->planRow(2, 'plan-b'),
            ]);

            $api = $this->checkPlanApi('plan-a');
            $shortcode = Shortcodes::renderRestrict([
                'plan' => 'plan-a',
                'resource_type' => 'post',
                'resource_id' => '55',
            ], 'WRONG PLAN CONTENT');

            self::assertFalse($api['has_access']);
            self::assertStringNotContainsString('WRONG PLAN CONTENT', $shortcode);
            self::assertStringContainsString('restricted to members', $shortcode);
        }

        public function test_no_resource_shortcode_denies_when_every_aggregate_is_ineffective(): void
        {
            $this->installScenario([], [
                $this->grantRow([
                    'id' => 91,
                    'starts_at' => '2026-03-14 00:00:00',
                ]),
                $this->grantRow([
                    'id' => 92,
                    'expires_at' => '2026-03-13 21:59:59',
                ]),
            ]);

            $shortcode = Shortcodes::renderRestrict([
                'plan' => 'plan-a',
            ], 'INEFFECTIVE CONTENT');

            self::assertStringNotContainsString('INEFFECTIVE CONTENT', $shortcode);
            self::assertStringContainsString('restricted to members', $shortcode);
        }

        public function test_shortcode_scans_past_a_locked_lineage_when_a_stacked_lineage_is_unlocked(): void
        {
            $this->installScenario([
                $this->edgeRow([
                    'id' => 301,
                    'drip_available_at' => '2026-03-20 00:00:00',
                ]),
                $this->edgeRow([
                    'id' => 302,
                    'source_id' => 45,
                    'drip_available_at' => null,
                ]),
            ], []);

            $shortcode = Shortcodes::renderRestrict([
                'plan' => 'plan-a',
                'resource_type' => 'post',
                'resource_id' => '55',
            ], 'UNLOCKED STACK');

            self::assertSame('UNLOCKED STACK', $shortcode);
        }

        public function test_shortcode_returns_the_earliest_drip_lock_when_no_lineage_is_unlocked(): void
        {
            $GLOBALS['_fchub_test_options']['date_format'] = 'Y-m-d';
            $this->installScenario([
                $this->edgeRow([
                    'id' => 301,
                    'drip_available_at' => '2026-03-20 00:00:00',
                ]),
                $this->edgeRow([
                    'id' => 302,
                    'source_id' => 45,
                    'drip_available_at' => '2026-03-15 00:00:00',
                ]),
            ], []);

            $shortcode = Shortcodes::renderRestrict([
                'plan' => 'plan-a',
                'resource_type' => 'post',
                'resource_id' => '55',
            ], 'LOCKED STACK');

            self::assertStringContainsString('2026-03-15', $shortcode);
            self::assertStringNotContainsString('2026-03-20', $shortcode);
            self::assertStringNotContainsString('LOCKED STACK', $shortcode);
        }

        public function test_rest_and_shortcode_progress_count_distinct_plan_resources_not_edge_lineages(): void
        {
            $this->installScenario([
                $this->edgeRow([
                    'id' => 301,
                ]),
                $this->edgeRow([
                    'id' => 302,
                    'source_id' => 45,
                ]),
            ], [], [], [
                $this->ruleRow([
                    'id' => 501,
                ]),
                $this->ruleRow([
                    'id' => 502,
                    'sort_order' => 2,
                ]),
            ]);

            $apiProgress = $this->checkPlanApi('plan-a')['drip_status'];
            $progress = Shortcodes::renderDripProgress(['plan' => 'plan-a']);

            self::assertSame(1, $apiProgress['total']);
            self::assertSame(1, $apiProgress['unlocked']);
            self::assertSame(100.0, $apiProgress['percentage']);
            self::assertStringContainsString('1 of 1 items unlocked (100%)', $progress);
            self::assertStringNotContainsString('2 of 2', $progress);
        }

        public function test_rest_next_unlock_ignores_an_ineffective_aggregate(): void
        {
            $this->installScenario([
                $this->edgeRow([
                    'drip_available_at' => '2026-03-20 00:00:00',
                ]),
            ], [
                $this->grantRow([
                    'id' => 91,
                    'resource_id' => '77',
                    'source_type' => 'manual',
                    'starts_at' => '2026-03-14 00:00:00',
                    'drip_available_at' => '2026-03-15 00:00:00',
                ]),
            ], [], [
                $this->ruleRow(),
            ]);

            $progress = $this->checkPlanApi('plan-a')['drip_status'];

            self::assertSame(1, $progress['total']);
            self::assertSame(0, $progress['unlocked']);
            self::assertSame('2026-03-20 00:00:00', $progress['next_unlock']);
        }

        public function test_rest_progress_does_not_count_a_paused_typed_mirror_as_unlocked(): void
        {
            $this->installScenario([
                $this->edgeRow([
                    'id' => 301,
                    'resource_id' => '66',
                ]),
                $this->edgeRow([
                    'id' => 302,
                    'access_status' => 'paused',
                ]),
            ], [
                $this->grantRow([
                    'id' => 91,
                    'source_type' => 'manual',
                ]),
            ], [], [
                $this->ruleRow(),
            ]);

            $progress = $this->checkPlanApi('plan-a')['drip_status'];

            self::assertSame(1, $progress['total']);
            self::assertSame(0, $progress['unlocked']);
            self::assertSame(0.0, $progress['percentage']);
            self::assertNull($progress['next_unlock']);
        }

        public function test_manual_and_unmirrored_legacy_memberships_remain_safe_fallbacks(): void
        {
            $this->installScenario([
                $this->edgeRow([
                    'plan_id' => 9,
                    'access_status' => 'paused',
                    'resource_id' => '99',
                ]),
            ], [
                $this->grantRow([
                    'id' => 91,
                    'source_type' => 'manual',
                ]),
                $this->grantRow([
                    'id' => 92,
                    'plan_id' => 2,
                    'resource_id' => '77',
                    'source_type' => 'order',
                ]),
            ], [
                2 => $this->planRow(2, 'plan-b'),
            ]);

            self::assertTrue($this->checkPlanApi('plan-a')['has_access']);
            self::assertTrue($this->checkPlanApi('plan-b')['has_access']);
        }

        public function test_batch_plan_access_has_no_manual_mirror_exemption(): void
        {
            $query = '';
            $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
                $query = $sql;
                return [];
            };
            $policy = new ResourceAccessPolicy('wordpress_core', 'post', '55');
            $policy->allowAnyActivePlan();

            (new GrantRepository())->countDistinctUsersWithResourceAccessBatch(['post-55' => $policy]);

            self::assertStringContainsString('NOT EXISTS', $query);
            self::assertStringNotContainsString("membership.source_type = 'manual'", $query);
        }

        /**
         * @param list<array<string, mixed>> $edges
         * @param list<array<string, mixed>> $grants
         * @param array<int, array<string, mixed>> $extraPlans
         * @param list<array<string, mixed>> $rules
         */
        private function installScenario(
            array $edges,
            array $grants,
            array $extraPlans = [],
            array $rules = []
        ): void
        {
            $plans = [1 => $this->planRow(1, 'plan-a')] + $extraPlans;
            $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use ($plans, $grants): ?array {
                if (str_contains($query, 'fchub_membership_plans')) {
                    foreach ($plans as $plan) {
                        if (str_contains($query, "slug = '{$plan['slug']}'")) {
                            return $plan;
                        }
                        if (str_contains($query, "id = {$plan['id']}")) {
                            return $plan;
                        }
                    }

                    return null;
                }

                if (str_contains($query, 'FROM wp_fchub_membership_grants')) {
                    foreach ($grants as $grant) {
                        if (str_contains($query, "provider = '{$grant['provider']}'")
                            && str_contains($query, "resource_type = '{$grant['resource_type']}'")
                            && str_contains($query, "resource_id = '{$grant['resource_id']}'")
                        ) {
                            return $grant;
                        }
                    }
                }

                return null;
            };

            $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($edges, $grants, $rules): array {
                if (str_contains($query, 'fchub_membership_plan_rules')) {
                    return $rules;
                }

                if (str_contains($query, 'FROM wp_fchub_membership_grants')) {
                    $matches = $grants;
                    if (preg_match('/(?:membership\\.)?plan_id = (\\d+)/', $query, $match) === 1) {
                        $planId = (int) $match[1];
                        $matches = array_values(array_filter(
                            $matches,
                            static fn(array $grant): bool => (int) $grant['plan_id'] === $planId
                        ));
                    }

                    if (str_contains($query, 'membership.starts_at IS NULL')) {
                        $matches = array_values(array_filter(
                            $matches,
                            static fn(array $grant): bool => (empty($grant['starts_at'])
                                    || $grant['starts_at'] <= '2026-03-13 22:00:00')
                                && (empty($grant['expires_at'])
                                    || $grant['expires_at'] > '2026-03-13 22:00:00')
                        ));
                    }

                    if (str_contains($query, 'NOT EXISTS')) {
                        $matches = array_values(array_filter(
                            $matches,
                            static function (array $grant) use ($edges): bool {
                                if (($grant['source_type'] ?? '') === 'manual') {
                                    return true;
                                }

                                foreach ($edges as $edge) {
                                    if (($edge['provider'] ?? '') === ($grant['provider'] ?? '')
                                        && ($edge['resource_type'] ?? '') === ($grant['resource_type'] ?? '')
                                        && (string) ($edge['resource_id'] ?? '') === (string) ($grant['resource_id'] ?? '')
                                    ) {
                                        return false;
                                    }
                                }

                                return true;
                            }
                        ));
                    }

                    return $matches;
                }

                if (str_contains($query, 'fchub_membership_entitlement_edges')) {
                    if (str_contains($query, 'SELECT DISTINCT')) {
                        return array_map(
                            static fn(array $edge): array => [
                                'provider' => $edge['provider'],
                                'resource_type' => $edge['resource_type'],
                                'resource_id' => $edge['resource_id'],
                            ],
                            $edges
                        );
                    }

                    $matches = array_values(array_filter(
                        $edges,
                        static fn(array $edge): bool => ($edge['lifecycle'] ?? '') === 'active'
                            && ($edge['access_status'] ?? '') === 'active'
                            && (empty($edge['starts_at']) || $edge['starts_at'] <= '2026-03-13 22:00:00')
                            && (empty($edge['expires_at']) || $edge['expires_at'] > '2026-03-13 22:00:00')
                    ));
                    if (preg_match('/edge\\.plan_id = (\\d+)/', $query, $match) === 1) {
                        $planId = (int) $match[1];
                        $matches = array_values(array_filter(
                            $matches,
                            static fn(array $edge): bool => (int) $edge['plan_id'] === $planId
                        ));
                    }

                    return $matches;
                }

                return [];
            };
        }

        /** @return array<string, mixed> */
        private function checkPlanApi(string $slug): array
        {
            return AccessCheckController::check(new \WP_REST_Request('GET', '/check-access', [
                'plan' => $slug,
            ]))->get_data();
        }

        /** @return array<string, mixed> */
        private function planRow(int $id, string $slug): array
        {
            return [
                'id' => $id,
                'title' => strtoupper($slug),
                'slug' => $slug,
                'description' => '',
                'status' => 'active',
                'level' => 0,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => '',
                'redirect_url' => '',
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ];
        }

        /** @return array<string, mixed> */
        private function edgeRow(array $overrides = []): array
        {
            return array_merge([
                'id' => 301,
                'user_id' => 21,
                'plan_id' => 1,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '55',
                'lifecycle' => 'active',
                'access_status' => 'active',
                'starts_at' => null,
                'expires_at' => null,
                'drip_available_at' => null,
                'created_at' => '2026-03-01 00:00:00',
            ], $overrides);
        }

        /** @return array<string, mixed> */
        private function grantRow(array $overrides = []): array
        {
            return array_merge([
                'id' => 90,
                'user_id' => 21,
                'plan_id' => 1,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '55',
                'source_type' => 'order',
                'source_id' => 44,
                'feed_id' => 3,
                'grant_key' => 'compatibility-grant',
                'status' => 'active',
                'starts_at' => null,
                'expires_at' => null,
                'drip_available_at' => null,
                'trial_ends_at' => null,
                'cancellation_requested_at' => null,
                'cancellation_effective_at' => null,
                'cancellation_reason' => null,
                'renewal_count' => 0,
                'source_ids' => '[44]',
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ], $overrides);
        }

        /** @return array<string, mixed> */
        private function ruleRow(array $overrides = []): array
        {
            return array_merge([
                'id' => 501,
                'plan_id' => 1,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '55',
                'drip_type' => 'immediate',
                'drip_delay_days' => 0,
                'drip_date' => null,
                'sort_order' => 1,
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ], $overrides);
        }
    }
}
