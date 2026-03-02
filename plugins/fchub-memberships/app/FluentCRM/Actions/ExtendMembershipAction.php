<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

class ExtendMembershipAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fchub_extend_membership';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'title'       => __('Extend Membership Expiry', 'fchub-memberships'),
            'description' => __('Extend the expiry date of an active membership', 'fchub-memberships'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'plan_id'     => '',
                'extend_days' => '',
                'extend_mode' => 'from_current_expiry',
            ],
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Extend Membership Expiry', 'fchub-memberships'),
            'sub_title' => __('Extend the expiry date of an active membership', 'fchub-memberships'),
            'fields'    => [
                'plan_id'     => [
                    'type'        => 'select',
                    'label'       => __('Membership Plan', 'fchub-memberships'),
                    'placeholder' => __('Select Plan', 'fchub-memberships'),
                    'options'     => $this->getPlanOptions(),
                    'is_required' => true,
                ],
                'extend_days' => [
                    'type'        => 'input-number',
                    'label'       => __('Extend by (days)', 'fchub-memberships'),
                    'placeholder' => __('e.g. 30', 'fchub-memberships'),
                    'is_required' => true,
                ],
                'extend_mode' => [
                    'type'    => 'radio',
                    'label'   => __('Extend From', 'fchub-memberships'),
                    'options' => [
                        ['id' => 'from_current_expiry', 'title' => __('From current expiry date', 'fchub-memberships')],
                        ['id' => 'from_now', 'title' => __('From today', 'fchub-memberships')],
                    ],
                ],
            ],
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $planId = (int) Arr::get($sequence->settings, 'plan_id');
        $extendDays = (int) Arr::get($sequence->settings, 'extend_days');
        if (!$planId || !$extendDays) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $userId = $this->resolveUserId($subscriber);
        if (!$userId) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $grantRepo = new GrantRepository();
        $grants = $grantRepo->getByUserId($userId, ['plan_id' => $planId, 'status' => 'active']);
        if (empty($grants)) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $extendMode = Arr::get($sequence->settings, 'extend_mode', 'from_current_expiry');

        foreach ($grants as $grant) {
            if ($extendMode === 'from_current_expiry' && !empty($grant['expires_at'])) {
                $baseTime = strtotime($grant['expires_at']);
            } else {
                $baseTime = time();
            }
            $newExpiresAt = gmdate('Y-m-d H:i:s', strtotime('+' . $extendDays . ' days', $baseTime));

            $service = new AccessGrantService();
            $service->extendExpiry($userId, $planId, $newExpiresAt);
            break; // extendExpiry updates all grants for the plan
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
