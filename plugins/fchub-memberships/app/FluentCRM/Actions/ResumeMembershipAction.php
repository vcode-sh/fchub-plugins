<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Actions\Contracts\MembershipActionRuntimeInterface;
use FChubMemberships\Storage\PlanRepository;
use Throwable;

class ResumeMembershipAction extends BaseAction
{
    private MembershipActionRuntimeInterface $runtime;

    public function __construct(?MembershipActionRuntimeInterface $runtime = null)
    {
        $this->runtime = $runtime ?? new MembershipActionRuntime();
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
            $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
            return;
        }

        $planId = (int) Arr::get($sequence->settings, 'plan_id');

        try {
            if ($planId && !$this->runtime->planExists($planId)) {
                $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
                return;
            }

            $grants = $this->runtime->getPausedGrants($userId, $planId ?: null);
        } catch (Throwable $exception) {
            $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, $exception);
            return;
        }

        if (empty($grants)) {
            $this->skip($funnelSubscriberId, $sequence->id, MembershipActionOutcome::fromAffectedRows(0));
            return;
        }

        $resumed = 0;
        foreach ($grants as $grant) {
            try {
                $result = $this->runtime->resumeGrant((int) $grant['id']);
            } catch (Throwable $exception) {
                $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, $exception, $resumed);
                return;
            }

            if (empty($result['success'])) {
                $this->skip(
                    $funnelSubscriberId,
                    $sequence->id,
                    new MembershipActionOutcome(false, $resumed > 0, $resumed > 0 ? 'partial' : 'failed', ['affected' => $resumed])
                );
                return;
            }

            $resumed++;
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
