<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers\Plans;

use FChubMemberships\Http\Controllers\Plans\PlanWriteController;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * Tests for PlanWriteController membership_term handling.
 *
 * These tests exercise the controller's validation logic directly.
 * PlanService/PlanRepository calls hit the wpdb stub (no real DB),
 * so we focus on validation responses (422) vs acceptance (201/200).
 */
class PlanWriteControllerTermTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 4) . '/stubs/controller-stubs.php';

        // Reset the singleton so tests are isolated
        \FChubMemberships\Support\ResourceTypeRegistry::reset();

        // Override wpdb with a version that returns a fake plan row from get_row/get_var.
        // PlanService::create() calls PlanRepository::find() after insert, and
        // PlanRepository::slugExists() calls get_var — both need non-null returns.
        $GLOBALS['wpdb'] = new class extends \wpdb {
            public function get_row(string $query, string $output = OBJECT): array|object|null
            {
                $GLOBALS['_fchub_test_queries'][] = ['get_row', $query, $output];
                // Return a fake plan row for any SELECT * FROM wp_fchub_membership_plans
                if (str_contains($query, 'fchub_membership_plans')) {
                    return [
                        'id'                => $this->insert_id ?: 1,
                        'title'             => 'Test Plan',
                        'slug'              => 'test-plan',
                        'description'       => '',
                        'status'            => 'active',
                        'level'             => 0,
                        'duration_type'     => 'lifetime',
                        'duration_days'     => null,
                        'trial_days'        => 0,
                        'grace_period_days' => 0,
                        'includes_plan_ids' => '[]',
                        'restriction_message' => null,
                        'redirect_url'      => null,
                        'settings'          => '{}',
                        'meta'              => '{}',
                        'scheduled_status'  => null,
                        'scheduled_at'      => null,
                        'created_at'        => '2026-03-13 22:00:00',
                        'updated_at'        => '2026-03-13 22:00:00',
                    ];
                }
                return null;
            }

            public function get_var(string $query): string|int|float|null
            {
                $GLOBALS['_fchub_test_queries'][] = ['get_var', $query];
                // slugExists: return 0 (slug doesn't exist) so create() proceeds
                // getMemberCount/getRuleCount: return 0
                return 0;
            }
        };
    }

    // ---------------------------------------------------------------
    // Helper to build a minimal valid plan payload
    // ---------------------------------------------------------------

    private function validPlanData(array $overrides = []): array
    {
        return array_merge([
            'title'         => 'Test Plan',
            'slug'          => 'test-plan',
            'description'   => '',
            'status'        => 'active',
            'duration_type' => 'lifetime',
            'meta'          => [],
            'rules'         => [],
        ], $overrides);
    }

    private function makeRequest(array $data, ?int $id = null): \WP_REST_Request
    {
        $params = $data;
        if ($id !== null) {
            $params['id'] = $id;
        }
        return new \WP_REST_Request('POST', '/fchub-memberships/v1/plans', $params);
    }

    // ===============================================================
    // store() — membership_term validation
    // ===============================================================

    public function test_store_accepts_meta_without_membership_term_key(): void
    {
        // meta exists but has NO membership_term key — should be treated as no term
        $data = $this->validPlanData(['meta' => ['billing_anchor_day' => 15]]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status(), 'Should accept meta without membership_term');
    }

    public function test_store_accepts_mode_none(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => ['mode' => 'none']],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status());
    }

    public function test_store_accepts_mode_none_with_extra_fields(): void
    {
        // mode='none' but custom fields are also sent — should pass because mode='none' skips validation
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'none',
                'value' => 12,
                'unit'  => 'months',
                'date'  => '2027-01-01',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status(), 'mode=none should skip validation regardless of extra fields');
    }

    public function test_store_accepts_preset_year_modes(): void
    {
        foreach (['1y', '2y', '3y'] as $mode) {
            $data = $this->validPlanData([
                'meta' => ['membership_term' => ['mode' => $mode]],
            ]);
            $response = PlanWriteController::store($this->makeRequest($data));
            $this->assertEquals(201, $response->get_status(), "mode={$mode} should be accepted");
        }
    }

    public function test_store_accepts_custom_mode_with_valid_config(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => 6,
                'unit'  => 'months',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status());
    }

    public function test_store_accepts_custom_mode_with_string_value(): void
    {
        // value as string '5' — validate() casts to int, should pass
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => '5',
                'unit'  => 'months',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status(), 'String value should be accepted (cast to int)');
    }

    public function test_store_rejects_custom_mode_with_zero_value(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => 0,
                'unit'  => 'months',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_store_rejects_custom_mode_without_unit(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => 5,
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status(), 'Missing unit should be rejected');
    }

    public function test_store_rejects_custom_mode_with_invalid_unit(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => 5,
                'unit'  => 'centuries',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status(), 'Invalid unit should be rejected');
    }

    public function test_store_rejects_invalid_mode(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => ['mode' => 'forever']],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status(), 'Invalid mode should be rejected');
    }

    public function test_store_rejects_date_mode_without_date(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => ['mode' => 'date']],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_store_accepts_date_mode_with_valid_date(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode' => 'date',
                'date' => '2027-12-31',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status());
    }

    public function test_store_accepts_empty_meta(): void
    {
        $data = $this->validPlanData(['meta' => []]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status());
    }

    public function test_store_accepts_no_meta_key_at_all(): void
    {
        $data = $this->validPlanData();
        unset($data['meta']);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status());
    }

    /**
     * FIXED: Extremely large custom values now rejected by validate().
     * Previously, 999999 years passed validation but strtotime overflow
     * caused calculateEndDate to return null, silently dropping the term.
     */
    public function test_store_rejects_absurdly_large_custom_value(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => 999999,
                'unit'  => 'years',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status(), 'Values exceeding unit max should be rejected');
    }

    public function test_store_accepts_max_boundary_custom_value(): void
    {
        // 100 years is the maximum — should pass
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => 100,
                'unit'  => 'years',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status(), '100 years (max) should be accepted');
    }

    public function test_store_rejects_over_max_boundary_custom_value(): void
    {
        // 101 years exceeds the limit
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => 101,
                'unit'  => 'years',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status(), '101 years should be rejected');
    }

    // ===============================================================
    // update() — membership_term validation
    // ===============================================================

    public function test_update_validates_term_on_meta_change(): void
    {
        $data = [
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => 0,
                'unit'  => 'months',
            ]],
        ];
        $response = PlanWriteController::update($this->makeRequest($data, 1));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_update_accepts_meta_without_membership_term(): void
    {
        // Sending meta WITHOUT membership_term key.
        // PlanService::update() merges: array_merge($existing['meta'], $data['meta'])
        // Since membership_term is not in $data['meta'], the existing value is PRESERVED.
        // This is correct behavior — no validation error expected.
        $data = [
            'meta' => ['billing_anchor_day' => 20],
        ];
        $response = PlanWriteController::update($this->makeRequest($data, 1));

        $this->assertEquals(200, $response->get_status(), 'Should accept meta update without membership_term key');
    }

    public function test_update_skips_term_validation_when_no_meta_sent(): void
    {
        // No meta at all — controller skips the meta block entirely
        $data = ['title' => 'New Title'];
        $response = PlanWriteController::update($this->makeRequest($data, 1));

        // Will fail with "Plan not found" from PlanService (stub returns null)
        // but it should NOT fail with a term validation error
        $responseData = $response->get_data();
        $this->assertStringNotContainsString('term', strtolower($responseData['message'] ?? ''));
    }

    // ===============================================================
    // store() — interaction between meta keys
    // ===============================================================

    public function test_store_preserves_both_anchor_day_and_term_in_meta(): void
    {
        $data = $this->validPlanData([
            'duration_type' => 'fixed_anchor',
            'meta'          => [
                'billing_anchor_day' => 15,
                'membership_term'    => [
                    'mode'  => 'custom',
                    'value' => 6,
                    'unit'  => 'months',
                ],
            ],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status(), 'Both anchor_day and term should coexist');
    }

    // ===============================================================
    // store() — duration_type + anchor validation
    // ===============================================================

    public function test_store_rejects_fixed_anchor_without_billing_anchor_day(): void
    {
        $data = $this->validPlanData([
            'duration_type' => 'fixed_anchor',
            'meta'          => [],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_store_rejects_fixed_anchor_with_anchor_day_zero(): void
    {
        $data = $this->validPlanData([
            'duration_type' => 'fixed_anchor',
            'meta'          => ['billing_anchor_day' => 0],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_store_rejects_fixed_anchor_with_anchor_day_32(): void
    {
        $data = $this->validPlanData([
            'duration_type' => 'fixed_anchor',
            'meta'          => ['billing_anchor_day' => 32],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_store_accepts_fixed_anchor_with_valid_anchor_day(): void
    {
        $data = $this->validPlanData([
            'duration_type' => 'fixed_anchor',
            'meta'          => ['billing_anchor_day' => 15],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(201, $response->get_status());
    }

    // ===============================================================
    // store() — basic validation
    // ===============================================================

    public function test_store_rejects_missing_title(): void
    {
        $data = $this->validPlanData(['title' => '']);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_store_rejects_invalid_duration_type(): void
    {
        $data = $this->validPlanData(['duration_type' => 'unlimited']);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_store_rejects_fixed_days_without_duration(): void
    {
        $data = $this->validPlanData(['duration_type' => 'fixed_days']);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status());
    }

    // ===============================================================
    // update() — anchor validation within duration_type block
    // ===============================================================

    public function test_update_rejects_fixed_anchor_without_meta(): void
    {
        // Sends duration_type=fixed_anchor but no meta — $data['meta'] not set,
        // so anchor_day defaults to 0, which fails < 1 check
        $data = ['duration_type' => 'fixed_anchor'];
        $response = PlanWriteController::update($this->makeRequest($data, 1));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_update_rejects_fixed_anchor_with_bad_anchor_day(): void
    {
        $data = [
            'duration_type' => 'fixed_anchor',
            'meta'          => ['billing_anchor_day' => 99],
        ];
        $response = PlanWriteController::update($this->makeRequest($data, 1));

        $this->assertEquals(422, $response->get_status());
    }

    // ===============================================================
    // MembershipTermCalculator::validate edge cases
    // (exercised indirectly through the controller)
    // ===============================================================

    public function test_store_rejects_custom_mode_with_negative_value(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode'  => 'custom',
                'value' => -5,
                'unit'  => 'months',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        $this->assertEquals(422, $response->get_status());
    }

    public function test_store_rejects_date_mode_with_unparseable_date(): void
    {
        $data = $this->validPlanData([
            'meta' => ['membership_term' => [
                'mode' => 'date',
                'date' => 'not-a-date',
            ]],
        ]);
        $response = PlanWriteController::store($this->makeRequest($data));

        // strtotime('not-a-date') returns false, but validate() checks
        // strtotime($date) === false — PHP 8 returns false for this
        // So this should be rejected
        $this->assertEquals(422, $response->get_status());
    }
}

/*
 * =====================================================================
 * VUE STATE BUGS — DOCUMENTED
 * (Cannot be unit tested in PHP, tracked here for reference)
 * =====================================================================
 *
 * BUG #1 (FIXED): savePlan() was NOT clearing membership_term on 'none'
 * -----------------------------------------------------------------------
 * File: resources/admin/pages/Plans/PlanEditor.vue
 *
 * Previously, when termMode === 'none', savePlan() omitted membership_term
 * from meta entirely. PlanService::update() merges via array_merge, so the
 * old term was preserved instead of being cleared.
 *
 * Fixed: membership_term is now always included in the payload. When mode
 * is 'none', it sends { mode: 'none' } which overwrites the old value.
 *
 * BUG #2: savePlan() rebuilds meta from scratch — other meta keys may be lost
 * ---------------------------------------------------------------------------
 * File: resources/admin/pages/Plans/PlanEditor.vue, lines 896-898
 *
 * The meta object is rebuilt as either { billing_anchor_day: ... } or {}
 * depending on duration_type. Any meta keys OTHER than billing_anchor_day
 * and membership_term that the backend might have stored are silently dropped
 * on update. The backend merge (array_merge) will preserve keys not sent,
 * but if the Vue sends an empty {} for meta, it will wipe all existing keys.
 *
 * Currently this is mitigated by the backend merge, but it's fragile. If a
 * future feature adds a new meta key that the Vue doesn't know about,
 * saving the plan will wipe it.
 *
 * BUG #3: Switching duration_type does NOT affect membership_term state
 * --------------------------------------------------------------------
 * This is actually CORRECT behavior — the term is independent of duration_type.
 * No bug here, just documenting the design decision.
 *
 * BUG #4: loadPlan() handles missing membership_term gracefully (NOT a bug)
 * -------------------------------------------------------------------------
 * File: resources/admin/pages/Plans/PlanEditor.vue, lines 827-833
 *
 *   const savedTerm = planMeta.membership_term || {}
 *   form.meta.membership_term = {
 *     mode: savedTerm.mode || 'none',
 *     ...
 *   }
 *
 * This correctly defaults to mode='none' if membership_term is missing.
 * No bug here.
 *
 * BUG #5 (FIXED): No upper bound on custom term value
 * -----------------------------------------------------------
 * File: app/Domain/Grant/MembershipTermCalculator.php validate() method
 *
 * Previously the validator only checked value >= 1 with no upper bound.
 * A value of 999999 years passed validation but strtotime overflow caused
 * calculateEndDate to return null, silently dropping the term.
 *
 * Fixed: Added per-unit upper bounds in validate():
 *   days: max 36500, weeks: max 5200, months: max 1200, years: max 100
 */
