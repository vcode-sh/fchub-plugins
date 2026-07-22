<?php

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Actions\Contracts\MembershipActionRuntimeInterface;
use FChubMemberships\Storage\PlanRepository;
use Throwable;

class ExtendMembershipAction extends BaseAction
{
    private MembershipActionRuntimeInterface $runtime;

    public function __construct(?MembershipActionRuntimeInterface $runtime = null)
    {
        $this->runtime = $runtime ?? new MembershipActionRuntime();
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
        if (!$planId || $extendDays <= 0) {
            $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
            return;
        }

        $userId = $this->resolveUserId($subscriber);
        if (!$userId) {
            $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
            return;
        }

        try {
            if (!$this->runtime->planExists($planId)) {
                $this->skip($funnelSubscriberId, $sequence->id, new MembershipActionOutcome(false, false, 'invalid_input'));
                return;
            }

            $grants = $this->runtime->getActiveGrants($userId, $planId);
        } catch (Throwable $exception) {
            $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, $exception);
            return;
        }

        if (empty($grants)) {
            $this->skip($funnelSubscriberId, $sequence->id, MembershipActionOutcome::fromAffectedRows(0));
            return;
        }

        $extendMode = Arr::get($sequence->settings, 'extend_mode', 'from_current_expiry');

        $grant = $grants[0];
        if ($extendMode === 'from_current_expiry' && !empty($grant['expires_at'])) {
            $baseTime = strtotime($grant['expires_at']);
        } else {
            $baseTime = time();
        }
        $newExpiresAt = gmdate('Y-m-d H:i:s', strtotime('+' . $extendDays . ' days', $baseTime));

        try {
            $outcome = MembershipActionOutcome::fromAffectedRows(
                $this->runtime->extendExpiry($userId, $planId, $newExpiresAt)
            );
        } catch (Throwable $exception) {
            $this->skipRuntimeFailure($funnelSubscriberId, $sequence->id, $exception);
            return;
        }

        if (!$outcome->isSuccessful()) {
            $this->skip($funnelSubscriberId, $sequence->id, $outcome);
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

    private function skipRuntimeFailure(mixed $funnelSubscriberId, mixed $sequenceId, Throwable $exception): void
    {
        $this->skip(
            $funnelSubscriberId,
            $sequenceId,
            MembershipActionOutcome::fromThrowable($exception)
        );
    }
}
