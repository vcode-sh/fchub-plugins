<?php

namespace FChubMemberships\FluentCRM\Triggers;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Helpers\MembershipFunnelHelper;

final class MembershipPlanChangedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/plan_changed';
        $this->priority = 20;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger(): array
    {
        return [
            'category' => __('FCHub Memberships', 'fchub-memberships'),
            'label' => __('Membership Plan Changed', 'fchub-memberships'),
            'description' => __('Starts when a completed membership plan transition is recorded.', 'fchub-memberships'),
        ];
    }

    public function getFunnelSettingsDefaults(): array
    {
        return ['subscription_status' => 'subscribed'];
    }

    public function getSettingsFields($funnel): array
    {
        return [
            'title' => __('Membership Plan Changed', 'fchub-memberships'),
            'fields' => [
                'subscription_status' => [
                    'type' => 'option_selectors',
                    'option_key' => 'editable_statuses',
                    'is_multiple' => false,
                    'label' => __('Subscription Status', 'fchub-memberships'),
                ],
            ],
        ];
    }

    public function getFunnelConditionDefaults($funnel): array
    {
        return ['from_plan_ids' => [], 'to_plan_ids' => [], 'change_types' => [], 'run_multiple' => 'yes'];
    }

    public function getConditionFields($funnel): array
    {
        $plans = MembershipFunnelHelper::getPlanOptions();
        return [
            'from_plan_ids' => ['type' => 'multi-select', 'is_multiple' => true, 'label' => __('Source Plans', 'fchub-memberships'), 'options' => $plans],
            'to_plan_ids' => ['type' => 'multi-select', 'is_multiple' => true, 'label' => __('Target Plans', 'fchub-memberships'), 'options' => $plans],
            'change_types' => ['type' => 'multi-select', 'is_multiple' => true, 'label' => __('Transition Types', 'fchub-memberships'), 'options' => [
                ['id' => 'exclusive_replacement', 'title' => __('Exclusive replacement', 'fchub-memberships')],
                ['id' => 'level_upgrade', 'title' => __('Level upgrade', 'fchub-memberships')],
                ['id' => 'automation_change', 'title' => __('Automation change', 'fchub-memberships')],
            ]],
            'run_multiple' => ['type' => 'yes_no_check', 'label' => '', 'check_label' => __('Restart automation for repeat changes', 'fchub-memberships')],
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $change = $originalArgs[0] ?? [];
        $user = get_user_by('ID', (int) ($change['user_id'] ?? 0));
        if (!$user || !$this->matches($funnel->conditions ?? [], $change)) {
            return false;
        }
        $subscriber = FunnelHelper::getSubscriber($user->user_email);
        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            if (Arr::get($funnel->conditions, 'run_multiple', 'yes') !== 'yes') {
                return false;
            }
            FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
        }
        $data = wp_parse_args(FunnelHelper::prepareUserData($user), $funnel->settings);
        $data['status'] = $data['subscription_status'];
        unset($data['subscription_status']);
        (new FunnelProcessor())->startFunnelSequence($funnel, $data, ['source_trigger_name' => $this->triggerName, 'source_ref_id' => (int) $change['to_plan_id']]);
    }

    private function matches(array $conditions, array $change): bool
    {
        $from = Arr::get($conditions, 'from_plan_ids', []);
        if ($from && !array_intersect(array_map('intval', $from), $change['from_plan_ids'] ?? [])) return false;
        $to = Arr::get($conditions, 'to_plan_ids', []);
        $to = $to ?: Arr::get($conditions, 'plan_ids', []);
        if ($to && !in_array((int) $change['to_plan_id'], array_map('intval', $to), true)) return false;
        $types = Arr::get($conditions, 'change_types', []);
        return !$types || in_array($change['change_type'] ?? '', $types, true);
    }
}
