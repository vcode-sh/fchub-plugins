<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

final class Constants
{
    public const REST_NAMESPACE = 'fchub-mc/v1';

    public const OPTION_SETTINGS = 'fchub_mc_settings';
    public const OPTION_DB_VERSION = 'fchub_mc_db_version';
    public const OPTION_FEATURE_FLAGS = 'fchub_mc_feature_flags';

    public const HOOK_PREFIX = 'fchub_mc/';

    public const COOKIE_KEY = 'fchub_mc_currency';
    public const COOKIE_DAYS = 90;

    public const USER_META_KEY = '_fchub_mc_currency';

    public const TABLE_RATE_HISTORY = 'fchub_mc_rate_history';
    public const TABLE_EVENT_LOG = 'fchub_mc_event_log';

    public const FC_ADDON_SLUG = 'fchub-multi-currency';

    public const CRON_REFRESH_RATES = 'fchub_mc_refresh_rates';

    public const DEFAULT_SETTINGS = [
        'enabled'                     => 'yes',
        'base_currency'               => 'USD',
        'display_currencies'          => [],
        'default_display_currency'    => 'USD',
        'url_param_enabled'           => 'yes',
        'url_param_key'               => 'currency',
        'cookie_enabled'              => 'yes',
        'cookie_lifetime_days'        => 90,
        'geo_enabled'                 => 'no',
        'geo_provider'                => 'ip_api',
        'geo_api_key'                 => '',
        'rate_provider'               => 'exchange_rate_api',
        'rate_provider_api_key'       => '',
        'rate_refresh_interval_hrs'   => 6,
        'stale_threshold_hrs'         => 24,
        'stale_fallback'              => 'base',
        'rounding_mode'               => 'half_up',
        'rounding_precision'          => 2,
        'checkout_disclosure_enabled' => 'yes',
        'checkout_disclosure_text'    => 'Your payment will be processed in {base_currency}.',
        'show_rate_freshness_badge'   => 'yes',
        'fluentcrm_enabled'           => 'yes',
        'fluentcrm_auto_create_tags'  => 'yes',
        'fluentcrm_tag_prefix'        => 'currency:',
        'fluentcrm_field_preferred'   => 'preferred_currency',
        'fluentcrm_field_last_order'  => 'last_order_display_currency',
        'fluentcrm_field_last_rate'   => 'last_order_fx_rate',
        'fluentcommunity_enabled'     => 'yes',
        'uninstall_remove_data'       => 'no',
    ];
}
