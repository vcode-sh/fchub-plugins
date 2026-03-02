<?php

namespace FChubMemberships\Email;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Logger;

class AccessRevokedEmail
{
    private const SETTING_KEY = 'email_access_revoked';
    private const TEMPLATE_KEY = 'access_revoked';

    /**
     * Send the access revoked notification.
     *
     * @param int   $userId WordPress user ID.
     * @param array $data {
     *     @type string $plan_title  Plan name.
     *     @type string $reason      Reason for revocation.
     *     @type string $support_url Support contact URL.
     * }
     */
    public function send(int $userId, array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $user = get_userdata($userId);
        if (!$user) {
            Logger::error('AccessRevokedEmail: user not found', "User ID: {$userId}");
            return;
        }

        $smartCodes = $this->buildSmartCodes($user, $data);
        $subject    = $this->replaceSmartCodes(
            __('Your {plan_name} access has been removed', 'fchub-memberships'),
            $smartCodes
        );
        $body = $this->replaceSmartCodes($this->getTemplate(), $smartCodes);
        $body = $this->wrapHtml($body, $subject);

        $this->dispatch($user->user_email, $subject, $body);

        Logger::log(
            'Access revoked email sent',
            sprintf('User %d, Plan: %s, Reason: %s', $userId, $data['plan_title'] ?? '', $data['reason'] ?? '')
        );
    }

    /**
     * Build smart code replacements.
     */
    private function buildSmartCodes(\WP_User $user, array $data): array
    {
        $supportUrl    = $data['support_url'] ?? '';
        $repurchaseUrl = home_url('/');

        if (empty($supportUrl)) {
            $supportUrl = home_url('/contact/');
        }

        return [
            '{user_name}'      => $user->display_name ?: $user->user_login,
            '{user_email}'     => $user->user_email,
            '{plan_name}'      => $data['plan_title'] ?? '',
            '{site_name}'      => get_bloginfo('name'),
            '{reason}'         => esc_html($data['reason'] ?? __('Your membership has ended.', 'fchub-memberships')),
            '{support_url}'    => esc_url($supportUrl),
            '{repurchase_url}' => esc_url($repurchaseUrl),
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
<h2>Access Removed</h2>
<p>Hi {user_name},</p>
<p>Your access to <strong>{plan_name}</strong> has been removed.</p>
<p><strong>Reason:</strong> {reason}</p>
<p>If you believe this was done in error or need help, please contact our support team:</p>
<p><a href="{support_url}" style="display:inline-block;padding:12px 24px;background-color:#6b7280;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">Contact Support</a></p>
<p>You can also re-purchase access at any time:</p>
<p><a href="{repurchase_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">Re-purchase Membership</a></p>
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
