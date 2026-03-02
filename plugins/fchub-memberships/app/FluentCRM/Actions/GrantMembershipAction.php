<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Storage\PlanRepository;

class GrantMembershipAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fchub_grant_membership';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'title'       => __('Grant Membership Plan', 'fchub-memberships'),
            'description' => __('Grant a membership plan to the contact', 'fchub-memberships'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'plan_id'           => '',
                'validity_mode'     => 'plan_default',
                'duration_days'     => '',
                'custom_expires_at' => '',
            ],
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Grant Membership Plan', 'fchub-memberships'),
            'sub_title' => __('Grant a membership plan to the contact', 'fchub-memberships'),
            'fields'    => [
                'plan_id'           => [
                    'type'        => 'select',
                    'label'       => __('Membership Plan', 'fchub-memberships'),
                    'placeholder' => __('Select Plan', 'fchub-memberships'),
                    'options'     => $this->getPlanOptions(),
                    'is_required' => true,
                ],
                'validity_mode'     => [
                    'type'    => 'radio',
                    'label'   => __('Validity Mode', 'fchub-memberships'),
                    'options' => [
                        ['id' => 'plan_default', 'title' => __('Use plan default duration', 'fchub-memberships')],
                        ['id' => 'fixed_days', 'title' => __('Fixed number of days', 'fchub-memberships')],
                        ['id' => 'custom_date', 'title' => __('Custom expiry date', 'fchub-memberships')],
                    ],
                ],
                'duration_days'     => [
                    'type'        => 'input-number',
                    'label'       => __('Duration (days)', 'fchub-memberships'),
                    'placeholder' => __('e.g. 30', 'fchub-memberships'),
                    'dependency'  => [
                        'depends_on' => 'validity_mode',
                        'value'      => 'fixed_days',
                        'operator'   => '=',
                    ],
                ],
                'custom_expires_at' => [
                    'type'       => 'date_time',
                    'label'      => __('Custom Expiry Date', 'fchub-memberships'),
                    'dependency' => [
                        'depends_on' => 'validity_mode',
                        'value'      => 'custom_date',
                        'operator'   => '=',
                    ],
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

        $validityMode = Arr::get($sequence->settings, 'validity_mode', 'plan_default');
        $expiresAt = null;

        if ($validityMode === 'fixed_days') {
            $days = (int) Arr::get($sequence->settings, 'duration_days', 0);
            if ($days > 0) {
                $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
            }
        } elseif ($validityMode === 'custom_date') {
            $expiresAt = Arr::get($sequence->settings, 'custom_expires_at');
        }

        $context = [
            'source_type' => 'automation',
            'source_id'   => $sequence->id,
        ];

        if ($expiresAt) {
            $context['expires_at'] = $expiresAt;
        }

        $service = new AccessGrantService();
        $service->grantPlan($userId, $planId, $context);
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
