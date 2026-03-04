<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Syncs currency preference to a FluentCommunity-namespaced user meta key.
 *
 * FluentCommunity stores profile data in fcom_xprofile.meta (serialized),
 * but has no built-in currency preference field. We write to a dedicated
 * WordPress user_meta key so that FluentCommunity add-ons or custom
 * templates can read the user's preferred display currency.
 */
final class FluentCommunitySync
{
    private const FC_META_KEY = '_fcom_preferred_currency';

    public static function register(): void
    {
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            return;
        }

        add_action('fchub_mc/context_switched', [self::class, 'onContextSwitched'], 10, 2);
    }

    public static function onContextSwitched(string $currencyCode, int $userId): void
    {
        if ($userId === 0) {
            return;
        }

        $settings = get_option(Constants::OPTION_SETTINGS, []);

        if (($settings['fluentcommunity_enabled'] ?? 'yes') !== 'yes') {
            return;
        }

        try {
            update_user_meta($userId, self::FC_META_KEY, strtoupper($currencyCode));

            do_action('fchub_mc/community_currency_updated', $currencyCode, $userId);

            Logger::debug('FluentCommunity currency synced', [
                'user_id'  => $userId,
                'currency' => $currencyCode,
            ]);
        } catch (\Throwable $e) {
            Logger::error('FluentCommunity sync failed', [
                'error'    => $e->getMessage(),
                'user_id'  => $userId,
                'currency' => $currencyCode,
            ]);
        }
    }
}
