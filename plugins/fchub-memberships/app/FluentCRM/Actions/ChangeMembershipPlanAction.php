<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Actions\Contracts\MembershipActionRuntimeInterface;
use FChubMemberships\Storage\PlanRepository;
use Throwable;

class ChangeMembershipPlanAction extends BaseAction
{
    private MembershipActionRuntimeInterface $runtime;

    public function __construct(?MembershipActionRuntimeInterface $runtime = null)
    {
        $this->runtime = $runtime ?? new MembershipActionRuntime();
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
            $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
            return;
        }

        $userId = $this->resolveUserId($subscriber);
        if (!$userId) {
            $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
            return;
        }

        try {
            $destinationExists = $this->runtime->planExists($toPlanId);
        } catch (Throwable $exception) {
            $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, 'destinationPlanLookup', $exception);
            return;
        }
        if (!$destinationExists) {
            $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
            return;
        }

        $fromPlanId = (int) Arr::get($sequence->settings, 'from_plan_id');
        $keepExpiry = Arr::get($sequence->settings, 'keep_expiry', 'no') === 'yes';

        try {
            $existingGrants = $this->runtime->getActiveGrants($userId, $fromPlanId ?: null);
        } catch (Throwable $exception) {
            $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, 'activeGrantLookup', $exception);
            return;
        }

        if (empty($existingGrants)) {
            $this->skip($funnelSubscriberId, $sequence->id, MembershipActionOutcome::fromAffectedRows(0));
            return;
        }

        $existingExpiry = null;
        if ($keepExpiry) {
            $existingExpiry = $existingGrants[0]['expires_at'] ?? null;
        }

        $revokePlanId = $fromPlanId ?: (int) ($existingGrants[0]['plan_id'] ?? 0);
        if (!$revokePlanId) {
            $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
            return;
        }

        try {
            $revokeResult = $this->runtime->revokePlan($userId, $revokePlanId, [
                'reason' => 'Changed to plan #' . $toPlanId,
                'grace_period_days' => 0,
            ]);
        } catch (Throwable $exception) {
            $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, 'revoke', $exception);
            return;
        }
        $revokeOutcome = MembershipActionOutcome::fromRevokeResult($revokeResult);
        if (!$revokeOutcome->isSuccessful()) {
            $this->skip($funnelSubscriberId, $sequence->id, $revokeOutcome);
            return;
        }

        $context = [
            'source_type' => 'automation',
            'plan_change' => [
                'change_type' => 'automation_change',
                'from_plan_ids' => [$revokePlanId],
            ],
        ];
        if ($keepExpiry) {
            $context['expires_at'] = $existingExpiry;
            if ($existingExpiry === null) {
                $context['preserve_expiry'] = true;
            }
        }

        try {
            $grantResult = $this->runtime->grantPlan($userId, $toPlanId, $context);
        } catch (Throwable $exception) {
            $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, 'grant', $exception);
            return;
        }
        $grantOutcome = MembershipActionOutcome::fromGrantResult($grantResult);
        if (!$grantOutcome->isSuccessful()) {
            $this->skip($funnelSubscriberId, $sequence->id, $grantOutcome);
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

    private function skipRuntimeFailure(
        mixed $funnelSubscriberId,
        mixed $sequenceId,
        string $stage,
        Throwable $exception
    ): void {
        $this->skip(
            $funnelSubscriberId,
            $sequenceId,
            MembershipActionOutcome::fromThrowable($exception, $stage)
        );
    }

    private function skip(mixed $funnelSubscriberId, mixed $sequenceId, MembershipActionOutcome $outcome): void
    {
        $outcome->skip((int) $funnelSubscriberId, (int) $sequenceId, $this->actionName);
    }
}
