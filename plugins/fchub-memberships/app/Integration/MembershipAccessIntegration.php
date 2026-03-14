<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Grant\AnchorDateCalculator;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Logger;
use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use FluentCart\Framework\Support\Arr;

class MembershipAccessIntegration extends BaseIntegrationManager
{
    protected $runOnBackgroundForProduct = false;
    protected $runOnBackgroundForGlobal = false;

    public function __construct()
    {
        parent::__construct(
            'Memberships',
            'memberships',
            12
        );

        $this->description = __('Grant membership plan access when orders are paid. Supports lifetime, fixed duration, and subscription-mirrored validity.', 'fchub-memberships');
        $this->logo = FCHUB_MEMBERSHIPS_URL . 'assets/icons/memberships.svg';
        $this->category = 'membership';
        $this->scopes = ['global', 'product'];
        $this->hasGlobalMenu = true;
        $this->disableGlobalSettings = false;
    }

    /**
     * Always configured — no external API needed.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * API settings check — always active.
     */
    public function getApiSettings(): array
    {
        return ['status' => true];
    }

    /**
     * Default feed settings.
     */
    public function getIntegrationDefaults($settings): array
    {
        return [
            'enabled'       => 'yes',
            'name'          => __('Membership Access', 'fchub-memberships'),
            'event_trigger' => ['order_paid_done'],
        ];
    }

