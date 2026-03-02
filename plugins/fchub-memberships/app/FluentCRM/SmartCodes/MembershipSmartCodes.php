<?php

namespace FChubMemberships\FluentCRM\SmartCodes;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\FluentCRM\Helpers\CheckoutUrlHelper;

class MembershipSmartCodes
{
    /** @var array Per-request cache keyed by user_id */
    private static array $grantCache = [];

    /** @var array Per-request cache for plan data keyed by plan_id */
    private static array $planCache = [];

    public static function register(): void
    {
        add_filter('fluent_crm_funnel_context_smart_codes', [static::class, 'pushSmartCodes']);
        add_filter('fluent_crm/smartcode_group_callback_membership', [static::class, 'parseSmartCode'], 10, 4);
    }

    public static function pushSmartCodes(array $codes): array
    {
        $codes[] = [
            'key'        => 'membership',
            'title'      => __('Membership', 'fchub-memberships'),
            'description' => __('Membership-related smart codes', 'fchub-memberships'),
            'shortcodes' => [
                '{{membership.plan_name}}'       => __('Active Plan Name', 'fchub-memberships'),
                '{{membership.plan_slug}}'       => __('Active Plan Slug', 'fchub-memberships'),
                '{{membership.status}}'          => __('Membership Status', 'fchub-memberships'),
                '{{membership.expires_at}}'      => __('Expiration Date', 'fchub-memberships'),
                '{{membership.days_remaining}}'  => __('Days Until Expiry', 'fchub-memberships'),
                '{{membership.trial_ends_at}}'   => __('Trial End Date', 'fchub-memberships'),
                '{{membership.renewal_count}}'   => __('Renewal Count', 'fchub-memberships'),
                '{{membership.granted_at}}'      => __('Grant Date', 'fchub-memberships'),
                '{{membership.resources_count}}' => __('Accessible Resources Count', 'fchub-memberships'),
                '{{membership.account_url}}'     => __('Account Page URL', 'fchub-memberships'),
                '{{membership.drip_progress}}'   => __('Drip Progress', 'fchub-memberships'),
                '{{membership.all_plans}}'       => __('All Active Plan Names', 'fchub-memberships'),
                '{{membership.cancellation_reason}}'    => __('Cancellation / Revoke Reason', 'fchub-memberships'),
                '{{membership.trial_days_remaining}}'   => __('Trial Days Remaining', 'fchub-memberships'),
                '{{membership.coupon_code}}'     => __('Last Generated Coupon Code', 'fchub-memberships'),
                '{{membership.coupon_amount}}'   => __('Last Generated Coupon Amount', 'fchub-memberships'),
                '{{membership.coupon_expires}}'  => __('Last Generated Coupon Expiry', 'fchub-memberships'),
                '{{membership.checkout_url}}'    => __('Checkout URL for Current Plan', 'fchub-memberships'),
                '{{membership.upgrade_url}}'     => __('Upgrade URL for Next Plan', 'fchub-memberships'),
                '{{membership.next_billing_date}}' => __('Next Billing Date', 'fchub-memberships'),
                '{{membership.payment_update_url}}' => __('Payment Update URL', 'fchub-memberships'),
                '{{membership.days_as_member}}'  => __('Days as Member', 'fchub-memberships'),
                '{{membership.member_since}}'    => __('Member Since Date', 'fchub-memberships'),
                '{{membership.days_since_expired}}' => __('Days Since Expired', 'fchub-memberships'),
                '{{membership.drip_percentage}}' => __('Drip Completion Percentage', 'fchub-memberships'),
            ],
        ];

        return $codes;
    }

