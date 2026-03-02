<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

class ChangeMembershipPlanAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fchub_change_membership_plan';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'title'       => __('Change Membership Plan', 'fchub-memberships'),
            'description' => __('Switch the contact from one membership plan to another', 'fchub-memberships'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'from_plan_id' => '',
                'to_plan_id'   => '',
                'keep_expiry'  => 'no',
            ],
        ];
    }

    public function getBlockFields()
    {
        $planOptions = $this->getPlanOptions();
        $fromOptions = array_merge(
            [['id' => '', 'title' => __('Any active plan', 'fchub-memberships')]],
            $planOptions
        );

        return [
            'title'     => __('Change Membership Plan', 'fchub-memberships'),
            'sub_title' => __('Switch the contact from one plan to another', 'fchub-memberships'),
            'fields'    => [
                'from_plan_id' => [
                    'type'        => 'select',
                    'label'       => __('From Plan', 'fchub-memberships'),
                    'placeholder' => __('Any active plan', 'fchub-memberships'),
                    'options'     => $fromOptions,
                ],
                'to_plan_id'   => [
                    'type'        => 'select',
                    'label'       => __('To Plan', 'fchub-memberships'),
                    'placeholder' => __('Select Plan', 'fchub-memberships'),
                    'options'     => $planOptions,
                    'is_required' => true,
                ],
                'keep_expiry'  => [
                    'type'        => 'yes_no_check',
                    'label'       => __('Keep Expiry', 'fchub-memberships'),
                    'check_label' => __('Transfer remaining time to the new plan', 'fchub-memberships'),
                ],
            ],
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $toPlanId = (int) Arr::get($sequence->settings, 'to_plan_id');
        if (!$toPlanId) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $userId = $this->resolveUserId($subscriber);
        if (!$userId) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $fromPlanId = (int) Arr::get($sequence->settings, 'from_plan_id');
        $keepExpiry = Arr::get($sequence->settings, 'keep_expiry', 'no') === 'yes';

        $grantRepo = new GrantRepository();
        $service = new AccessGrantService();

        // Find active grants to revoke
        $filters = ['status' => 'active'];
        if ($fromPlanId) {
            $filters['plan_id'] = $fromPlanId;
        }
        $existingGrants = $grantRepo->getByUserId($userId, $filters);

        if (empty($existingGrants)) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        // Capture expiry from first grant before revoking
        $existingExpiry = null;
        if ($keepExpiry) {
            $existingExpiry = $existingGrants[0]['expires_at'] ?? null;
        }

        // Revoke the old plan
        $revokePlanId = $fromPlanId ?: ($existingGrants[0]['plan_id'] ?? 0);
        if ($revokePlanId) {
            $service->revokePlan($userId, $revokePlanId, [
                'source_type'      => 'automation',
                'source_id'        => $sequence->id,
                'reason'           => 'Changed to plan #' . $toPlanId,
                'grace_period_days' => 0,
            ]);
        }

        // Grant the new plan
        $context = [
            'source_type' => 'automation',
            'source_id'   => $sequence->id,
        ];
        if ($keepExpiry && $existingExpiry) {
            $context['expires_at'] = $existingExpiry;
        }

        $service->grantPlan($userId, $toPlanId, $context);
    }

    private function resolveUserId($subscriber): ?int
    {
        if ($subscriber->user_id) {
            return (int) $subscriber->user_id;
        }
        $user = get_user_by('email', $subscriber->email);
        return $user ? $user->ID : null;
    }

    private function getPlanOptions(): array
    {
        $plans = (new PlanRepository())->all();
        $options = [];
        foreach ($plans as $plan) {
            $options[] = ['id' => (string) $plan['id'], 'title' => $plan['title']];
        }
        return $options;
    }
}
