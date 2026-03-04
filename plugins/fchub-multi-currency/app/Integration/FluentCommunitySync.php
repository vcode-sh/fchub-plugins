<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class FluentCommunitySync
{
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
            do_action('fchub_mc/community_currency_updated', $currencyCode, $userId);
        } catch (\Throwable $e) {
            Logger::error('FluentCommunity sync failed', [
                'error'    => $e->getMessage(),
                'user_id'  => $userId,
                'currency' => $currencyCode,
            ]);
        }
    }
}
