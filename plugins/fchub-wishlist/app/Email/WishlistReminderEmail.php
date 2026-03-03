<?php

declare(strict_types=1);

namespace FChubWishlist\Email;

use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Support\Constants;
use FChubWishlist\Support\Logger;

defined('ABSPATH') || exit;

final class WishlistReminderEmail
{
    private const BATCH_SIZE = 100;
    private const MAX_DISPLAY_ITEMS = 5;
    private const TEMPLATE_KEY = 'wishlist_reminder';

    private WishlistItemRepository $items;

    public function __construct()
    {
        $this->items = new WishlistItemRepository();
    }

    public function sendPendingReminders(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $reminderDays = $this->getReminderDays();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($reminderDays * DAY_IN_SECONDS));

        global $wpdb;
        $listsTable = $wpdb->prefix . Constants::TABLE_LISTS;

        $offset = 0;

        while (true) {
            $wishlists = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$listsTable}
                 WHERE user_id IS NOT NULL
                   AND item_count > 0
                   AND updated_at < %s
                 LIMIT %d OFFSET %d",
                $cutoff,
                self::BATCH_SIZE,
                $offset
            ), ARRAY_A);

            if (!$wishlists) {
                break;
            }

            foreach ($wishlists as $wishlist) {
                $this->processWishlistReminder($wishlist, $reminderDays);
            }

            if (count($wishlists) < self::BATCH_SIZE) {
                break;
            }

            $offset += self::BATCH_SIZE;
        }
    }

    private function processWishlistReminder(array $wishlist, int $reminderDays): void
    {
        $userId = (int) $wishlist['user_id'];

        $lastReminder = get_user_meta($userId, '_fchub_wishlist_last_reminder', true);
        if ($lastReminder && strtotime($lastReminder) > strtotime("-{$reminderDays} days")) {
            return;
        }

        $user = get_userdata($userId);
        if (!$user || !$user->user_email) {
            return;
        }

        $items = $this->items->getItemsWithProductData((int) $wishlist['id']);
        if (empty($items)) {
            return;
        }

        $this->send($user, $items);
        update_user_meta($userId, '_fchub_wishlist_last_reminder', current_time('mysql'));
    }

    /**
     * @param \WP_User $user
     * @param array<int, array<string, mixed>> $items
     */
    private function send(\WP_User $user, array $items): void
    {
        $smartCodes = $this->buildSmartCodes($user, $items);
        $subject = $this->replaceSmartCodes(
            __('You have items waiting in your wishlist at {site_name}', 'fchub-wishlist'),
            $smartCodes
        );
        $body = $this->replaceSmartCodes($this->getTemplate(), $smartCodes);
        $body = $this->wrapHtml($body, $subject);

        /**
         * Filter the reminder email arguments before sending.
         *
         * @param array{to: string, subject: string, body: string, headers: string[]} $emailArgs
         * @param \WP_User $user
         * @param array<int, array<string, mixed>> $items
         */
        $emailArgs = apply_filters('fchub_wishlist/reminder_email_args', [
            'to'      => $user->user_email,
            'subject' => $subject,
            'body'    => $body,
            'headers' => $this->getHeaders(),
        ], $user, $items);

        $this->dispatch(
            $emailArgs['to'],
            $emailArgs['subject'],
            $emailArgs['body'],
            $emailArgs['headers']
        );

        Logger::info('Wishlist reminder email dispatched', [
            'user_id' => $user->ID,
            'email'   => $user->user_email,
            'items'   => count($items),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildSmartCodes(\WP_User $user, array $items): array
    {
        $itemListHtml = '<ul style="padding-left:20px;margin:16px 0;">';
        $displayed = 0;

        foreach ($items as $item) {
            if ($displayed >= self::MAX_DISPLAY_ITEMS) {
                break;
            }

            $title = esc_html($item['product_title'] ?: __('(Unknown product)', 'fchub-wishlist'));
            if (!empty($item['variant_title'])) {
                $title .= ' &mdash; ' . esc_html($item['variant_title']);
            }

            $productUrl = get_permalink($item['product_id'] ?? 0);
            if ($productUrl) {
                $itemListHtml .= "<li style=\"margin-bottom:8px;\"><a href=\"{$productUrl}\" style=\"color:#2563eb;text-decoration:none;\">{$title}</a></li>";
            } else {
                $itemListHtml .= "<li style=\"margin-bottom:8px;\">{$title}</li>";
            }

            $displayed++;
        }

        $remaining = count($items) - $displayed;
        if ($remaining > 0) {
            $moreText = sprintf(
                /* translators: %d: number of additional items */
                esc_html__('...and %d more items', 'fchub-wishlist'),
                $remaining
            );
            $itemListHtml .= "<li style=\"margin-bottom:8px;color:#6b7280;\">{$moreText}</li>";
        }

        $itemListHtml .= '</ul>';

        return [
            '{user_name}'    => $user->display_name ?: $user->user_login,
            '{item_list}'    => $itemListHtml,
            '{item_count}'   => (string) count($items),
            '{wishlist_url}' => home_url('/'),
            '{site_name}'    => get_bloginfo('name'),
        ];
    }

    private function replaceSmartCodes(string $content, array $codes): string
    {
        return str_replace(array_keys($codes), array_values($codes), $content);
    }

    public function getTemplate(): string
    {
        $settings  = get_option(Constants::OPTION_SETTINGS, []);
        $templates = $settings['email_templates'] ?? [];

        if (!empty($templates[self::TEMPLATE_KEY])) {
            return $templates[self::TEMPLATE_KEY];
        }

        return static::getDefaultTemplate();
    }

    public static function getDefaultTemplate(): string
    {
        return <<<'HTML'
<h2>Hi {user_name},</h2>
<p>You have {item_count} items waiting in your wishlist:</p>
{item_list}
<p>Don't let them slip away &mdash; visit your wishlist to check availability and prices.</p>
<p><a href="{wishlist_url}" style="display:inline-block;padding:12px 24px;
background-color:#2563eb;color:#ffffff;text-decoration:none;
border-radius:6px;font-weight:600;">View My Wishlist</a></p>
<p>Best regards,<br>{site_name}</p>
HTML;
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
<table role="presentation" width="600" cellspacing="0" cellpadding="0"
style="background-color:#ffffff;border-radius:8px;overflow:hidden;
box-shadow:0 1px 3px rgba(0,0,0,0.1);">
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
     * Dispatch via Action Scheduler (async) or wp_mail (sync fallback).
     *
     * @param string[] $headers
     */
    private function dispatch(string $to, string $subject, string $body, array $headers): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('fchub_wishlist_send_email', [
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
                'headers' => $headers,
            ]);
            return;
        }

        $sent = wp_mail($to, $subject, $body, $headers);
        if (!$sent) {
            Logger::error('Wishlist reminder email failed (sync)', [
                'to' => $to,
            ]);
        }
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

    private function isEnabled(): bool
    {
        $settings = get_option(Constants::OPTION_SETTINGS, []);
        $settingsEnabled = ($settings['email_reminder_enabled'] ?? 'no') === 'yes';

        return (bool) apply_filters('fchub_wishlist/reminder_email_enabled', $settingsEnabled);
    }

    private function getReminderDays(): int
    {
        $settings = get_option(Constants::OPTION_SETTINGS, []);
        return max(1, (int) ($settings['email_reminder_days'] ?? 14));
    }
}
