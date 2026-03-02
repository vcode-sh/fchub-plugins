<?php

namespace FChubMemberships\Email;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Logger;

class DripContentUnlockedEmail
{
    private const SETTING_KEY = 'email_drip_unlocked';
    private const TEMPLATE_KEY = 'drip_content_unlocked';

    /**
     * Send the drip content unlocked notification.
     *
     * @param int   $userId WordPress user ID.
     * @param array $data {
     *     @type string      $resource_title Resource that was just unlocked.
     *     @type string      $resource_url   Direct link to the resource.
     *     @type string      $plan_title     Plan name.
     *     @type array|null  $next_drip_item Next upcoming drip {title, available_date}, or null.
     *     @type array       $progress       {unlocked: int, total: int}.
     * }
     */
    public function send(int $userId, array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $user = get_userdata($userId);
        if (!$user) {
            Logger::error('DripContentUnlockedEmail: user not found', "User ID: {$userId}");
            return;
        }

        $smartCodes = $this->buildSmartCodes($user, $data);
        $subject    = $this->replaceSmartCodes(
            __('New content available: {resource_title}', 'fchub-memberships'),
            $smartCodes
        );
        $body = $this->replaceSmartCodes($this->getTemplate(), $smartCodes);
        $body = $this->wrapHtml($body, $subject);

        $this->dispatch($user->user_email, $subject, $body);

        Logger::log(
            'Drip content unlocked email sent',
            sprintf('User %d, Resource: %s', $userId, $data['resource_title'] ?? '')
        );
    }

    /**
     * Build smart code replacements.
     */
    private function buildSmartCodes(\WP_User $user, array $data): array
    {
        $progress    = $data['progress'] ?? [];
        $unlocked    = (int) ($progress['unlocked'] ?? 0);
        $total       = (int) ($progress['total'] ?? 0);
        $progressHtml = '';
        if ($total > 0) {
            $pct = min(100, round(($unlocked / $total) * 100));
            $progressHtml = '<div style="margin:16px 0;">'
                . '<div style="background-color:#e5e7eb;border-radius:9999px;height:8px;overflow:hidden;">'
                . '<div style="background-color:#2563eb;height:100%;width:' . $pct . '%;border-radius:9999px;"></div>'
                . '</div>'
                . '<p style="margin:4px 0 0;font-size:13px;color:#6b7280;">'
                . sprintf(__('%d of %d items unlocked', 'fchub-memberships'), $unlocked, $total)
                . '</p></div>';
        }

        $nextDripHtml = '';
        if (!empty($data['next_drip_item'])) {
            $nextTitle = esc_html($data['next_drip_item']['title'] ?? '');
            $nextDate  = esc_html($data['next_drip_item']['available_date'] ?? '');
            $nextDripHtml = '<p style="padding:12px 16px;background-color:#f0f9ff;border-left:4px solid #2563eb;border-radius:4px;">'
                . '<strong>' . __('Coming next:', 'fchub-memberships') . '</strong> '
                . "{$nextTitle} &mdash; {$nextDate}</p>";
        }

        return [
            '{user_name}'       => $user->display_name ?: $user->user_login,
            '{user_email}'      => $user->user_email,
            '{plan_name}'       => $data['plan_title'] ?? '',
            '{site_name}'       => get_bloginfo('name'),
            '{resource_title}'  => $data['resource_title'] ?? '',
            '{resource_url}'    => esc_url($data['resource_url'] ?? '#'),
            '{progress}'        => $progressHtml,
            '{next_drip_item}'  => $nextDripHtml,
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
<h2>New Content Unlocked!</h2>
<p>Hi {user_name},</p>
<p>A new piece of content from your <strong>{plan_name}</strong> membership is now available:</p>
<h3 style="margin:16px 0 8px;">{resource_title}</h3>
<p><a href="{resource_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">View Content</a></p>
{progress}
{next_drip_item}
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
