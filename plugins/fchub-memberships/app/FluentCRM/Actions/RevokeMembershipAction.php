<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Storage\PlanRepository;

class RevokeMembershipAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fchub_revoke_membership';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'title'       => __('Revoke Membership Plan', 'fchub-memberships'),
            'description' => __('Revoke a membership plan from the contact', 'fchub-memberships'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'plan_id'          => '',
                'reason'           => 'Removed by automation',
                'use_grace_period' => 'no',
            ],
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Revoke Membership Plan', 'fchub-memberships'),
            'sub_title' => __('Revoke a membership plan from the contact', 'fchub-memberships'),
            'fields'    => [
                'plan_id'          => [
                    'type'        => 'select',
                    'label'       => __('Membership Plan', 'fchub-memberships'),
                    'placeholder' => __('Select Plan', 'fchub-memberships'),
                    'options'     => $this->getPlanOptions(),
                    'is_required' => true,
                ],
                'reason'           => [
                    'type'        => 'input-text',
                    'label'       => __('Reason', 'fchub-memberships'),
                    'placeholder' => __('Reason for revoking', 'fchub-memberships'),
                ],
                'use_grace_period' => [
                    'type'        => 'yes_no_check',
                    'label'       => __('Use Grace Period', 'fchub-memberships'),
                    'check_label' => __('Apply the plan\'s grace period before revoking', 'fchub-memberships'),
                ],
            ],
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $planId = (int) Arr::get($sequence->settings, 'plan_id');
        if (!$planId) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $userId = $this->resolveUserId($subscriber);
        if (!$userId) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $reason = Arr::get($sequence->settings, 'reason', 'Removed by automation');
        $useGrace = Arr::get($sequence->settings, 'use_grace_period', 'no') === 'yes';

        $context = [
            'source_type' => 'automation',
            'source_id'   => $sequence->id,
            'reason'      => $reason,
        ];

        if (!$useGrace) {
            $context['grace_period_days'] = 0;
        }

        $service = new AccessGrantService();
        $service->revokePlan($userId, $planId, $context);
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
