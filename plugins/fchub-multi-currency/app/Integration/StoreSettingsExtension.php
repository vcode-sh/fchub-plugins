<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class StoreSettingsExtension
{
    public static function register(): void
    {
        add_filter('fluent_cart/store_settings/values', [self::class, 'addValues']);
        add_filter('fluent_cart/store_settings/fields', [self::class, 'addFields']);
        add_filter('fluent_cart/store_settings/sanitizer', [self::class, 'sanitize']);
        add_filter('fluent_cart/store_settings/rules', [self::class, 'rules']);
    }

    public static function addValues(array $values): array
    {
        $settings = get_option(Constants::OPTION_SETTINGS, []);
        $settings = is_array($settings) ? $settings : [];

        $values['fchub_mc_enabled'] = $settings['enabled'] ?? 'yes';
        $values['fchub_mc_base_currency'] = $settings['base_currency'] ?? 'USD';

        return $values;
    }

    public static function addFields(array $fields): array
    {
        // Fields are registered via the dedicated admin page
        return $fields;
    }

    public static function sanitize(array $sanitizers): array
    {
        $sanitizers['fchub_mc_enabled'] = 'sanitize_text_field';
        $sanitizers['fchub_mc_base_currency'] = 'sanitize_text_field';

        return $sanitizers;
    }

    public static function rules(array $rules): array
    {
        return $rules;
    }
}
