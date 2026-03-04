<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

final class Hooks
{
    public static function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = get_option(Constants::OPTION_SETTINGS, []);

        if (isset($settings[$key])) {
            return $settings[$key];
        }

        return $default ?? (Constants::DEFAULT_SETTINGS[$key] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $settings = get_option(Constants::OPTION_SETTINGS, []);
        return array_merge(Constants::DEFAULT_SETTINGS, is_array($settings) ? $settings : []);
    }

    public static function isEnabled(): bool
    {
        return self::getSetting('enabled', 'yes') === 'yes';
    }
}
