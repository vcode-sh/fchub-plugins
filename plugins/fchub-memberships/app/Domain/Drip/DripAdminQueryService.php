<?php

namespace FChubMemberships\Domain\Drip;

use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;

defined('ABSPATH') || exit;

final class DripAdminQueryService
{
    private DripScheduleRepository $drips;
    private PlanRuleRepository $rules;
    private PlanRepository $plans;
    private \wpdb $wpdb;
    private string $table;

    public function __construct(
        ?DripScheduleRepository $drips = null,
        ?PlanRuleRepository $rules = null,
        ?PlanRepository $plans = null,
        ?\wpdb $wpdb = null
    ) {
        $this->drips = $drips ?? new DripScheduleRepository();
        $this->rules = $rules ?? new PlanRuleRepository();
        $this->plans = $plans ?? new PlanRepository();
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->table = $this->wpdb->prefix . 'fchub_membership_drip_notifications';
    }

    public function overview(): array
    {
        $plans = $this->plans->getActivePlans();
        $totalRules = 0;
        foreach ($plans as $plan) {
            $totalRules += count($this->rules->getDripRules($plan['id']));
        }

        $sentToday = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'sent' AND DATE(sent_at) = %s",
            current_time('Y-m-d')
        ));

        $failed = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'failed'"
        );

        return [
            'total_rules' => $totalRules,
            'pending' => $this->drips->countPending(),
            'sent_today' => $sentToday,
            'failed' => $failed,
        ];
    }

    public function notificationsTotal(array $filters): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode(' AND ', $where);

        return $params
            ? (int) $this->wpdb->get_var($this->wpdb->prepare($sql, ...$params))
            : (int) $this->wpdb->get_var($sql);
    }
}
