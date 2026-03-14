<?php

namespace FChubMemberships\Http\Controllers\Plans;

use FChubMemberships\Domain\Plan\PlanRuleValidationService;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Support\PlanStatus;

defined('ABSPATH') || exit;

final class PlanWriteController
{
    public static function store(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();

        if (empty($data['title'])) {
            return new \WP_REST_Response(['message' => __('Plan title is required.', 'fchub-memberships')], 422);
        }

        $durationType = $data['duration_type'] ?? 'lifetime';
        if (!in_array($durationType, ['lifetime', 'fixed_days', 'subscription_mirror'], true)) {
            return new \WP_REST_Response(['message' => __('Invalid duration type.', 'fchub-memberships')], 422);
        }
        if ($durationType === 'fixed_days' && empty($data['duration_days'])) {
            return new \WP_REST_Response(['message' => __('Duration days is required for fixed duration plans.', 'fchub-memberships')], 422);
        }

        $validation = new PlanRuleValidationService();
        $rules = $data['rules'] ?? [];
        $validationError = $validation->validate($rules);
        if ($validationError) {
            return new \WP_REST_Response(['message' => $validationError], 422);
        }

        $service = new PlanService();
        $status = PlanStatus::normalize($data['status'] ?? null, PlanStatus::ACTIVE);
        $result = $service->create([
            'title'               => sanitize_text_field($data['title']),
            'slug'                => sanitize_title($data['slug'] ?? ''),
            'description'         => sanitize_textarea_field($data['description'] ?? ''),
            'status'              => $status,
            'level'               => (int) ($data['level'] ?? 0),
            'includes_plan_ids'   => array_map('intval', $data['includes_plan_ids'] ?? []),
            'restriction_message' => sanitize_textarea_field($data['restriction_message'] ?? ''),
            'redirect_url'        => esc_url_raw($data['redirect_url'] ?? ''),
            'settings'            => $data['settings'] ?? [],
            'meta'                => $data['meta'] ?? [],
            'rules'               => $validation->prepareForStorage($rules),
            'duration_type'       => $durationType,
            'duration_days'       => isset($data['duration_days']) ? (int) $data['duration_days'] : null,
            'trial_days'          => (int) ($data['trial_days'] ?? 0),
            'grace_period_days'   => (int) ($data['grace_period_days'] ?? 0),
        ]);

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 422);
        }

        return new \WP_REST_Response(['data' => $result], 201);
    }

    public static function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $data = $request->get_json_params();

        $service = new PlanService();
        $updateData = [];

        foreach (['title', 'description', 'restriction_message'] as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = sanitize_textarea_field($data[$field]);
            }
        }

        if (isset($data['slug'])) {
            $updateData['slug'] = sanitize_title($data['slug']);
        }
        if (isset($data['status'])) {
            $updateData['status'] = PlanStatus::normalize($data['status'], PlanStatus::ACTIVE);
        }
        if (isset($data['level'])) {
            $updateData['level'] = (int) $data['level'];
        }
        if (isset($data['includes_plan_ids'])) {
            $updateData['includes_plan_ids'] = array_map('intval', $data['includes_plan_ids']);
        }
        if (isset($data['redirect_url'])) {
            $updateData['redirect_url'] = esc_url_raw($data['redirect_url']);
        }
        if (isset($data['settings'])) {
            $updateData['settings'] = $data['settings'];
        }
        if (isset($data['meta'])) {
            $updateData['meta'] = $data['meta'];
        }
        if (isset($data['rules'])) {
            $validation = new PlanRuleValidationService();
            $validationError = $validation->validate($data['rules']);
            if ($validationError) {
                return new \WP_REST_Response(['message' => $validationError], 422);
            }
            $updateData['rules'] = $validation->prepareForStorage($data['rules']);
        }
        if (isset($data['duration_type'])) {
            if (!in_array($data['duration_type'], ['lifetime', 'fixed_days', 'subscription_mirror'], true)) {
                return new \WP_REST_Response(['message' => __('Invalid duration type.', 'fchub-memberships')], 422);
            }
            $updateData['duration_type'] = $data['duration_type'];
        }
        foreach (['duration_days', 'trial_days', 'grace_period_days'] as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field] !== null ? (int) $data[$field] : null;
            }
        }

        $result = $service->update($id, $updateData);

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 422);
        }

        return new \WP_REST_Response(['data' => $result]);
    }

    public static function destroy(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $service->delete((int) $request->get_param('id'));
        return new \WP_REST_Response(['message' => __('Plan deleted.', 'fchub-memberships')]);
    }

    public static function duplicate(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $result = $service->duplicate((int) $request->get_param('id'));

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 422);
        }

        return new \WP_REST_Response(['data' => $result], 201);
    }
}