    /**
     * Settings fields for the integration feed form.
     */
    public function getSettingsFields($settings, $args = []): array
    {
        $fields = [
            [
                'key'         => 'name',
                'label'       => __('Feed Title', 'fchub-memberships'),
                'required'    => true,
                'placeholder' => __('Name', 'fchub-memberships'),
                'component'   => 'text',
                'inline_tip'  => __('Name of this feed for identification purposes.', 'fchub-memberships'),
            ],
            [
                'key'            => 'plan_id',
                'label'          => __('Membership Plan', 'fchub-memberships'),
                'required'       => true,
                'component'      => 'rest_selector',
                'option_key'     => 'plan_id',
                'is_multiple'    => false,
                'cacheable'      => true,
                'inline_tip'     => __('Select the membership plan to grant when this feed fires.', 'fchub-memberships'),
            ],
            [
                'key'       => 'validity_mode',
                'label'     => __('Validity Mode', 'fchub-memberships'),
                'required'  => true,
                'component' => 'radio_choice',
                'options'   => [
                    'lifetime'             => __('Lifetime (never expires)', 'fchub-memberships'),
                    'fixed_duration'       => __('Fixed Duration (X days)', 'fchub-memberships'),
                    'mirror_subscription'  => __('Mirror Subscription (expires with subscription)', 'fchub-memberships'),
                    'anchor_billing'       => __('Fixed Billing Anchor (monthly due date)', 'fchub-memberships'),
                ],
                'inline_tip' => __('How long the membership access should last.', 'fchub-memberships'),
            ],
            [
                'key'         => 'validity_days',
                'label'       => __('Validity Days', 'fchub-memberships'),
                'component'   => 'number',
                'placeholder' => '30',
                'inline_tip'  => __('Number of days the membership is valid. Only used with Fixed Duration mode.', 'fchub-memberships'),
                'dependency'  => [
                    'depends_on' => 'validity_mode',
                    'value'      => 'fixed_duration',
                    'operator'   => '=',
                ],
            ],
            [
                'key'         => 'billing_anchor_day',
                'label'       => __('Billing Anchor Day', 'fchub-memberships'),
                'component'   => 'number',
                'placeholder' => '20',
                'inline_tip'  => __('Day of the month (1-31) when payment is due. Access suspends if unpaid by this date. Short months clamp to the last valid day.', 'fchub-memberships'),
                'dependency'  => [
                    'depends_on' => 'validity_mode',
                    'value'      => 'anchor_billing',
                    'operator'   => '=',
                ],
            ],
            [
                'key'         => 'grace_period_days',
                'label'       => __('Grace Period (Days)', 'fchub-memberships'),
                'component'   => 'number',
                'placeholder' => '0',
                'inline_tip'  => __('Days to keep access after cancellation or failed renewal. 0 = no grace period.', 'fchub-memberships'),
            ],
            [
                'key'        => 'watch_on_access_revoke',
                'label'      => __('Enable Access Revocation', 'fchub-memberships'),
                'component'  => 'yes-no-checkbox',
                'checkbox_label' => __('Revoke access on cancel/refund events', 'fchub-memberships'),
                'inline_tip' => __('When enabled, membership access will be revoked when the associated order is cancelled or refunded.', 'fchub-memberships'),
            ],
            [
                'key'       => 'cancel_behavior',
                'label'     => __('Cancellation Behavior', 'fchub-memberships'),
                'component' => 'radio_choice',
                'options'   => [
                    'wait_validity' => __('Keep access until validity expires', 'fchub-memberships'),
                    'immediate'     => __('Revoke immediately', 'fchub-memberships'),
                ],
                'inline_tip' => __('What happens when a subscription is cancelled or a refund is issued.', 'fchub-memberships'),
            ],
            [
                'key'        => 'auto_create_user',
                'label'      => __('Auto-Create User', 'fchub-memberships'),
                'component'  => 'yes-no-checkbox',
                'checkbox_label' => __('Create a WordPress user if one does not exist', 'fchub-memberships'),
                'inline_tip' => __('Automatically create a WordPress user from the order email when no account exists.', 'fchub-memberships'),
            ],
        ];

        $fields[] = $this->actionFields();

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => __('Memberships', 'fchub-memberships'),
        ];
    }

    /**
     * Process integration action — grant or revoke membership access.
     */
    public function processAction($order, $eventData): void
    {
        $trigger = Arr::get($eventData, 'trigger', '');
        $isRevokeHook = Arr::get($eventData, 'is_revoke_hook') === 'yes';

        if ($isRevokeHook) {
            $this->handleRevoke($order, $eventData);
            return;
        }

        $this->handleGrant($order, $eventData);
    }

    /**
     * Grant membership plan access to the order's user.
     *
     * Delegates to AccessGrantService::grantPlan() to ensure all lifecycle hooks,
     * audit logging, adapter calls, emails, trial detection, and multi-membership
     * mode enforcement are applied consistently.
     */
    private function handleGrant($order, array $eventData): void
    {
        $settings = Arr::get($eventData, 'feed', []);
        $planId = (int) Arr::get($settings, 'plan_id', 0);

        if (!$planId) {
            Logger::orderLog($order, __('Membership grant skipped', 'fchub-memberships'), __('No plan ID configured in feed.', 'fchub-memberships'), 'warning');
            return;
        }

        $planRepo = new PlanRepository();
        $plan = $planRepo->find($planId);

        if (!$plan) {
            Logger::orderLog($order, __('Membership grant failed', 'fchub-memberships'), sprintf(__('Plan #%d not found.', 'fchub-memberships'), $planId), 'error');
            return;
        }

        $userId = $this->resolveUserId($order, $settings);

        if (!$userId) {
            Logger::orderLog($order, __('Membership grant failed', 'fchub-memberships'), __('No user found and auto-create is disabled.', 'fchub-memberships'), 'error');
            return;
        }

        $validityMode = Arr::get($settings, 'validity_mode', 'lifetime');
        $expiresAt = $this->calculateExpiresAt($validityMode, $settings, $order, $plan);
        $feedId = (int) Arr::get($eventData, 'feed_id', 0);
        $graceDays = (int) Arr::get($settings, 'grace_period_days', $plan['grace_period_days'] ?? 0);

        // Detect subscription from event data to set correct source_type
        $subscription = Arr::get($eventData, 'event_data.subscription');
        $sourceType = $subscription ? 'subscription' : 'order';
        $sourceId = $subscription ? $subscription->id : $order->id;

        $context = [
            'source_type'      => $sourceType,
            'source_id'        => $sourceId,
            'feed_id'          => $feedId,
            'order'            => $order,
            'grace_period_days' => $graceDays,
        ];

        if ($expiresAt) {
            $context['expires_at'] = $expiresAt;
        }

        // Inject billing_anchor_day into grant meta for fixed_anchor plans
        $planDurationType = $plan['duration_type'] ?? 'lifetime';
        if ($planDurationType === 'fixed_anchor') {
            $planMeta = $plan['meta'] ?? [];
            $context['meta'] = array_merge($context['meta'] ?? [], [
                'billing_anchor_day' => (int) ($planMeta['billing_anchor_day'] ?? 1),
            ]);
        } elseif ($validityMode === 'anchor_billing') {
            $context['meta'] = array_merge($context['meta'] ?? [], [
                'billing_anchor_day' => (int) Arr::get($settings, 'billing_anchor_day', 1),
            ]);
        }

        $grantService = new \FChubMemberships\Domain\AccessGrantService();
        $result = $grantService->grantPlan($userId, $planId, $context);

        Logger::orderLog(
            $order,
            __('Membership access granted', 'fchub-memberships'),
            sprintf(
                __('Plan "%s" granted to user #%d (%d created, %d updated, validity: %s, source: %s #%d).', 'fchub-memberships'),
                $plan['title'],
                $userId,
                $result['created'] ?? 0,
                $result['updated'] ?? 0,
                $expiresAt ?? __('lifetime', 'fchub-memberships'),
                $sourceType,
                $sourceId
            )
        );
    }

    /**
     * Revoke membership access.
     *
     * Delegates to AccessGrantService::revokePlan() to ensure all lifecycle hooks,
     * audit logging, adapter calls, and emails are applied consistently.
     */
    private function handleRevoke($order, array $eventData): void
    {
        $settings = Arr::get($eventData, 'feed', []);
        $cancelBehavior = Arr::get($settings, 'cancel_behavior', 'wait_validity');

        if ($cancelBehavior !== 'immediate') {
            Logger::orderLog(
                $order,
                __('Membership revocation deferred', 'fchub-memberships'),
                __('Cancel behavior is set to wait until validity expires. SubscriptionValidityWatcher will handle expiration.', 'fchub-memberships')
            );
            return;
        }

        $grantRepo = new GrantRepository();
        $grants = $grantRepo->getBySourceId($order->id, 'order');

        // Also check subscription-sourced grants
        $subscription = Arr::get($eventData, 'event_data.subscription');
        if ($subscription) {
            $subGrants = $grantRepo->getBySourceId($subscription->id, 'subscription');
            $grantIds = array_column($grants, 'id');
            foreach ($subGrants as $subGrant) {
                if (!in_array($subGrant['id'], $grantIds, false)) {
                    $grants[] = $subGrant;
                }
            }
        }

        if (empty($grants)) {
            Logger::orderLog($order, __('Membership revoke skipped', 'fchub-memberships'), __('No active grants found for this order.', 'fchub-memberships'), 'warning');
            return;
        }

        // Group active grants by plan_id and user_id for proper revocation
        $planUsers = [];
        foreach ($grants as $grant) {
            if ($grant['status'] !== 'active') {
                continue;
            }
            $key = $grant['plan_id'] . ':' . $grant['user_id'];
            $planUsers[$key] = [
                'plan_id' => (int) $grant['plan_id'],
                'user_id' => (int) $grant['user_id'],
            ];
        }

        $grantService = new \FChubMemberships\Domain\AccessGrantService();
        $totalRevoked = 0;

        foreach ($planUsers as $info) {
            $sourceId = $subscription ? $subscription->id : $order->id;
            $result = $grantService->revokePlan($info['user_id'], $info['plan_id'], [
                'source_id' => $sourceId,
                'reason'    => sprintf('Order #%d revoked/refunded', $order->id),
                'order'     => $order,
            ]);
            $totalRevoked += $result['revoked'] ?? 0;
        }

        Logger::orderLog(
            $order,
            __('Membership access revoked', 'fchub-memberships'),
            sprintf(
                __('%d grant(s) revoked for order #%d.', 'fchub-memberships'),
                $totalRevoked,
                $order->id
            )
        );
    }

    /**
     * Calculate the expiration date based on validity mode.
     * Plan data is the primary source of truth for duration configuration.
     */
    private function calculateExpiresAt(string $validityMode, array $settings, $order, ?array $plan = null): ?string
    {
        // Plan is source of truth for duration
        if ($plan) {
            $planDurationType = $plan['duration_type'] ?? 'lifetime';
            if ($planDurationType === 'fixed_days' && !empty($plan['duration_days'])) {
                return date('Y-m-d H:i:s', strtotime('+' . (int) $plan['duration_days'] . ' days'));
            }
            if ($planDurationType === 'fixed_anchor') {
                $planMeta = $plan['meta'] ?? [];
                $anchorDay = (int) ($planMeta['billing_anchor_day'] ?? 1);
                return AnchorDateCalculator::nextAnchorDate($anchorDay, current_time('mysql'));
            }
            if ($planDurationType === 'lifetime') {
                return null;
            }
            // subscription_mirror falls through to feed logic
        }

        // Feed override (existing logic)
        switch ($validityMode) {
            case 'fixed_duration':
                $days = (int) Arr::get($settings, 'validity_days', 30);
                return date('Y-m-d H:i:s', strtotime('+' . max(1, $days) . ' days'));

            case 'mirror_subscription':
                return $this->getSubscriptionNextBillingDate($order);

            case 'anchor_billing':
                $anchorDay = (int) Arr::get($settings, 'billing_anchor_day', 1);
                return AnchorDateCalculator::nextAnchorDate($anchorDay, current_time('mysql'));

            case 'lifetime':
            default:
                return null;
        }
    }

    /**
     * Get the next billing date from the order's subscription.
     */
    private function getSubscriptionNextBillingDate($order): ?string
    {
        if (!method_exists($order, 'subscriptions') && !property_exists($order, 'subscriptions')) {
            return null;
        }

        $subscriptions = is_callable([$order, 'subscriptions'])
            ? $order->subscriptions()->get()
            : ($order->subscriptions ?? []);

        foreach ($subscriptions as $subscription) {
            $nextBilling = is_array($subscription)
                ? Arr::get($subscription, 'next_billing_date')
                : ($subscription->next_billing_date ?? null);

            if ($nextBilling) {
                return $nextBilling;
            }
        }

        return null;
    }

    /**
     * Resolve the WordPress user ID from the order, optionally creating one.
     */
    private function resolveUserId($order, array $settings): ?int
    {
        $userId = $order->user_id ?? null;

        if ($userId) {
            return (int) $userId;
        }

        // Try to find by email
        $email = $order->customer_email ?? ($order->customer->email ?? null);
        if ($email) {
            $user = get_user_by('email', $email);
            if ($user) {
                return $user->ID;
            }
        }

        // Auto-create user if enabled
        $autoCreate = Arr::get($settings, 'auto_create_user', 'yes');
        if ($autoCreate !== 'yes' || empty($email)) {
            return null;
        }

        $username = sanitize_user(current(explode('@', $email)), true);
        if (username_exists($username)) {
            $username .= '_' . wp_rand(100, 999);
        }

        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => wp_generate_password(16),
            'first_name' => $order->customer_first_name ?? '',
            'last_name'  => $order->customer_last_name ?? '',
            'role'       => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            Logger::error(
                __('Failed to create user', 'fchub-memberships'),
                $userId->get_error_message()
            );
            return null;
        }

        return (int) $userId;
    }

}