    public static function parseSmartCode($code, string $valueKey, $defaultValue, $subscriber): string
    {
        if (!$subscriber) {
            return '';
        }

        // Coupon codes are stored on subscriber meta — no user_id required
        if (in_array($valueKey, ['coupon_code', 'coupon_amount', 'coupon_expires'], true)) {
            return self::parseCouponSmartCode($valueKey, $subscriber);
        }

        if (!$subscriber->user_id) {
            return '';
        }

        $userId = (int) $subscriber->user_id;
        $grant = self::getLatestActiveGrant($userId);

        switch ($valueKey) {
            case 'plan_name':
                if (!$grant) {
                    return '';
                }
                $plan = self::getPlan($grant['plan_id']);
                return $plan ? $plan['title'] : '';

            case 'plan_slug':
                if (!$grant) {
                    return '';
                }
                $plan = self::getPlan($grant['plan_id']);
                return $plan ? $plan['slug'] : '';

            case 'status':
                return $grant ? $grant['status'] : '';

            case 'expires_at':
                if (!$grant || empty($grant['expires_at'])) {
                    return '';
                }
                return date_i18n(get_option('date_format'), strtotime($grant['expires_at']));

            case 'days_remaining':
                if (!$grant || empty($grant['expires_at'])) {
                    return '';
                }
                $diff = strtotime($grant['expires_at']) - current_time('timestamp');
                return (string) max(0, (int) ceil($diff / DAY_IN_SECONDS));

            case 'trial_ends_at':
                if (!$grant || empty($grant['trial_ends_at'])) {
                    return '';
                }
                return date_i18n(get_option('date_format'), strtotime($grant['trial_ends_at']));

            case 'renewal_count':
                return $grant ? (string) $grant['renewal_count'] : '0';

            case 'granted_at':
                if (!$grant || empty($grant['created_at'])) {
                    return '';
                }
                return date_i18n(get_option('date_format'), strtotime($grant['created_at']));

            case 'resources_count':
                if (!$grant || !$grant['plan_id']) {
                    return '0';
                }
                $planRepo = new PlanRepository();
                return (string) $planRepo->getRuleCount($grant['plan_id']);

            case 'account_url':
                return site_url('/account/');

            case 'drip_progress':
                return self::getDripProgress($userId, $grant);

            case 'all_plans':
                return self::getAllActivePlanNames($userId);

            case 'cancellation_reason':
                return self::getCancellationReason($userId);

            case 'trial_days_remaining':
                return self::getTrialDaysRemaining($grant);

            case 'checkout_url':
                if (!$grant || !$grant['plan_id']) {
                    return '';
                }
                return CheckoutUrlHelper::getCheckoutUrl($grant['plan_id']);

            case 'upgrade_url':
                if (!$grant || !$grant['plan_id']) {
                    return '';
                }
                return CheckoutUrlHelper::getUpgradeUrl($grant['plan_id']);

            case 'next_billing_date':
                if (!$grant) {
                    return '';
                }
                $subscriptionId = CheckoutUrlHelper::getSubscriptionIdFromGrant($grant);
                if (!$subscriptionId) {
                    return '';
                }
                return CheckoutUrlHelper::getNextBillingDate($subscriptionId);

            case 'payment_update_url':
                if (!$grant) {
                    return '';
                }
                $subscriptionId = CheckoutUrlHelper::getSubscriptionIdFromGrant($grant);
                if (!$subscriptionId) {
                    return '';
                }
                return CheckoutUrlHelper::getPaymentUpdateUrl($subscriptionId);

            case 'days_as_member':
                return self::getDaysAsMember($userId);

            case 'member_since':
                return self::getMemberSince($userId);

            case 'days_since_expired':
                return self::getDaysSinceExpired($userId);

            case 'drip_percentage':
                return self::getDripPercentage($userId, $grant);

            default:
                return '';
        }
    }

    private static function getLatestActiveGrant(int $userId): ?array
    {
        if (isset(self::$grantCache[$userId])) {
            return self::$grantCache[$userId];
        }

        $grantRepo = new GrantRepository();
        $grants = $grantRepo->getByUserId($userId, ['status' => 'active']);

        self::$grantCache[$userId] = !empty($grants) ? $grants[0] : null;

        return self::$grantCache[$userId];
    }

    private static function getPlan(int $planId): ?array
    {
        if (isset(self::$planCache[$planId])) {
            return self::$planCache[$planId];
        }

        $planRepo = new PlanRepository();
        self::$planCache[$planId] = $planRepo->find($planId);

        return self::$planCache[$planId];
    }

    private static function getDripProgress(int $userId, ?array $grant): string
    {
        if (!$grant) {
            return '';
        }

        $dripRepo = new DripScheduleRepository();
        $notifications = $dripRepo->getByGrantId($grant['id']);

        if (empty($notifications)) {
            return '';
        }

        $total = count($notifications);
        $sent = count(array_filter($notifications, fn($n) => $n['status'] === 'sent'));

        return sprintf(__('%d of %d items unlocked', 'fchub-memberships'), $sent, $total);
    }

    private static function parseCouponSmartCode(string $valueKey, $subscriber): string
    {
        switch ($valueKey) {
            case 'coupon_code':
                return (string) ($subscriber->getMeta('_fchub_last_coupon_code') ?: '');

            case 'coupon_amount':
                $couponAmount = $subscriber->getMeta('_fchub_last_coupon_amount');
                $couponType = $subscriber->getMeta('_fchub_last_coupon_type');
                if (!$couponAmount) {
                    return '';
                }
                return $couponType === 'percentage'
                    ? $couponAmount . '%'
                    : $couponAmount;

            case 'coupon_expires':
                $expiryDate = $subscriber->getMeta('_fchub_last_coupon_expires');
                if (!$expiryDate) {
                    return '';
                }
                return date_i18n(get_option('date_format'), strtotime($expiryDate));

            default:
                return '';
        }
    }

