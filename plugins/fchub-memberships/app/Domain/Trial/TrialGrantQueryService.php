<?php

namespace FChubMemberships\Domain\Trial;

defined('ABSPATH') || exit;

final class TrialGrantQueryService
{
    private \wpdb $wpdb;
    private string $grantsTable;
    private string $plansTable;

    public function __construct(?\wpdb $wpdb = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->grantsTable = $this->wpdb->prefix . 'fchub_membership_grants';
        $this->plansTable = $this->wpdb->prefix . 'fchub_membership_plans';
    }

    public function getDueTrialExpirations(string $now): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, user_id, plan_id, trial_ends_at, source_id, source_ids, meta
             FROM {$this->grantsTable}
             WHERE trial_ends_at IS NOT NULL
               AND trial_ends_at <= %s
               AND status = 'active'
             ORDER BY trial_ends_at ASC
             LIMIT 100",
            $now
        ), ARRAY_A) ?: [];
    }

    public function getTrialExpiringSoon(string $now, string $cutoff): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, user_id, plan_id, trial_ends_at, meta
             FROM {$this->grantsTable}
             WHERE trial_ends_at IS NOT NULL
               AND trial_ends_at > %s
               AND trial_ends_at <= %s
               AND status = 'active'
             ORDER BY trial_ends_at ASC
             LIMIT 100",
            $now,
            $cutoff
        ), ARRAY_A) ?: [];
    }

    public function findPlanSummary(int $planId): ?object
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT title, slug FROM {$this->plansTable} WHERE id = %d",
            $planId
        ));

        return $row ?: null;
    }

    public function markTrialExpiryNotified(int $grantId, array $meta): void
    {
        $this->wpdb->update(
            $this->grantsTable,
            ['meta' => wp_json_encode($meta)],
            ['id' => $grantId],
            ['%s'],
            ['%d']
        );
    }
}
