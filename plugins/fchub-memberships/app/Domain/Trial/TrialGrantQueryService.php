<?php

namespace FChubMemberships\Domain\Trial;

defined('ABSPATH') || exit;

final class TrialGrantQueryService
{
    private \wpdb $database;
    private string $grantsTable;
    private string $plansTable;

    public function __construct(?\wpdb $database = null)
    {
        $this->database = $database ?? $GLOBALS['wpdb'];
        $this->grantsTable = \FChubMemberships\Support\CustomTableDatabase::identifierOn($this->database, $this->database->prefix . 'fchub_membership_grants');
        $this->plansTable = \FChubMemberships\Support\CustomTableDatabase::identifierOn($this->database, $this->database->prefix . 'fchub_membership_plans');
    }

    public function getDueTrialExpirations(string $now): array
    {
        return \FChubMemberships\Support\CustomTableDatabase::getResultsFrom($this->database, \FChubMemberships\Support\CustomTableDatabase::prepareOn($this->database,
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
        return \FChubMemberships\Support\CustomTableDatabase::getResultsFrom($this->database, \FChubMemberships\Support\CustomTableDatabase::prepareOn($this->database,
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
        $row = \FChubMemberships\Support\CustomTableDatabase::getRowFrom($this->database, \FChubMemberships\Support\CustomTableDatabase::prepareOn($this->database,
            "SELECT title, slug FROM {$this->plansTable} WHERE id = %d",
            $planId
        ));

        return $row ?: null;
    }

    public function markTrialExpiryNotified(int $grantId, array $meta): void
    {
        \FChubMemberships\Support\CustomTableDatabase::updateIn($this->database,
            $this->grantsTable,
            ['meta' => wp_json_encode($meta)],
            ['id' => $grantId],
            ['%s'],
            ['%d']
        );
    }
}
