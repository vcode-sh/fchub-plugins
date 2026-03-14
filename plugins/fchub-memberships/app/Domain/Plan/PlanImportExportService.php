<?php

namespace FChubMemberships\Domain\Plan;

defined('ABSPATH') || exit;

final class PlanImportExportService
{
    private PlanService $plans;

    public function __construct(?PlanService $plans = null)
    {
        $this->plans = $plans ?? new PlanService();
    }

    public function export(int $planId): array
    {
        $plan = $this->plans->getFullPlan($planId);
        if (isset($plan['error'])) {
            return $plan;
        }

        return ['data' => $this->stripPortableFields($plan)];
    }

    public function import(array $data): array
    {
        if (empty($data['title'])) {
            return ['error' => __('Import data must include a plan title.', 'fchub-memberships')];
        }

        $data['slug'] = '';
        $data['status'] = 'inactive';

        return $this->plans->create($data);
    }

    public function exportAll(): array
    {
        $allPlans = $this->plans->list(['per_page' => 9999]);
        $exported = [];

        foreach ($allPlans as $plan) {
            $full = $this->plans->getFullPlan($plan['id']);
            if (isset($full['error'])) {
                continue;
            }

            $exported[] = $this->stripPortableFields($full);
        }

        return ['data' => $exported];
    }

    private function stripPortableFields(array $plan): array
    {
        unset($plan['id'], $plan['created_at'], $plan['updated_at'], $plan['members_count']);

        foreach ($plan['rules'] as &$rule) {
            unset($rule['id'], $rule['plan_id'], $rule['created_at'], $rule['updated_at']);
        }

        return $plan;
    }
}
