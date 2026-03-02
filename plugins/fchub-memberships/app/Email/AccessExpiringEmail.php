<?php

namespace FChubMemberships\Email;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Logger;

class AccessExpiringEmail
{
    private const SETTING_KEY = 'email_access_expiring';
    private const TEMPLATE_KEY = 'access_expiring';

    /**
     * Send the access expiring notification.
     *
     * @param int   $userId WordPress user ID.
     * @param array $data {
     *     @type string $plan_title  Plan name.
     *     @type string $expires_at  Expiry date (Y-m-d H:i:s).
     *     @type string $renewal_url Renewal/re-purchase URL.
     *     @type array  $resources   Resources user will lose [{title}].
     * }
     */
    public function send(int $userId, array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $user = get_userdata($userId);
        if (!$user) {
            Logger::error('AccessExpiringEmail: user not found', "User ID: {$userId}");
            return;
        }

        $expiresAt = $data['expires_at'] ?? '';
        $daysLeft  = '';
        if ($expiresAt) {
            $diff     = (new \DateTime($expiresAt))->diff(new \DateTime('now'));
            $daysLeft = max(0, (int) $diff->days);
        }

        $smartCodes = $this->buildSmartCodes($user, $data, $daysLeft);
        $subject    = $this->replaceSmartCodes(
            __('Your {plan_name} access expires in {days} days', 'fchub-memberships'),
            $smartCodes
        );
        $body = $this->replaceSmartCodes($this->getTemplate(), $smartCodes);
        $body = $this->wrapHtml($body, $subject);

        $this->dispatch($user->user_email, $subject, $body);

        Logger::log(
            'Access expiring email sent',
            sprintf('User %d, Plan: %s, Expires: %s', $userId, $data['plan_title'] ?? '', $expiresAt)
        );
    }

    /**
     * Process pending expiry notifications via daily cron.
     *
     * Queries grants where expires_at is within the configured notice period
     * and the notification hasn't been sent yet. Fires the grant_expiring_soon
     * hook for each grant regardless of email settings.
     */
    public function sendPendingNotifications(): void
    {
        global $wpdb;

        $settings   = get_option('fchub_memberships_settings', []);
        $noticeDays = (int) ($settings['expiry_notice_days'] ?? 7);

        $table   = $wpdb->prefix . 'fchub_membership_grants';
        $cutoff  = gmdate('Y-m-d H:i:s', strtotime("+{$noticeDays} days"));
        $now     = gmdate('Y-m-d H:i:s');

        // Find active grants expiring within notice period, not yet notified
        $grants = $wpdb->get_results($wpdb->prepare(
            "SELECT g.id, g.user_id, g.plan_id, g.expires_at, g.meta
             FROM {$table} g
             WHERE g.status = 'active'
               AND g.expires_at IS NOT NULL
               AND g.expires_at > %s
               AND g.expires_at <= %s
             ORDER BY g.expires_at ASC
             LIMIT 100",
            $now,
            $cutoff
        ));

        if (empty($grants)) {
            return;
        }

        $emailEnabled = $this->isEnabled();
        $plansTable = $wpdb->prefix . 'fchub_membership_plans';

        foreach ($grants as $grant) {
            // Check if already notified
            $meta = $grant->meta ? json_decode($grant->meta, true) : [];
            if (!empty($meta['expiry_notified'])) {
                continue;
            }

            $plan = $wpdb->get_row($wpdb->prepare(
                "SELECT title, slug FROM {$plansTable} WHERE id = %d",
                (int) $grant->plan_id
            ));

            $planTitle = $plan ? $plan->title : __('Membership', 'fchub-memberships');

            $daysLeft = max(0, (int) ceil((strtotime($grant->expires_at) - time()) / DAY_IN_SECONDS));

            // Fire hook for FluentCRM automation triggers (always, regardless of email setting)
            $grantArray = [
                'id'         => (int) $grant->id,
                'user_id'    => (int) $grant->user_id,
                'plan_id'    => (int) $grant->plan_id,
                'expires_at' => $grant->expires_at,
                'meta'       => $meta,
            ];
            do_action('fchub_memberships/grant_expiring_soon', $grantArray, $daysLeft);

            // Send email notification if enabled
            if ($emailEnabled) {
                $resources = $this->getGrantResources((int) $grant->plan_id);
                $renewalUrl = $this->getRenewalUrl($plan);

                $this->send((int) $grant->user_id, [
                    'plan_title'  => $planTitle,
                    'expires_at'  => $grant->expires_at,
                    'renewal_url' => $renewalUrl,
                    'resources'   => $resources,
                ]);
            }

            // Mark as notified
            $meta['expiry_notified'] = gmdate('Y-m-d H:i:s');
            $wpdb->update(
                $table,
                ['meta' => wp_json_encode($meta)],
                ['id' => $grant->id],
                ['%s'],
                ['%d']
            );
        }
    }

