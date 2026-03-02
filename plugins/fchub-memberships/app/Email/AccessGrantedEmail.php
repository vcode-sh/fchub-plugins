<?php

namespace FChubMemberships\Email;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Logger;

class AccessGrantedEmail
{
    private const SETTING_KEY = 'email_access_granted';
    private const TEMPLATE_KEY = 'access_granted';

    /**
     * Send the welcome/access granted email.
     *
     * @param int   $userId   WordPress user ID.
     * @param array $grantData {
     *     @type int    $plan_id    Plan ID.
     *     @type string $plan_title Plan name.
     *     @type array  $resources  Immediately accessible resources [{title, url}].
     *     @type array  $drip_items Drip schedule items [{title, available_date}].
     * }
     */
    public function send(int $userId, array $grantData): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $user = get_userdata($userId);
        if (!$user) {
            Logger::error('AccessGrantedEmail: user not found', "User ID: {$userId}");
            return;
        }

        $smartCodes = $this->buildSmartCodes($user, $grantData);
        $subject    = $this->replaceSmartCodes(
            __('Welcome to {plan_name}!', 'fchub-memberships'),
            $smartCodes
        );
        $body = $this->replaceSmartCodes($this->getTemplate(), $smartCodes);
        $body = $this->wrapHtml($body, $subject);

        $this->dispatch($user->user_email, $subject, $body);

        Logger::log(
            'Access granted email sent',
            sprintf('User %d, Plan: %s', $userId, $grantData['plan_title'] ?? '')
        );
    }

    /**
     * Build smart code replacements.
     */
    private function buildSmartCodes(\WP_User $user, array $data): array
    {
        $resourcesHtml = '';
        if (!empty($data['resources'])) {
            $resourcesHtml = '<ul>';
            foreach ($data['resources'] as $resource) {
                $title = esc_html($resource['title'] ?? '');
                $url   = esc_url($resource['url'] ?? '#');
                $resourcesHtml .= "<li><a href=\"{$url}\">{$title}</a></li>";
            }
            $resourcesHtml .= '</ul>';
        }

        $dripHtml = '';
        if (!empty($data['drip_items'])) {
            $dripHtml = '<h3>' . __('Coming Soon', 'fchub-memberships') . '</h3><ul>';
            foreach ($data['drip_items'] as $item) {
                $title = esc_html($item['title'] ?? '');
                $date  = esc_html($item['available_date'] ?? '');
                $dripHtml .= "<li>{$title} &mdash; {$date}</li>";
            }
            $dripHtml .= '</ul>';
        }

        return [
            '{user_name}'     => $user->display_name ?: $user->user_login,
            '{user_email}'    => $user->user_email,
            '{plan_name}'     => $data['plan_title'] ?? '',
            '{site_name}'     => get_bloginfo('name'),
            '{account_url}'   => $this->getAccountUrl(),
            '{resources_list}' => $resourcesHtml,
            '{drip_schedule}' => $dripHtml,
        ];
    }

    /**
     * Get the account page URL.
     */
    private function getAccountUrl(): string
    {
        $settings = get_option('fchub_memberships_settings', []);
        if (!empty($settings['account_page_id'])) {
            $url = get_permalink((int) $settings['account_page_id']);
            if ($url) {
                return $url;
            }
        }

        return home_url('/account/');
    }

    /**
     * Whether this email type is enabled in settings.
     */
    private function isEnabled(): bool
    {
        $settings = get_option('fchub_memberships_settings', []);

        return !isset($settings[self::SETTING_KEY]) || $settings[self::SETTING_KEY] !== 'no';
    }

    /**
     * Get the email template, falling back to default.
     */
    public function getTemplate(): string
    {
        $settings  = get_option('fchub_memberships_settings', []);
        $templates = $settings['email_templates'] ?? [];

        if (!empty($templates[self::TEMPLATE_KEY])) {
            return $templates[self::TEMPLATE_KEY];
        }

        return static::getDefaultTemplate();
    }

    /**
     * Get the default HTML email body template.
     */
    public static function getDefaultTemplate(): string
    {
        return <<<'HTML'
<h2>Welcome to {plan_name}, {user_name}!</h2>
<p>Thank you for joining. Your membership is now active and you have immediate access to the following resources:</p>
{resources_list}
{drip_schedule}
<p>You can manage your membership and access all your content from your account:</p>
<p><a href="{account_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">Go to My Account</a></p>
<p>If you have any questions, feel free to reply to this email.</p>
<p>Best regards,<br>{site_name}</p>
HTML;
    }

    /**
     * Replace smart codes in a string.
     */
    private function replaceSmartCodes(string $content, array $codes): string
    {
        return str_replace(array_keys($codes), array_values($codes), $content);
    }

    /**
     * Wrap body content in a styled HTML wrapper.
     */
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

    /**
     * Dispatch the email via Action Scheduler (async) or wp_mail (sync).
     */
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
     * Get wp_mail headers with From name/email.
     *
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
