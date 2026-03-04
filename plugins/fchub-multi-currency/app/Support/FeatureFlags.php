<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

final class FeatureFlags
{
    private const DEFAULTS = [
        'js_projection' => true,
        'geo_resolver'  => false,
    ];

    public static function isEnabled(string $flag): bool
    {
        $flags = get_option(Constants::OPTION_FEATURE_FLAGS, []);

        if (!is_array($flags)) {
            $flags = [];
        }

        return (bool) ($flags[$flag] ?? self::DEFAULTS[$flag] ?? false);
    }

    /**
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $flags = get_option(Constants::OPTION_FEATURE_FLAGS, []);

        if (!is_array($flags)) {
            $flags = [];
        }

        return array_merge(self::DEFAULTS, $flags);
    }
}