    private static function getDaysAsMember(int $userId): string
    {
        $grantRepo = new GrantRepository();
        $grants = $grantRepo->getByUserId($userId);

        if (empty($grants)) {
            return '';
        }

        // Find earliest created_at across all grants (any status)
        $earliest = null;
        foreach ($grants as $grant) {
            if (!empty($grant['created_at'])) {
                $ts = strtotime($grant['created_at']);
                if ($earliest === null || $ts < $earliest) {
                    $earliest = $ts;
                }
            }
        }

        if ($earliest === null) {
            return '';
        }

        $days = (int) floor((current_time('timestamp') - $earliest) / DAY_IN_SECONDS);
        return (string) max(0, $days);
    }

    private static function getMemberSince(int $userId): string
    {
        $grantRepo = new GrantRepository();
        $grants = $grantRepo->getByUserId($userId);

        if (empty($grants)) {
            return '';
        }

        $earliest = null;
        foreach ($grants as $grant) {
            if (!empty($grant['created_at'])) {
                $ts = strtotime($grant['created_at']);
                if ($earliest === null || $ts < $earliest) {
                    $earliest = $ts;
                }
            }
        }

        if ($earliest === null) {
            return '';
        }

        return date_i18n(get_option('date_format'), $earliest);
    }

    private static function getDaysSinceExpired(int $userId): string
    {
        $grantRepo = new GrantRepository();

        // If user has any active grants, return empty
        $activeGrants = $grantRepo->getByUserId($userId, ['status' => 'active']);
        if (!empty($activeGrants)) {
            return '';
        }

        // Find the most recent expired grant
        $expiredGrants = $grantRepo->getByUserId($userId, ['status' => 'expired']);
        if (empty($expiredGrants)) {
            return '';
        }

        // Grants are ordered by created_at DESC, find the one with most recent expires_at
        $latestExpiry = null;
        foreach ($expiredGrants as $grant) {
            if (!empty($grant['expires_at'])) {
                $ts = strtotime($grant['expires_at']);
                if ($latestExpiry === null || $ts > $latestExpiry) {
                    $latestExpiry = $ts;
                }
            }
        }

        if ($latestExpiry === null) {
            return '';
        }

        $days = (int) floor((current_time('timestamp') - $latestExpiry) / DAY_IN_SECONDS);
        return (string) max(0, $days);
    }

    private static function getDripPercentage(int $userId, ?array $grant): string
    {
        if (!$grant) {
            return '';
        }

        $dripRepo = new DripScheduleRepository();
        $notifications = $dripRepo->getByGrantId($grant['id']);

        if (empty($notifications)) {
            return '';
        }

        $total = count($notifications);
        $sent = count(array_filter($notifications, fn($n) => $n['status'] === 'sent'));

        return (string) (int) round(($sent / $total) * 100);
    }

    private static function getCancellationReason(int $userId): string
    {
        $grantRepo = new GrantRepository();

        // Look across all grant statuses for cancellation/revoke reason
        $grants = $grantRepo->getByUserId($userId);

        foreach ($grants as $grant) {
            $meta = $grant['meta'] ?? [];

            if (!empty($meta['cancellation_reason'])) {
                return (string) $meta['cancellation_reason'];
            }
            if (!empty($meta['revoke_reason'])) {
                return (string) $meta['revoke_reason'];
            }

            // Also check the top-level cancellation_reason field
            if (!empty($grant['cancellation_reason'])) {
                return (string) $grant['cancellation_reason'];
            }
        }

        return '';
    }

    private static function getTrialDaysRemaining(?array $grant): string
    {
        if (!$grant || empty($grant['trial_ends_at'])) {
            return '';
        }

        $diff = strtotime($grant['trial_ends_at']) - current_time('timestamp');
        return (string) max(0, (int) ceil($diff / DAY_IN_SECONDS));
    }

    private static function getAllActivePlanNames(int $userId): string
    {
        $grantRepo = new GrantRepository();
        $grants = $grantRepo->getByUserId($userId, ['status' => 'active']);

        if (empty($grants)) {
            return '';
        }

        $planIds = array_unique(array_column($grants, 'plan_id'));
        $names = [];

        foreach ($planIds as $planId) {
            $plan = self::getPlan($planId);
            if ($plan) {
                $names[] = $plan['title'];
            }
        }

        return implode(', ', $names);
    }
}
