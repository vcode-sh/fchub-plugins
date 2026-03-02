<?php

namespace FChubMemberships\FluentCRM\Filters;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRepository;

class MembershipFilters
{
    public static function register(): void
    {
        add_filter('fluentcrm_advanced_filter_options', [static::class, 'addFilterOptions']);
        add_action('fluentcrm_contacts_filter_fchub_memberships', [static::class, 'applyFilters'], 10, 2);
    }

    public static function addFilterOptions(array $groups): array
    {
        $planOptions = static::getPlanOptions();

        $groups['fchub_memberships'] = [
            'label'    => __('Memberships', 'fchub-memberships'),
            'value'    => 'fchub_memberships',
            'children' => [
                [
                    'value'            => 'fchub_has_membership',
                    'label'            => __('Has Membership Plan', 'fchub-memberships'),
                    'type'             => 'selections',
                    'is_multiple'      => true,
                    'options'          => $planOptions,
                    'custom_operators' => [
                        'exist'     => __('has', 'fchub-memberships'),
                        'not_exist' => __('does not have', 'fchub-memberships'),
                    ],
                ],
                [
                    'value'            => 'fchub_membership_status',
                    'label'            => __('Membership Status', 'fchub-memberships'),
                    'type'             => 'selections',
                    'is_multiple'      => false,
                    'is_singular_value' => true,
                    'options'          => [
                        ['id' => 'active', 'title' => __('Active', 'fchub-memberships')],
                        ['id' => 'paused', 'title' => __('Paused', 'fchub-memberships')],
                        ['id' => 'expired', 'title' => __('Expired', 'fchub-memberships')],
                        ['id' => 'revoked', 'title' => __('Revoked', 'fchub-memberships')],
                    ],
                    'custom_operators' => [
                        'exist'     => __('is', 'fchub-memberships'),
                        'not_exist' => __('is not', 'fchub-memberships'),
                    ],
                ],
                [
                    'value' => 'fchub_days_until_expiry',
                    'label' => __('Days Until Expiry', 'fchub-memberships'),
                    'type'  => 'numeric',
                ],
                [
                    'value' => 'fchub_renewal_count',
                    'label' => __('Renewal Count', 'fchub-memberships'),
                    'type'  => 'numeric',
                ],
                [
                    'value' => 'fchub_member_duration',
                    'label' => __('Member Duration (Days)', 'fchub-memberships'),
                    'type'  => 'numeric',
                ],
                [
                    'value'             => 'fchub_in_trial',
                    'label'             => __('In Trial', 'fchub-memberships'),
                    'type'              => 'selections',
                    'is_multiple'       => false,
                    'disable_values'    => true,
                    'value_description' => __('Check if the contact is currently in a trial membership period', 'fchub-memberships'),
                    'custom_operators'  => [
                        'exist'     => __('Yes', 'fchub-memberships'),
                        'not_exist' => __('No', 'fchub-memberships'),
                    ],
                ],
            ],
        ];

        return $groups;
    }

    public static function applyFilters($query, array $filters)
    {
        foreach ($filters as $filter) {
            $query = static::applyFilter($query, $filter);
        }
        return $query;
    }

    private static function applyFilter($query, array $filter)
    {
        $key = $filter['property'] ?? '';
        $operator = $filter['operator'] ?? '';
        $value = $filter['value'] ?? '';
        if (!$key || !$operator) {
            return $query;
        }

        global $wpdb;
        $t = $wpdb->prefix . 'fchub_membership_grants';

        return match ($key) {
            'fchub_has_membership' => static::filterHasMembership($query, $operator, $value, $t),
            'fchub_membership_status' => static::filterMembershipStatus($query, $operator, $value, $t),
            'fchub_days_until_expiry' => $value !== '' ? static::filterDaysUntilExpiry($query, $operator, $value, $t) : $query,
            'fchub_renewal_count' => $value !== '' ? static::filterRenewalCount($query, $operator, $value, $t) : $query,
            'fchub_member_duration' => $value !== '' ? static::filterMemberDuration($query, $operator, $value, $t) : $query,
            'fchub_in_trial' => static::filterInTrial($query, $operator, $t),
            default => $query,
        };
    }

