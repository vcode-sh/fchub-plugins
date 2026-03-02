<?php

namespace FChubMemberships\Reports;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Storage\DripScheduleRepository;

class ContentPopularityReport
{
    private PlanRepository $planRepo;
    private PlanRuleRepository $ruleRepo;
    private DripScheduleRepository $dripRepo;
    private string $grantsTable;
    private string $rulesTable;
    private string $plansTable;
    private string $dripTable;

    public function __construct()
    {
        global $wpdb;
        $this->planRepo = new PlanRepository();
        $this->ruleRepo = new PlanRuleRepository();
        $this->dripRepo = new DripScheduleRepository();
        $this->grantsTable = $wpdb->prefix . 'fchub_membership_grants';
        $this->rulesTable = $wpdb->prefix . 'fchub_membership_plan_rules';
        $this->plansTable = $wpdb->prefix . 'fchub_membership_plans';
        $this->dripTable = $wpdb->prefix . 'fchub_membership_drip_notifications';
    }

    /**
     * Get most accessed content: resources ranked by unique active member count.
     *
     * @param int $limit Maximum number of results.
     * @return array Array of ['provider' => string, 'resource_type' => string, 'resource_id' => string, 'title' => string, 'member_count' => int].
     */
    public function getMostAccessedContent(int $limit = 20): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                g.provider,
                g.resource_type,
                g.resource_id,
                COUNT(DISTINCT g.user_id) AS member_count
             FROM {$this->grantsTable} g
             WHERE g.status = 'active'
               AND (g.starts_at IS NULL OR g.starts_at <= %s)
               AND (g.expires_at IS NULL OR g.expires_at > %s)
               AND (g.drip_available_at IS NULL OR g.drip_available_at <= %s)
             GROUP BY g.provider, g.resource_type, g.resource_id
             ORDER BY member_count DESC
             LIMIT %d",
            $now,
            $now,
            $now,
            $limit
        ), ARRAY_A);

        return array_map(function ($row) {
            return [
                'provider'      => $row['provider'],
                'resource_type' => $row['resource_type'],
                'resource_id'   => $row['resource_id'],
                'title'         => $this->getResourceTitle($row['resource_type'], $row['resource_id']),
                'member_count'  => (int) $row['member_count'],
            ];
        }, $rows ?: []);
    }

    /**
     * Get least accessed content: protected resources with fewest members.
     *
     * @param int $limit Maximum number of results.
     * @return array Array of ['provider' => string, 'resource_type' => string, 'resource_id' => string, 'title' => string, 'member_count' => int].
     */
    public function getLeastAccessedContent(int $limit = 20): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                r.provider,
                r.resource_type,
                r.resource_id,
                COUNT(DISTINCT g.user_id) AS member_count
             FROM {$this->rulesTable} r
             LEFT JOIN {$this->grantsTable} g
                ON r.provider = g.provider
               AND r.resource_type = g.resource_type
               AND r.resource_id = g.resource_id
               AND g.status = 'active'
               AND (g.starts_at IS NULL OR g.starts_at <= %s)
               AND (g.expires_at IS NULL OR g.expires_at > %s)
             WHERE r.resource_id != '*'
             GROUP BY r.provider, r.resource_type, r.resource_id
             ORDER BY member_count ASC
             LIMIT %d",
            $now,
            $now,
            $limit
        ), ARRAY_A);

        return array_map(function ($row) {
            return [
                'provider'      => $row['provider'],
                'resource_type' => $row['resource_type'],
                'resource_id'   => $row['resource_id'],
                'title'         => $this->getResourceTitle($row['resource_type'], $row['resource_id']),
                'member_count'  => (int) $row['member_count'],
            ];
        }, $rows ?: []);
    }

    /**
     * Get drip completion rates per plan.
     * Returns the percentage of members who have unlocked all drip items.
     *
     * @return array Array of ['plan_id' => int, 'plan_title' => string, 'total_drip_items' => int, 'members_completed' => int, 'total_members' => int, 'completion_rate' => float].
     */
    public function getDripCompletionRates(): array
    {
        global $wpdb;
        $now = current_time('mysql');

        // Get plans that have drip rules
        $plansWithDrip = $wpdb->get_results(
            "SELECT r.plan_id, p.title AS plan_title, COUNT(*) AS drip_count
             FROM {$this->rulesTable} r
             LEFT JOIN {$this->plansTable} p ON r.plan_id = p.id
             WHERE r.drip_type != 'immediate'
             GROUP BY r.plan_id, p.title",
            ARRAY_A
        );

        $results = [];

        foreach ($plansWithDrip ?: [] as $planRow) {
            $planId = (int) $planRow['plan_id'];
            $totalDripItems = (int) $planRow['drip_count'];

            // Count users who have all drip items unlocked (drip_available_at in the past or null)
            $totalMembers = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$this->grantsTable}
                 WHERE plan_id = %d AND status = 'active'",
                $planId
            ));

            if ($totalMembers === 0) {
                $results[] = [
                    'plan_id'           => $planId,
                    'plan_title'        => $planRow['plan_title'] ?? __('(Deleted Plan)', 'fchub-memberships'),
                    'total_drip_items'  => $totalDripItems,
                    'members_completed' => 0,
                    'total_members'     => 0,
                    'completion_rate'   => 0.0,
                ];
                continue;
            }

            // Members who have unlocked all drip content for this plan
            $membersCompleted = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT user_id
                    FROM {$this->grantsTable}
                    WHERE plan_id = %d
                      AND status = 'active'
                      AND (drip_available_at IS NULL OR drip_available_at <= %s)
                    GROUP BY user_id
                    HAVING COUNT(*) >= %d
                ) AS completed",
                $planId,
                $now,
                $totalDripItems
            ));

            $completionRate = round(($membersCompleted / $totalMembers) * 100, 1);

            $results[] = [
                'plan_id'           => $planId,
                'plan_title'        => $planRow['plan_title'] ?? __('(Deleted Plan)', 'fchub-memberships'),
                'total_drip_items'  => $totalDripItems,
                'members_completed' => $membersCompleted,
                'total_members'     => $totalMembers,
                'completion_rate'   => $completionRate,
            ];
        }

        return $results;
    }

    /**
     * Get content that appears in multiple plans.
     *
     * @return array Array of ['provider' => string, 'resource_type' => string, 'resource_id' => string, 'title' => string, 'plan_count' => int, 'plan_titles' => string[]].
     */
    public function getContentByPlanOverlap(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT
                r.provider,
                r.resource_type,
                r.resource_id,
                COUNT(DISTINCT r.plan_id) AS plan_count,
                GROUP_CONCAT(DISTINCT p.title SEPARATOR '||') AS plan_titles
             FROM {$this->rulesTable} r
             LEFT JOIN {$this->plansTable} p ON r.plan_id = p.id
             WHERE r.resource_id != '*'
             GROUP BY r.provider, r.resource_type, r.resource_id
             HAVING plan_count > 1
             ORDER BY plan_count DESC",
            ARRAY_A
        );

        return array_map(function ($row) {
            return [
                'provider'      => $row['provider'],
                'resource_type' => $row['resource_type'],
                'resource_id'   => $row['resource_id'],
                'title'         => $this->getResourceTitle($row['resource_type'], $row['resource_id']),
                'plan_count'    => (int) $row['plan_count'],
                'plan_titles'   => array_filter(explode('||', $row['plan_titles'] ?? '')),
            ];
        }, $rows ?: []);
    }

    /**
     * Get a human-readable title for a resource.
     */
    private function getResourceTitle(string $resourceType, string $resourceId): string
    {
        if ($resourceId === '0' || $resourceId === '*') {
            return sprintf('All %ss', $resourceType);
        }

        if (in_array($resourceType, ['post', 'page', 'lesson', 'course'], true)) {
            $title = get_the_title((int) $resourceId);
            return $title ?: sprintf('#%s (%s)', $resourceId, $resourceType);
        }

        if ($resourceType === 'category' || $resourceType === 'post_tag') {
            $term = get_term((int) $resourceId, $resourceType);
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
        }

        return sprintf('#%s (%s)', $resourceId, $resourceType);
    }
}
