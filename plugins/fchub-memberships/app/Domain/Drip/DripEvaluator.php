<?php

namespace FChubMemberships\Domain\Drip;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Domain\Plan\PlanRuleResolver;

class DripEvaluator
{
    private GrantRepository $grantRepo;
    private PlanRuleResolver $ruleResolver;

    public function __construct()
    {
        $this->grantRepo = new GrantRepository();
        $this->ruleResolver = new PlanRuleResolver();
    }

    /**
     * Check if a specific drip item is available for a user.
     */
    public function isAvailable(int $userId, string $provider, string $resourceType, string $resourceId): array
    {
        $grant = $this->grantRepo->getActiveGrant($userId, $provider, $resourceType, $resourceId);

        if (!$grant) {
            return ['available' => false, 'reason' => 'no_grant'];
        }

        if (empty($grant['drip_available_at'])) {
            return ['available' => true, 'reason' => 'immediate'];
        }

        $dripTime = strtotime($grant['drip_available_at']);
        if ($dripTime <= time()) {
            return ['available' => true, 'reason' => 'unlocked'];
        }

        return [
            'available'    => false,
            'reason'       => 'drip_locked',
            'available_at' => $grant['drip_available_at'],
            'days_left'    => max(0, (int) ceil(($dripTime - time()) / DAY_IN_SECONDS)),
        ];
    }

    /**
     * Get the full drip timeline for a user's plan.
     */
    public function getTimeline(int $userId, int $planId): array
    {
        $rules = $this->ruleResolver->resolveUniqueRules($planId);
        $grants = $this->grantRepo->getByUserId($userId, ['plan_id' => $planId, 'status' => 'active']);
        $now = time();

        // Index grants by resource key for quick lookup
        $grantIndex = [];
        foreach ($grants as $grant) {
            $key = $grant['provider'] . ':' . $grant['resource_type'] . ':' . $grant['resource_id'];
            $grantIndex[$key] = $grant;
        }

        $timeline = [];
        foreach ($rules as $rule) {
            $key = $rule['provider'] . ':' . $rule['resource_type'] . ':' . $rule['resource_id'];
            $grant = $grantIndex[$key] ?? null;

            $item = [
                'rule_id'       => $rule['id'],
                'provider'      => $rule['provider'],
                'resource_type' => $rule['resource_type'],
                'resource_id'   => $rule['resource_id'],
                'drip_type'     => $rule['drip_type'],
                'drip_delay_days' => $rule['drip_delay_days'],
                'drip_date'     => $rule['drip_date'],
                'sort_order'    => $rule['sort_order'],
                'status'        => 'locked',
                'available_at'  => null,
            ];

            if ($grant) {
                if (empty($grant['drip_available_at'])) {
                    $item['status'] = 'unlocked';
                } elseif (strtotime($grant['drip_available_at']) <= $now) {
                    $item['status'] = 'unlocked';
                    $item['available_at'] = $grant['drip_available_at'];
                } else {
                    $item['status'] = 'upcoming';
                    $item['available_at'] = $grant['drip_available_at'];
                    $item['days_left'] = max(0, (int) ceil((strtotime($grant['drip_available_at']) - $now) / DAY_IN_SECONDS));
                }
            }

            // Get resource label
            $item['label'] = $this->getResourceLabel($rule['provider'], $rule['resource_type'], $rule['resource_id']);

            $timeline[] = $item;
        }

        // Sort by: unlocked first, then by sort_order
        usort($timeline, function ($a, $b) {
            $statusOrder = ['unlocked' => 0, 'upcoming' => 1, 'locked' => 2];
            $aOrder = $statusOrder[$a['status']] ?? 3;
            $bOrder = $statusOrder[$b['status']] ?? 3;

            if ($aOrder !== $bOrder) {
                return $aOrder - $bOrder;
            }

            return $a['sort_order'] - $b['sort_order'];
        });

        return $timeline;
    }

    /**
     * Get a visual drip schedule for a plan (not user-specific).
     */
    public function getPlanDripSchedule(int $planId): array
    {
        $ruleRepo = new PlanRuleRepository();
        $rules = $ruleRepo->getByPlanId($planId);

        $schedule = [];
        foreach ($rules as $rule) {
            $day = 0;
            $label = '';

            if ($rule['drip_type'] === 'immediate') {
                $day = 0;
                $label = __('Day 0 (Immediate)', 'fchub-memberships');
            } elseif ($rule['drip_type'] === 'delayed') {
                $day = $rule['drip_delay_days'];
                $label = sprintf(__('Day %d', 'fchub-memberships'), $day);
            } elseif ($rule['drip_type'] === 'fixed_date') {
                $label = wp_date(get_option('date_format'), strtotime($rule['drip_date']));
            }

            $schedule[] = [
                'day'           => $day,
                'label'         => $label,
                'drip_type'     => $rule['drip_type'],
                'drip_date'     => $rule['drip_date'],
                'resource_label' => $this->getResourceLabel($rule['provider'], $rule['resource_type'], $rule['resource_id']),
                'resource_type' => $rule['resource_type'],
                'resource_id'   => $rule['resource_id'],
                'provider'      => $rule['provider'],
                'sort_order'    => $rule['sort_order'],
            ];
        }

        // Group by day/date
        usort($schedule, function ($a, $b) {
            if ($a['drip_type'] === 'fixed_date' && $b['drip_type'] === 'fixed_date') {
                return strcmp($a['drip_date'] ?? '', $b['drip_date'] ?? '');
            }
            return $a['day'] - $b['day'];
        });

        return $schedule;
    }

    private function getResourceLabel(string $provider, string $resourceType, string $resourceId): string
    {
        if ($resourceId === '*') {
            return sprintf(__('All %s', 'fchub-memberships'), $resourceType);
        }

        $adapters = [
            'wordpress_core' => \FChubMemberships\Adapters\WordPressContentAdapter::class,
            'learndash'      => \FChubMemberships\Adapters\LearnDashAdapter::class,
        ];

        $class = $adapters[$provider] ?? null;
        if ($class && class_exists($class)) {
            return (new $class())->getResourceLabel($resourceType, $resourceId);
        }

        return $resourceType . ' #' . $resourceId;
    }
}
