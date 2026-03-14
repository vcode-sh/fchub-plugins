<?php

namespace FChubMemberships\Domain\Plan;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Storage\GrantRepository;

class PlanService
{
    private PlanRepository $planRepo;
    private PlanRuleRepository $ruleRepo;

    public function __construct()
    {
        $this->planRepo = new PlanRepository();
        $this->ruleRepo = new PlanRuleRepository();
    }

    public function find(int $id): ?array
    {
        return $this->planRepo->find($id);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->planRepo->findBySlug($slug);
    }

    public function list(array $filters = []): array
    {
        $plans = $this->planRepo->all($filters);

        foreach ($plans as &$plan) {
            $plan['members_count'] = $this->planRepo->getMemberCount($plan['id']);
            $plan['rules_count'] = $this->planRepo->getRuleCount($plan['id']);
            $plan['drip_count'] = count($this->ruleRepo->getDripRules($plan['id']));
        }

        return $plans;
    }

    public function count(array $filters = []): int
    {
        return $this->planRepo->count($filters);
    }

    public function create(array $data): array
    {
        if (empty($data['slug'])) {
            $data['slug'] = $this->planRepo->generateUniqueSlug($data['title']);
        } elseif ($this->planRepo->slugExists($data['slug'])) {
            return ['error' => __('Plan slug already exists.', 'fchub-memberships')];
        }

        $id = $this->planRepo->create($data);

        AuditLogger::logPlanChange($id, 'created', [], $data);
        do_action('fchub_memberships/plan_created', $this->planRepo->find($id));

        if (!empty($data['rules'])) {
            $this->ruleRepo->bulkCreate($id, $data['rules']);
        }

        $this->invalidateHierarchyCache();

        return $this->getFullPlan($id);
    }

    public function update(int $id, array $data): array
    {
        $existing = $this->planRepo->find($id);
        if (!$existing) {
            return ['error' => __('Plan not found.', 'fchub-memberships')];
        }

        if (!empty($data['slug']) && $data['slug'] !== $existing['slug']) {
            if ($this->planRepo->slugExists($data['slug'], $id)) {
                return ['error' => __('Plan slug already exists.', 'fchub-memberships')];
            }
        }

        // Merge incoming meta with existing to avoid wiping unrelated keys
        if (array_key_exists('meta', $data)) {
            $data['meta'] = array_merge($existing['meta'] ?? [], $data['meta'] ?? []);
        }

        // Check for circular references in includes_plan_ids
        if (isset($data['includes_plan_ids'])) {
            $cycle = $this->detectCycle($id, $data['includes_plan_ids']);
            if ($cycle) {
                return ['error' => __('Circular plan hierarchy detected.', 'fchub-memberships')];
            }
        }

        $this->planRepo->update($id, $data);

        AuditLogger::logPlanChange($id, 'updated', $existing, $data);
        do_action('fchub_memberships/plan_updated', $this->planRepo->find($id), $data);

        if (isset($data['rules'])) {
            $this->ruleRepo->syncRules($id, $data['rules']);
        }

        $this->invalidateHierarchyCache();

        return $this->getFullPlan($id);
    }

    public function delete(int $id): bool
    {
        global $wpdb;

        // Cascade: delete drip notifications for this plan's rules
        $ruleIds = array_column($this->ruleRepo->getByPlanId($id), 'id');
        if (!empty($ruleIds)) {
            $dripTable = $wpdb->prefix . 'fchub_membership_drip_notifications';
            $placeholders = implode(',', array_fill(0, count($ruleIds), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$dripTable} WHERE plan_rule_id IN ({$placeholders})",
                ...$ruleIds
            ));
        }

        // Cascade: remove plan from protection rules' plan_ids
        $protectionTable = $wpdb->prefix . 'fchub_membership_protection_rules';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, plan_ids FROM {$protectionTable} WHERE plan_ids LIKE %s", '%' . $wpdb->esc_like('"' . $id . '"') . '%'), ARRAY_A);
        foreach ($rows as $row) {
            $planIds = json_decode($row['plan_ids'] ?? '[]', true) ?: [];
            $planIds = array_values(array_filter($planIds, fn($pid) => (int) $pid !== $id));
            $wpdb->update($protectionTable, ['plan_ids' => wp_json_encode($planIds)], ['id' => $row['id']]);
        }

