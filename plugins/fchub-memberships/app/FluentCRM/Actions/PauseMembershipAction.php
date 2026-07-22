<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Actions\Contracts\MembershipActionRuntimeInterface;
use FChubMemberships\Storage\PlanRepository;
use Throwable;

class PauseMembershipAction extends BaseAction
{
    private MembershipActionRuntimeInterface $runtime;

    public function __construct(?MembershipActionRuntimeInterface $runtime = null)
    {
        $this->runtime = $runtime ?? new MembershipActionRuntime();
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
            $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
            return;
        }

        $planId = (int) Arr::get($sequence->settings, 'plan_id');
        $reason = Arr::get($sequence->settings, 'reason', '');

        try {
            if ($planId && !$this->runtime->planExists($planId)) {
                $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
                return;
            }

            $grants = $this->runtime->getActiveGrants($userId, $planId ?: null);
        } catch (Throwable $exception) {
            $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, $exception);
            return;
        }

        if (empty($grants)) {
            $this->skip($funnelSubscriberId, $sequence->id, MembershipActionOutcome::fromAffectedRows(0));
            return;
        }

        $paused = 0;
        foreach ($grants as $grant) {
            try {
                $result = $this->runtime->pauseGrant((int) $grant['id'], $reason);
            } catch (Throwable $exception) {
                $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, $exception, $paused);
                return;
            }

            if (empty($result['success'])) {
                $this->skip(
                    $funnelSubscriberId,
                    $sequence->id,
                    new MembershipActionOutcome(false, $paused > 0, $paused > 0 ? 'partial' : 'failed', ['affected' => $paused])
                );
                return;
            }

            $paused++;
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

    private function skip(mixed $funnelSubscriberId, mixed $sequenceId, MembershipActionOutcome $outcome): void
    {
        $outcome->skip((int) $funnelSubscriberId, (int) $sequenceId, $this->actionName);
    }

    private function skipRuntimeFailure(
        mixed $funnelSubscriberId,
        mixed $sequenceId,
        Throwable $exception,
        int $affected = 0
    ): void
    {
        $this->skip(
            $funnelSubscriberId,
            $sequenceId,
            MembershipActionOutcome::fromThrowable($exception, null, $affected)
        );
    }
}
