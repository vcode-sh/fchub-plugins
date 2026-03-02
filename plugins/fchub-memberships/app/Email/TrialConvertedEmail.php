<?php

namespace FChubMemberships\Email;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Logger;

class TrialConvertedEmail
{
    private const SETTING_KEY = 'email_trial_converted';
    private const TEMPLATE_KEY = 'trial_converted';

    /**
     * Send the trial converted to paid notification.
     *
     * @param int   $userId WordPress user ID.
     * @param array $data {
     *     @type string $plan_title Plan name.
     *     @type string $expires_at New expiry date (Y-m-d H:i:s).
     * }
     */
    public function send(int $userId, array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $user = get_userdata($userId);
        if (!$user) {
            Logger::error('TrialConvertedEmail: user not found', "User ID: {$userId}");
            return;
        }

        $smartCodes = $this->buildSmartCodes($user, $data);
        $subject = $this->replaceSmartCodes(
            __('Your {plan_name} membership is now active!', 'fchub-memberships'),
            $smartCodes
        );
        $body = $this->replaceSmartCodes($this->getTemplate(), $smartCodes);
        $body = $this->wrapHtml($body, $subject);

        $this->dispatch($user->user_email, $subject, $body);

        Logger::log(
            'Trial converted email sent',
            sprintf('User %d, Plan: %s', $userId, $data['plan_title'] ?? '')
        );
    }

    private function buildSmartCodes(\WP_User $user, array $data): array
    {
        $expiresFormatted = '';
        if (!empty($data['expires_at'])) {
            $expiresFormatted = wp_date(
                get_option('date_format') . ' ' . get_option('time_format'),
                strtotime($data['expires_at'])
            );
        }

        return [
            '{user_name}'   => $user->display_name ?: $user->user_login,
            '{user_email}'  => $user->user_email,
            '{plan_name}'   => $data['plan_title'] ?? '',
            '{site_name}'   => get_bloginfo('name'),
            '{expires_at}'  => $expiresFormatted ?: __('Lifetime', 'fchub-memberships'),
            '{account_url}' => $this->getAccountUrl(),
        ];
    }

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

    private function isEnabled(): bool
    {
        $settings = get_option('fchub_memberships_settings', []);

        return !isset($settings[self::SETTING_KEY]) || $settings[self::SETTING_KEY] !== 'no';
    }

    public function getTemplate(): string
    {
        $settings = get_option('fchub_memberships_settings', []);
        $templates = $settings['email_templates'] ?? [];

        if (!empty($templates[self::TEMPLATE_KEY])) {
            return $templates[self::TEMPLATE_KEY];
        }

        return static::getDefaultTemplate();
    }

    public static function getDefaultTemplate(): string
    {
        return <<<'HTML'
<h2>Welcome aboard, {user_name}!</h2>
<p>Your trial has been successfully converted to a full <strong>{plan_name}</strong> membership.</p>
<p>Your membership is now active and will remain valid until <strong>{expires_at}</strong>.</p>
<p>You can manage your membership from your account:</p>
<p><a href="{account_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">Go to My Account</a></p>
<p>Thank you for your support!</p>
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
        $fromName = get_bloginfo('name');
        $fromEmail = get_option('admin_email');

        return [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$fromName} <{$fromEmail}>",
        ];
    }
}
