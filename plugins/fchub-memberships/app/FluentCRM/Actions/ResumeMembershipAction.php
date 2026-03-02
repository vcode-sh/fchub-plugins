<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

class ResumeMembershipAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fchub_resume_membership';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'title'       => __('Resume Membership', 'fchub-memberships'),
            'description' => __('Resume paused membership grants for the contact', 'fchub-memberships'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'plan_id' => '',
            ],
        ];
    }

    public function getBlockFields()
    {
        $planOptions = $this->getPlanOptions();
        array_unshift($planOptions, ['id' => '', 'title' => __('All paused plans', 'fchub-memberships')]);

        return [
            'title'     => __('Resume Membership', 'fchub-memberships'),
            'sub_title' => __('Resume paused membership grants for the contact', 'fchub-memberships'),
            'fields'    => [
                'plan_id' => [
                    'type'        => 'select',
                    'label'       => __('Membership Plan', 'fchub-memberships'),
                    'placeholder' => __('All paused plans', 'fchub-memberships'),
                    'options'     => $planOptions,
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

        $grantRepo = new GrantRepository();

        if ($planId) {
            $grants = $grantRepo->getByUserId($userId, ['status' => 'paused', 'plan_id' => $planId]);
        } else {
            $grants = $grantRepo->getPausedGrants($userId);
        }

        if (empty($grants)) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $service = new AccessGrantService();
        foreach ($grants as $grant) {
            $service->resumeGrant($grant['id']);
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
