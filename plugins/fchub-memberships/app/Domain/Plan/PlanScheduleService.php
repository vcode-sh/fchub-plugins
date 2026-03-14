<?php

namespace FChubMemberships\Domain\Plan;

use FChubMemberships\Support\PlanStatus;

defined('ABSPATH') || exit;

final class PlanScheduleService
{
    private PlanService $plans;

    public function __construct(?PlanService $plans = null)
    {
        $this->plans = $plans ?? new PlanService();
    }

    public function save(int $planId, ?string $scheduledStatus, ?string $scheduledAt): array
    {
        $plan = $this->plans->find($planId);
        if (!$plan) {
            return ['error' => __('Plan not found.', 'fchub-memberships'), 'status' => 404];
        }

        if (empty($scheduledStatus) || empty($scheduledAt)) {
            $this->plans->clearSchedule($planId);

            return [
                'data' => $this->plans->find($planId),
                'message' => __('Schedule cleared.', 'fchub-memberships'),
            ];
        }

        $normalizedStatus = PlanStatus::normalizeNullable($scheduledStatus);
        if ($normalizedStatus === null) {
            return ['error' => __('Invalid scheduled status.', 'fchub-memberships'), 'status' => 422];
        }

        $result = $this->plans->schedulePlanStatus($planId, $normalizedStatus, $scheduledAt);
        if (isset($result['error'])) {
            return ['error' => $result['error'], 'status' => 422];
        }

        return [
            'data' => $result,
            'message' => __('Status change scheduled.', 'fchub-memberships'),
        ];
    }
}
