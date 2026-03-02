<?php

namespace FChubMemberships\FluentCRM\Helpers;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\FunnelHelper;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

class MembershipFunnelHelper
{
    /**
     * Prepare FluentCRM subscriber data from a WordPress user ID.
     *
     * @return array|null Subscriber data array or null if user not found
     */
    public static function prepareSubscriberFromUserId(int $userId): ?array
    {
        $user = get_user_by('ID', $userId);
        if (!$user) {
            return null;
        }

        return FunnelHelper::prepareUserData($user);
    }

    /**
     * Get all plan options for select fields.
     *
     * @return array<array{id: string, title: string}>
     */
    public static function getPlanOptions(): array
    {
        $planRepo = new PlanRepository();
        $plans = $planRepo->getActivePlans();

        return array_map(function ($plan) {
            return [
                'id'    => (string) $plan['id'],
                'title' => $plan['title'],
            ];
        }, $plans);
    }

    /**
     * Check if a subscriber should be processed for this funnel.
     *
     * Handles update_type (skip if exists) and run_multiple (allow re-entry) logic.
     */
    public static function isProcessable($funnel, $conditions, string $email, ?int $sourceRefId = null): bool
    {
        $updateType = $conditions['update_type'] ?? 'update';
        $runMultiple = $conditions['run_multiple'] ?? 'no';

        $subscriber = FunnelHelper::getSubscriber($email);

        if ($updateType === 'skip_all_if_exist' && $subscriber) {
            return false;
        }

        if ($subscriber && $runMultiple !== 'yes') {
            if (FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all active grants for a user.
     *
     * @return array
     */
    public static function getUserActiveGrants(int $userId): array
    {
        $grantRepo = new GrantRepository();
        return $grantRepo->getByUserId($userId, ['status' => 'active']);
    }

    /**
     * Get a user's grant for a specific plan.
     */
    public static function getUserGrantForPlan(int $userId, int $planId): ?array
    {
        $grantRepo = new GrantRepository();
        $grants = $grantRepo->getByUserId($userId, ['plan_id' => $planId, 'status' => 'active']);
        return !empty($grants) ? $grants[0] : null;
    }

    /**
     * Check if a plan ID matches a condition's plan_ids array.
     *
     * Empty conditionPlanIds means "all plans match".
     */
    public static function matchesPlanCondition(int $planId, array $conditionPlanIds): bool
    {
        if (empty($conditionPlanIds)) {
            return true;
        }

        return in_array($planId, array_map('intval', $conditionPlanIds), true);
    }

    /**
     * Standard plan_ids condition field definition for funnel editor.
     */
    public static function getPlanIdsConditionField(): array
    {
        return [
            'type'        => 'multi-select',
            'is_multiple' => true,
            'label'       => __('Target Membership Plans', 'fchub-memberships'),
            'help'        => __('Select which membership plans will trigger this automation', 'fchub-memberships'),
            'placeholder' => __('Select Plans', 'fchub-memberships'),
            'options'     => self::getPlanOptions(),
            'inline_help' => __('Leave blank to run for all plans', 'fchub-memberships'),
        ];
    }

    /**
     * Standard run_multiple condition field definition for funnel editor.
     */
    public static function getRunMultipleConditionField(): array
    {
        return [
            'type'        => 'radio',
            'label'       => __('Restart Automation?', 'fchub-memberships'),
            'help'        => __('Allow this automation to restart if it has already run for the contact', 'fchub-memberships'),
            'options'     => [
                [
                    'id'    => 'no',
                    'title' => __('No, skip if already in funnel', 'fchub-memberships'),
                ],
                [
                    'id'    => 'yes',
                    'title' => __('Yes, restart the automation', 'fchub-memberships'),
                ],
            ],
        ];
    }
}