        $this->ruleRepo->deleteByPlanId($id);
        $result = $this->planRepo->delete($id);

        AuditLogger::logPlanChange($id, 'deleted');
        do_action('fchub_memberships/plan_deleted', $id);

        $this->invalidateHierarchyCache();
        return $result;
    }

    public function duplicate(int $id): array
    {
        $plan = $this->getFullPlan($id);
        if (isset($plan['error'])) {
            return $plan;
        }

        $newData = $plan;
        unset($newData['id'], $newData['created_at'], $newData['updated_at']);
        $newData['title'] = $plan['title'] . ' (Copy)';
        $newData['slug'] = $this->planRepo->generateUniqueSlug($newData['title']);
        $newData['status'] = 'inactive';

        return $this->create($newData);
    }

    /**
     * Get full plan data including rules.
     */
    public function getFullPlan(int $id): array
    {
        $plan = $this->planRepo->find($id);
        if (!$plan) {
            return ['error' => __('Plan not found.', 'fchub-memberships')];
        }

        $plan['rules'] = $this->ruleRepo->getByPlanId($id);
        $plan['members_count'] = $this->planRepo->getMemberCount($id);
        $plan['rules_count'] = count($plan['rules']);

        return $plan;
    }

    /**
     * Get plan options for select/dropdown fields.
     */
    public function getOptions(): array
    {
        $plans = $this->planRepo->getActivePlans();
        return array_map(function ($plan) {
            return [
                'id'    => $plan['id'],
                'label' => $plan['title'],
                'value' => (string) $plan['id'],
            ];
        }, $plans);
    }

    /**
     * Get all plans for hierarchy display.
     */
    public function getActivePlans(): array
    {
        return $this->planRepo->getActivePlans();
    }

    /**
     * Schedule a plan status change for a future date.
     */
    public function schedulePlanStatus(int $planId, string $status, string $scheduledAt): array
    {
        $plan = $this->planRepo->find($planId);
        if (!$plan) {
            return ['error' => __('Plan not found.', 'fchub-memberships')];
        }

        $this->planRepo->updateSchedule($planId, $status, $scheduledAt);
        AuditLogger::logPlanChange($planId, 'schedule_set', [], [
            'scheduled_status' => $status,
            'scheduled_at'     => $scheduledAt,
        ]);

        return $this->planRepo->find($planId);
    }

    /**
     * Clear a scheduled plan status change.
     */
    public function clearSchedule(int $planId): void
    {
        $this->planRepo->updateSchedule($planId, null, null);
        AuditLogger::logPlanChange($planId, 'schedule_cleared');
    }

    /**
     * Process all plans with pending scheduled status changes.
     * Called from cron.
     */
    public function processScheduledStatuses(): int
    {
        $plans = $this->planRepo->getDueScheduledPlans();
        $processed = 0;

        foreach ($plans as $plan) {
            $newStatus = $plan['scheduled_status'];
            $this->planRepo->update($plan['id'], ['status' => $newStatus]);
            $this->planRepo->updateSchedule($plan['id'], null, null);

            AuditLogger::logPlanChange($plan['id'], 'scheduled_status_applied', [
                'status' => $plan['status'],
            ], [
                'status' => $newStatus,
            ]);

            do_action('fchub_memberships/plan_status_scheduled_change', $this->planRepo->find($plan['id']), $plan['status']);
            $processed++;
        }

        $this->invalidateHierarchyCache();
        return $processed;
    }

    private function detectCycle(int $planId, array $includesPlanIds, int $depth = 0): bool
    {
        if ($depth > 5) {
            return true;
        }

        if (in_array($planId, $includesPlanIds, true)) {
            return true;
        }

        foreach ($includesPlanIds as $includedId) {
            $includedPlan = $this->planRepo->find((int) $includedId);
            if ($includedPlan && !empty($includedPlan['includes_plan_ids'])) {
                if ($this->detectCycle($planId, $includedPlan['includes_plan_ids'], $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function invalidateHierarchyCache(): void
    {
        delete_transient('fchub_memberships_plan_hierarchy');
    }
}