    private static function filterHasMembership($query, string $operator, $value, string $t)
    {
        $planIds = array_filter(is_array($value) ? array_map('intval', $value) : [intval($value)]);
        $method = $operator === 'not_exist' ? 'whereNotExists' : 'whereExists';

        return $query->{$method}(function ($q) use ($t, $planIds) {
            $q->select(fluentCrmDb()->raw(1))->from($t)
                ->whereColumn($t . '.user_id', 'fc_subscribers.user_id')
                ->where($t . '.status', 'active');
            if ($planIds) {
                $q->whereIn($t . '.plan_id', $planIds);
            }
        });
    }

    private static function filterMembershipStatus($query, string $operator, $value, string $t)
    {
        $status = is_array($value) ? reset($value) : $value;
        $method = $operator === 'not_exist' ? 'whereNotExists' : 'whereExists';

        return $query->{$method}(function ($q) use ($t, $status) {
            $q->select(fluentCrmDb()->raw(1))->from($t)
                ->whereColumn($t . '.user_id', 'fc_subscribers.user_id')
                ->where($t . '.status', $status);
        });
    }

    private static function filterDaysUntilExpiry($query, string $operator, $value, string $t)
    {
        $operator = self::sanitizeOperator($operator);
        if (!$operator) {
            return $query;
        }

        return $query->whereExists(function ($q) use ($t, $operator, $value) {
            $q->select(fluentCrmDb()->raw(1))->from($t)
                ->whereColumn($t . '.user_id', 'fc_subscribers.user_id')
                ->where($t . '.status', 'active')
                ->whereNotNull($t . '.expires_at')
                ->whereRaw("DATEDIFF({$t}.expires_at, NOW()) {$operator} ?", [(int) $value]);
        });
    }

    private static function filterRenewalCount($query, string $operator, $value, string $t)
    {
        $operator = self::sanitizeOperator($operator);
        if (!$operator) {
            return $query;
        }

        return $query->whereExists(function ($q) use ($t, $operator, $value) {
            $q->select(fluentCrmDb()->raw(1))->from($t)
                ->whereColumn($t . '.user_id', 'fc_subscribers.user_id')
                ->where($t . '.status', 'active')
                ->where($t . '.renewal_count', $operator, (int) $value);
        });
    }

    private static function sanitizeOperator(string $operator): ?string
    {
        $allowed = ['=', '!=', '<>', '>', '<', '>=', '<='];
        return in_array($operator, $allowed, true) ? $operator : null;
    }

    private static function filterMemberDuration($query, string $operator, $value, string $t)
    {
        $operator = self::sanitizeOperator($operator);
        if (!$operator) {
            return $query;
        }

        return $query->whereExists(function ($q) use ($t, $operator, $value) {
            $q->select(fluentCrmDb()->raw(1))->from($t)
                ->whereColumn($t . '.user_id', 'fc_subscribers.user_id')
                ->whereRaw("DATEDIFF(NOW(), {$t}.created_at) {$operator} ?", [(int) $value]);
        });
    }

    private static function filterInTrial($query, string $operator, string $t)
    {
        $method = $operator === 'not_exist' ? 'whereNotExists' : 'whereExists';

        return $query->{$method}(function ($q) use ($t) {
            $q->select(fluentCrmDb()->raw(1))->from($t)
                ->whereColumn($t . '.user_id', 'fc_subscribers.user_id')
                ->where($t . '.status', 'active')
                ->whereNotNull($t . '.trial_ends_at')
                ->whereRaw("{$t}.trial_ends_at > NOW()");
        });
    }

    private static function getPlanOptions(): array
    {
        try {
            $plans = (new PlanRepository())->all(['status' => 'active']);
            return array_map(fn($p) => ['id' => (string) $p['id'], 'title' => $p['title']], $plans);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
