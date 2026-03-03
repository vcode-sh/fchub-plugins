<?php

declare(strict_types=1);

namespace FChubWishlist\Email;

use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Support\Constants;

defined('ABSPATH') || exit;

final class WishlistReminderEmail
{
    private WishlistRepository $wishlists;
    private WishlistItemRepository $items;

    public function __construct()
    {
        $this->wishlists = new WishlistRepository();
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

        $wishlists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$listsTable}
             WHERE user_id IS NOT NULL
               AND item_count > 0
               AND updated_at < %s",
            $cutoff
        ), ARRAY_A);

        if (!$wishlists) {
            return;
        }

        foreach ($wishlists as $wishlist) {
            $userId = (int) $wishlist['user_id'];

            // Skip if already reminded (use user meta as a flag)
            $lastReminder = get_user_meta($userId, '_fchub_wishlist_last_reminder', true);
            if ($lastReminder && strtotime($lastReminder) > strtotime("-{$reminderDays} days")) {
                continue;
            }

            $user = get_userdata($userId);
            if (!$user || !$user->user_email) {
                continue;
            }

            $items = $this->items->getItemsWithProductData((int) $wishlist['id']);
            if (empty($items)) {
                continue;
            }

            $this->send($user, $items);
            update_user_meta($userId, '_fchub_wishlist_last_reminder', current_time('mysql'));
        }
    }

    /**
     * @param \WP_User $user
     * @param array<int, array<string, mixed>> $items
     */
    private function send(\WP_User $user, array $items): void
    {
        $blogName = get_bloginfo('name');
        $subject = sprintf(
            /* translators: %s: site name */
            __('You have items waiting in your wishlist at %s', 'fchub-wishlist'),
            $blogName
        );

        $itemList = '';
        $maxDisplay = 5;
        $displayed = 0;

        foreach ($items as $item) {
            if ($displayed >= $maxDisplay) {
                break;
            }
            $title = $item['product_title'] ?: __('(Unknown product)', 'fchub-wishlist');
            if (!empty($item['variant_title'])) {
                $title .= ' - ' . $item['variant_title'];
            }
            $itemList .= '- ' . esc_html($title) . "\n";
            $displayed++;
        }

        $remaining = count($items) - $displayed;
        if ($remaining > 0) {
            $itemList .= sprintf(
                /* translators: %d: number of additional items */
                __('...and %d more items', 'fchub-wishlist'),
                $remaining
            ) . "\n";
        }

        $shopUrl = home_url('/');

        $message = sprintf(
            /* translators: 1: user display name, 2: item list, 3: shop URL */
            __(
                "Hi %1\$s,\n\nYou have items waiting in your wishlist:\n\n%2\$s\nVisit your wishlist: %3\$s\n\nBest regards,\n%4\$s",
                'fchub-wishlist'
            ),
            $user->display_name,
            $itemList,
            $shopUrl,
            $blogName
        );

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        /**
         * Filter the reminder email arguments before sending.
         *
         * @param array{to: string, subject: string, message: string, headers: array<int, string>} $emailArgs
         * @param \WP_User $user
         * @param array<int, array<string, mixed>> $items
         */
        $emailArgs = apply_filters('fchub_wishlist/reminder_email_args', [
            'to'      => $user->user_email,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
        ], $user, $items);

        wp_mail(
            $emailArgs['to'],
            $emailArgs['subject'],
            $emailArgs['message'],
            $emailArgs['headers']
        );
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
