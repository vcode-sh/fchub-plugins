<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

class PauseMembershipAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fchub_pause_membership';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'title'       => __('Pause Membership', 'fchub-memberships'),
            'description' => __('Pause active membership grants for the contact', 'fchub-memberships'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'plan_id' => '',
                'reason'  => '',
            ],
        ];
    }

    public function getBlockFields()
    {
        $planOptions = $this->getPlanOptions();
        array_unshift($planOptions, ['id' => '', 'title' => __('All active plans', 'fchub-memberships')]);

        return [
            'title'     => __('Pause Membership', 'fchub-memberships'),
            'sub_title' => __('Pause active membership grants for the contact', 'fchub-memberships'),
            'fields'    => [
                'plan_id' => [
                    'type'        => 'select',
                    'label'       => __('Membership Plan', 'fchub-memberships'),
                    'placeholder' => __('All active plans', 'fchub-memberships'),
                    'options'     => $planOptions,
                ],
                'reason'  => [
                    'type'        => 'input-text',
                    'label'       => __('Reason', 'fchub-memberships'),
                    'placeholder' => __('Reason for pausing', 'fchub-memberships'),
                ],
            ],
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $userId = $this->resolveUserId($subscriber);
        if (!$userId) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $planId = (int) Arr::get($sequence->settings, 'plan_id');
        $reason = Arr::get($sequence->settings, 'reason', '');

        $grantRepo = new GrantRepository();
        $filters = ['status' => 'active'];
        if ($planId) {
            $filters['plan_id'] = $planId;
        }

        $grants = $grantRepo->getByUserId($userId, $filters);
        if (empty($grants)) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $service = new AccessGrantService();
        foreach ($grants as $grant) {
            $service->pauseGrant($grant['id'], $reason);
        }
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
