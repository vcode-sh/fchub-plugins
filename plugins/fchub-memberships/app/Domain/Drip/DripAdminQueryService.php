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
    private \wpdb $database;
    private string $table;

    public function __construct(
        ?DripScheduleRepository $drips = null,
        ?PlanRuleRepository $rules = null,
        ?PlanRepository $plans = null,
        ?\wpdb $database = null
    ) {
        $this->drips = $drips ?? new DripScheduleRepository();
        $this->rules = $rules ?? new PlanRuleRepository();
        $this->plans = $plans ?? new PlanRepository();
        $this->database = $database ?? $GLOBALS['wpdb'];
        $this->table = \FChubMemberships\Support\CustomTableDatabase::identifierOn($this->database, $this->database->prefix . 'fchub_membership_drip_notifications');
    }

    public function overview(): array
    {
        $plans = $this->plans->getActivePlans();
        $totalRules = 0;
        foreach ($plans as $plan) {
            $totalRules += count($this->rules->getDripRules($plan['id']));
        }

        $sentToday = (int) \FChubMemberships\Support\CustomTableDatabase::getVarFrom($this->database, \FChubMemberships\Support\CustomTableDatabase::prepareOn($this->database,
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'sent' AND DATE(sent_at) = %s",
            current_time('Y-m-d')
        ));

        $failed = (int) \FChubMemberships\Support\CustomTableDatabase::getVarFrom($this->database,
            \FChubMemberships\Support\CustomTableDatabase::prepareOn(
                $this->database,
                "SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
                'failed',
            )
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
        $where = ['1=%d'];
        $params = [1];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        if (!empty($filters['date'])) {
            $where[] = 'notify_at >= %s AND notify_at <= %s';
            $params[] = $filters['date'] . ' 00:00:00';
            $params[] = $filters['date'] . ' 23:59:59';
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode(' AND ', $where);

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVarFrom(
            $this->database,
            \FChubMemberships\Support\CustomTableDatabase::prepareOn($this->database, $sql, ...$params),
        );
    }
}
