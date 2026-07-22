<?php

namespace FChubMemberships\Email;

defined('ABSPATH') || exit;

final class NotificationCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $common = [
            '{user_name}' => ['label' => __('Member name', 'fchub-memberships'), 'type' => 'text', 'sample' => 'Jamie Member'],
            '{user_email}' => ['label' => __('Member email', 'fchub-memberships'), 'type' => 'text', 'sample' => 'jamie@example.com'],
            '{plan_name}' => ['label' => __('Plan name', 'fchub-memberships'), 'type' => 'text', 'sample' => 'Premium Membership'],
            '{site_name}' => ['label' => __('Site name', 'fchub-memberships'), 'type' => 'text', 'sample' => get_bloginfo('name') ?: 'Membership Site'],
        ];

        return [
            'access_granted' => self::definition(
                __('Access granted', 'fchub-memberships'),
                __('Sent as soon as membership access is granted.', 'fchub-memberships'),
                'access',
                'email_access_granted',
                __('Welcome to {plan_name}!', 'fchub-memberships'),
                __('Your membership is active and ready to use.', 'fchub-memberships'),
                AccessGrantedEmail::getDefaultTemplate(),
                $common + [
                    '{account_url}' => ['label' => __('Account URL', 'fchub-memberships'), 'type' => 'url', 'sample' => home_url('/account/')],
                    '{resources_list}' => ['label' => __('Protected resources', 'fchub-memberships'), 'type' => 'rich', 'sample' => '<ul><li>Getting Started</li><li>Member Library</li></ul>'],
                    '{drip_schedule}' => ['label' => __('Drip schedule', 'fchub-memberships'), 'type' => 'rich', 'sample' => '<h3>Coming Soon</h3><ul><li>Advanced Workshop &mdash; Friday</li></ul>'],
                ]
            ),
            'access_expiring' => self::definition(
                __('Access expiring', 'fchub-memberships'),
                __('Warns a member before their access expires.', 'fchub-memberships'),
                'access',
                'email_access_expiring',
                __('Your {plan_name} access expires in {days} days', 'fchub-memberships'),
                __('A clear reminder before membership access ends.', 'fchub-memberships'),
                AccessExpiringEmail::getDefaultTemplate(),
                $common + [
                    '{days}' => ['label' => __('Days remaining', 'fchub-memberships'), 'type' => 'number', 'sample' => '7'],
                    '{expires_at}' => ['label' => __('Expiry date', 'fchub-memberships'), 'type' => 'date', 'sample' => wp_date('j F Y', time() + (7 * DAY_IN_SECONDS))],
                    '{renewal_url}' => ['label' => __('Renewal URL', 'fchub-memberships'), 'type' => 'url', 'sample' => home_url('/pricing/')],
                    '{resources_list}' => ['label' => __('Protected resources', 'fchub-memberships'), 'type' => 'rich', 'sample' => '<ul><li>Member Library</li><li>Private Resources</li></ul>'],
                ]
            ),
            'access_revoked' => self::definition(
                __('Access revoked', 'fchub-memberships'),
                __('Confirms when membership access has been removed.', 'fchub-memberships'),
                'access',
                'email_access_revoked',
                __('Your {plan_name} access has ended', 'fchub-memberships'),
                __('A helpful explanation and a clear next step.', 'fchub-memberships'),
                AccessRevokedEmail::getDefaultTemplate(),
                $common + [
                    '{reason}' => ['label' => __('Reason', 'fchub-memberships'), 'type' => 'text', 'sample' => __('Your membership has ended.', 'fchub-memberships')],
                    '{support_url}' => ['label' => __('Support URL', 'fchub-memberships'), 'type' => 'url', 'sample' => home_url('/contact/')],
                    '{repurchase_url}' => ['label' => __('Purchase URL', 'fchub-memberships'), 'type' => 'url', 'sample' => home_url('/pricing/')],
                ]
            ),
            'membership_paused' => self::definition(
                __('Membership paused', 'fchub-memberships'),
                __('Explains what changes while a membership is paused.', 'fchub-memberships'),
                'lifecycle',
                'email_membership_paused',
                __('Your {plan_name} membership is paused', 'fchub-memberships'),
                __('Membership is paused and can be resumed from the account.', 'fchub-memberships'),
                MembershipPausedEmail::getDefaultTemplate(),
                $common + [
                    '{resume_url}' => ['label' => __('Resume URL', 'fchub-memberships'), 'type' => 'url', 'sample' => home_url('/account/')],
                ]
            ),
            'membership_resumed' => self::definition(
                __('Membership resumed', 'fchub-memberships'),
                __('Welcomes a member back after access resumes.', 'fchub-memberships'),
                'lifecycle',
                'email_membership_resumed',
                __('Your {plan_name} membership is active again', 'fchub-memberships'),
                __('Membership access has resumed.', 'fchub-memberships'),
                MembershipResumedEmail::getDefaultTemplate(),
                $common + [
                    '{account_url}' => ['label' => __('Account URL', 'fchub-memberships'), 'type' => 'url', 'sample' => home_url('/account/')],
                    '{expires_at}' => ['label' => __('Expiry date', 'fchub-memberships'), 'type' => 'date', 'sample' => wp_date('j F Y', time() + (30 * DAY_IN_SECONDS))],
                ]
            ),
            'trial_expiring' => self::definition(
                __('Trial expiring', 'fchub-memberships'),
                __('Reminds a member before their trial ends.', 'fchub-memberships'),
                'trial',
                'email_trial_expiring',
                __('Your {plan_name} trial ends in {days} days', 'fchub-memberships'),
                __('A timely reminder before the trial ends.', 'fchub-memberships'),
                TrialExpiringEmail::getDefaultTemplate(),
                $common + [
                    '{days}' => ['label' => __('Days remaining', 'fchub-memberships'), 'type' => 'number', 'sample' => '3'],
                    '{trial_ends_at}' => ['label' => __('Trial end date', 'fchub-memberships'), 'type' => 'date', 'sample' => wp_date('j F Y', time() + (3 * DAY_IN_SECONDS))],
                    '{upgrade_url}' => ['label' => __('Upgrade URL', 'fchub-memberships'), 'type' => 'url', 'sample' => home_url('/pricing/')],
                ]
            ),
            'trial_converted' => self::definition(
                __('Trial converted', 'fchub-memberships'),
                __('Confirms that a trial became a paid membership.', 'fchub-memberships'),
                'trial',
                'email_trial_converted',
                __('Welcome to your paid {plan_name} membership', 'fchub-memberships'),
                __('The paid membership is active.', 'fchub-memberships'),
                TrialConvertedEmail::getDefaultTemplate(),
                $common + [
                    '{account_url}' => ['label' => __('Account URL', 'fchub-memberships'), 'type' => 'url', 'sample' => home_url('/account/')],
                    '{expires_at}' => ['label' => __('Expiry date', 'fchub-memberships'), 'type' => 'date', 'sample' => wp_date('j F Y', time() + (365 * DAY_IN_SECONDS))],
                ]
            ),
            'drip_content_unlocked' => self::definition(
                __('Drip content unlocked', 'fchub-memberships'),
                __('Notifies a member when scheduled content becomes available.', 'fchub-memberships'),
                'content',
                'email_drip_unlocked',
                __('New content is available: {resource_title}', 'fchub-memberships'),
                __('A new membership resource is ready.', 'fchub-memberships'),
                DripContentUnlockedEmail::getDefaultTemplate(),
                $common + [
                    '{resource_title}' => ['label' => __('Resource title', 'fchub-memberships'), 'type' => 'text', 'sample' => 'Advanced Workshop'],
                    '{resource_url}' => ['label' => __('Resource URL', 'fchub-memberships'), 'type' => 'url', 'sample' => home_url('/members/advanced-workshop/')],
                    '{progress}' => ['label' => __('Drip progress', 'fchub-memberships'), 'type' => 'rich', 'sample' => '<p><strong>3 of 8</strong> resources unlocked</p>'],
                    '{next_drip_item}' => ['label' => __('Next drip item', 'fchub-memberships'), 'type' => 'rich', 'sample' => '<p>Next: Member Q&amp;A on Friday</p>'],
                ]
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function sampleCodes(string $key): array
    {
        $definition = self::get($key);
        if (!$definition) {
            return [];
        }

        $codes = [];
        foreach ($definition['variables'] as $variable => $config) {
            $codes[$variable] = (string) ($config['sample'] ?? '');
        }

        return $codes;
    }

    /**
     * @param array<string, array<string, string>> $variables
     * @return array<string, mixed>
     */
    private static function definition(
        string $label,
        string $description,
        string $group,
        string $settingKey,
        string $subject,
        string $preheader,
        string $body,
        array $variables
    ): array {
        return [
            'label' => $label,
            'description' => $description,
            'group' => $group,
            'setting_key' => $settingKey,
            'default_subject' => $subject,
            'default_preheader' => $preheader,
            'default_body' => $body,
            'variables' => $variables,
            'requires_fluentcrm' => false,
        ];
    }
}