    /**
     * Get the resources associated with a plan.
     *
     * @return array<int, array{title: string}>
     */
    private function getGrantResources(int $planId): array
    {
        global $wpdb;

        $rulesTable = $wpdb->prefix . 'fchub_membership_plan_rules';
        $rules = $wpdb->get_results($wpdb->prepare(
            "SELECT resource_type, resource_id FROM {$rulesTable} WHERE plan_id = %d",
            $planId
        ));

        $resources = [];
        foreach ($rules as $rule) {
            $title = $this->getResourceTitle($rule->resource_type, $rule->resource_id);
            $resources[] = ['title' => $title];
        }

        return $resources;
    }

    /**
     * Get a human-readable title for a resource.
     */
    private function getResourceTitle(string $type, string $id): string
    {
        if (in_array($type, ['post', 'page', 'lesson', 'course'], true)) {
            $post = get_post((int) $id);
            return $post ? $post->post_title : "#{$id}";
        }

        if ($type === 'category' || $type === 'taxonomy_term') {
            $term = get_term((int) $id);
            return ($term && !is_wp_error($term)) ? $term->name : "#{$id}";
        }

        return "{$type} #{$id}";
    }

    /**
     * Build the renewal URL for a plan.
     */
    private function getRenewalUrl(?\stdClass $plan): string
    {
        if ($plan && !empty($plan->slug)) {
            return home_url('/membership/' . $plan->slug . '/');
        }

        return home_url('/');
    }

    /**
     * Build smart code replacements.
     *
     * @param \WP_User    $user
     * @param array       $data
     * @param int|string  $daysLeft
     */
    private function buildSmartCodes(\WP_User $user, array $data, $daysLeft): array
    {
        $resourcesHtml = '';
        if (!empty($data['resources'])) {
            $resourcesHtml = '<ul>';
            foreach ($data['resources'] as $resource) {
                $resourcesHtml .= '<li>' . esc_html($resource['title'] ?? '') . '</li>';
            }
            $resourcesHtml .= '</ul>';
        }

        $expiresFormatted = '';
        if (!empty($data['expires_at'])) {
            $expiresFormatted = wp_date(
                get_option('date_format') . ' ' . get_option('time_format'),
                strtotime($data['expires_at'])
            );
        }

        return [
            '{user_name}'      => $user->display_name ?: $user->user_login,
            '{user_email}'     => $user->user_email,
            '{plan_name}'      => $data['plan_title'] ?? '',
            '{site_name}'      => get_bloginfo('name'),
            '{days}'           => (string) $daysLeft,
            '{expires_at}'     => $expiresFormatted,
            '{renewal_url}'    => $data['renewal_url'] ?? home_url('/'),
            '{resources_list}' => $resourcesHtml,
        ];
    }

    private function isEnabled(): bool
    {
        $settings = get_option('fchub_memberships_settings', []);

        return !isset($settings[self::SETTING_KEY]) || $settings[self::SETTING_KEY] !== 'no';
    }

    public function getTemplate(): string
    {
        $settings  = get_option('fchub_memberships_settings', []);
        $templates = $settings['email_templates'] ?? [];

        if (!empty($templates[self::TEMPLATE_KEY])) {
            return $templates[self::TEMPLATE_KEY];
        }

        return static::getDefaultTemplate();
    }

    public static function getDefaultTemplate(): string
    {
        return <<<'HTML'
<h2>Your access is expiring soon, {user_name}</h2>
<p>Your <strong>{plan_name}</strong> membership expires on <strong>{expires_at}</strong> ({days} days from now).</p>
<p>When your access expires, you will lose access to the following resources:</p>
{resources_list}
<p>Renew now to keep your access:</p>
<p><a href="{renewal_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">Renew Membership</a></p>
<p>If you have any questions, feel free to reply to this email.</p>
<p>Best regards,<br>{site_name}</p>
HTML;
    }

    private function replaceSmartCodes(string $content, array $codes): string
    {
        return str_replace(array_keys($codes), array_values($codes), $content);
    }

    private function wrapHtml(string $body, string $title): string
    {
        $siteName = esc_html(get_bloginfo('name'));

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f3f4f6;">
<tr><td align="center" style="padding:40px 20px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
<tr><td style="padding:32px 40px;background-color:#2563eb;text-align:center;">
<h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:600;">{$siteName}</h1>
</td></tr>
<tr><td style="padding:32px 40px;color:#374151;font-size:15px;line-height:1.6;">
{$body}
</td></tr>
<tr><td style="padding:20px 40px;background-color:#f9fafb;text-align:center;font-size:12px;color:#9ca3af;">
&copy; {$siteName}. All rights reserved.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    private function dispatch(string $to, string $subject, string $body): void
    {
        $headers = $this->getHeaders();

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('fchub_memberships_send_email', [
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
                'headers' => $headers,
            ]);
            return;
        }

        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * @return string[]
     */
    private function getHeaders(): array
    {
        $fromName  = get_bloginfo('name');
        $fromEmail = get_option('admin_email');

        return [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$fromName} <{$fromEmail}>",
        ];
    }
}
