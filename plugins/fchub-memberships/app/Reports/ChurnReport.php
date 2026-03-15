<?php

namespace FChubMemberships\Reports;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\GrantRepository;

class ChurnReport
{
    private GrantRepository $grantRepo;
    private string $grantsTable;
    private string $statsTable;

    public function __construct()
    {
        global $wpdb;
        $this->grantRepo = new GrantRepository();
        $this->grantsTable = $wpdb->prefix . 'fchub_membership_grants';
        $this->statsTable = $wpdb->prefix . 'fchub_membership_stats_daily';
    }

    /**
     * Get churn rate for a given period.
     * Churn = churned members / active members at period start * 100.
     *
     * @param string $period E.g. '30d', '90d', '12m'.
     * @return float Churn rate as a percentage.
     */
    public function getChurnRate(string $period = '30d', ?string $from = null, ?string $to = null): float
    {
        $range = $this->resolveRange($period, $from, $to);

        $churnedCount = $this->grantRepo->countChurnedMembers($range['from'], $range['to']);

        // Active at period start = current active + churned during period - new during period
        $currentActive = $this->grantRepo->countActiveMembers(null, $range['to']);
        $newDuringPeriod = $this->grantRepo->countNewMembers($range['from'], $range['to']);
        $activeAtStart = $currentActive + $churnedCount - $newDuringPeriod;

        if ($activeAtStart <= 0) {
            return 0.0;
        }

        return round(($churnedCount / $activeAtStart) * 100, 2);
    }

    /**
     * Get monthly churn rates over time.
     *
     * @param string $period E.g. '12m', '6m'.
     * @return array Array of ['month' => string, 'churn_rate' => float, 'churned' => int, 'active_start' => int].
     */
    public function getChurnOverTime(string $period = '12m', ?string $from = null, ?string $to = null): array
    {
        global $wpdb;

        $range = $this->resolveRange($period, $from, $to);
        $results = [];

        // Use daily stats table for historical data
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE_FORMAT(stat_date, '%%Y-%%m') AS month,
                MAX(active_count) AS peak_active,
                SUM(churned_count) AS total_churned
             FROM {$this->statsTable}
             WHERE plan_id = 0
               AND stat_date >= %s
               AND stat_date <= %s
             GROUP BY DATE_FORMAT(stat_date, '%%Y-%%m')
             ORDER BY month ASC",
            $range['from'],
            $range['to']
        ), ARRAY_A);

        foreach ($rows ?: [] as $row) {
            $activeStart = (int) $row['peak_active'];
            $churned = (int) $row['total_churned'];
            $churnRate = $activeStart > 0 ? round(($churned / $activeStart) * 100, 2) : 0.0;

            $results[] = [
                'month'        => $row['month'],
                'churn_rate'   => $churnRate,
                'churned'      => $churned,
                'active_start' => $activeStart,
            ];
        }

        return $results;
    }

    /**
     * Get retention cohort table data.
     * Groups members by their join month and tracks what percentage remain active.
     *
     * @param int $months Number of months to include.
     * @return array Cohort data: ['cohort' => string, 'size' => int, 'retention' => [month_0 => float, ...]].
     */
    public function getRetentionCohort(int $months = 6): array
    {
        global $wpdb;

        $now = current_time('mysql');
        $cohorts = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $cohortStart = gmdate('Y-m-01', strtotime("-{$i} months"));
            $cohortEnd = gmdate('Y-m-t 23:59:59', strtotime($cohortStart));
            $cohortLabel = gmdate('Y-m', strtotime($cohortStart));

            // Get users who first received a grant in this month
            $cohortUserIds = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$this->grantsTable}
                 WHERE created_at >= %s AND created_at <= %s
                   AND user_id NOT IN (
                       SELECT DISTINCT user_id FROM {$this->grantsTable}
                       WHERE created_at < %s
                   )",
                $cohortStart,
                $cohortEnd,
                $cohortStart
            ));

            $cohortSize = count($cohortUserIds);
            if ($cohortSize === 0) {
                $cohorts[] = [
                    'cohort'    => $cohortLabel,
                    'size'      => 0,
                    'retention' => [],
                ];
                continue;
            }

            $retention = [];

            // Check retention for each subsequent month
            for ($m = 0; $m <= $i; $m++) {
                $checkStart = gmdate('Y-m-01', strtotime("{$cohortStart} +{$m} months"));
                $checkEnd = gmdate('Y-m-t 23:59:59', strtotime($checkStart));

                $placeholders = implode(',', array_fill(0, $cohortSize, '%d'));
                $params = array_merge($cohortUserIds, [$checkStart, $checkEnd]);

                $retainedCount = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT user_id) FROM {$this->grantsTable}
                     WHERE user_id IN ({$placeholders})
                       AND status = 'active'
                       AND created_at <= %s
                       AND (expires_at IS NULL OR expires_at > %s)",
                    ...$params
                ));

                $retention[$m] = round(($retainedCount / $cohortSize) * 100, 1);
            }

            $cohorts[] = [
                'cohort'    => $cohortLabel,
                'size'      => $cohortSize,
                'retention' => $retention,
            ];
        }

        return $cohorts;
    }

    /**
     * Parse a period string into from/to date strings.
     */
    private function parsePeriod(string $period): array
    {
        $to = current_time('mysql');
        $amount = (int) substr($period, 0, -1);
        $unit = substr($period, -1);

        if ($unit === 'm') {
            $from = gmdate('Y-m-d H:i:s', strtotime("-{$amount} months"));
        } else {
            $from = gmdate('Y-m-d H:i:s', strtotime("-{$amount} days"));
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @return array{from: string, to: string}
     */
    private function resolveRange(string $period, ?string $from, ?string $to): array
    {
        if ($from !== null && $to !== null && $from !== '' && $to !== '') {
            return [
                'from' => gmdate('Y-m-d 00:00:00', strtotime($from)),
                'to' => gmdate('Y-m-d 23:59:59', strtotime($to)),
            ];
        }

        return $this->parsePeriod($period);
    }
}
