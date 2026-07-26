<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\Providers\ProviderRegistry;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class RateSchedule
{
    public static function sync(OptionStore $optionStore): void
    {
        $provider = RateProvider::tryFrom((string) $optionStore->get('rate_provider', 'manual'));
        $hasRequiredKey = $provider === null
            || !$provider->requiresApiKey()
            || trim((string) $optionStore->get('rate_provider_api_key', '')) !== '';

        if (!ProviderRegistry::usesRemoteProvider($optionStore) || !$hasRequiredKey) {
            wp_clear_scheduled_hook(Constants::CRON_REFRESH_RATES);
            return;
        }

        if (!wp_next_scheduled(Constants::CRON_REFRESH_RATES)) {
            wp_schedule_event(time(), 'fchub_mc_rate_interval', Constants::CRON_REFRESH_RATES);
        }
    }
}
