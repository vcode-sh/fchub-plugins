<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Enums;

defined('ABSPATH') || exit;

enum ResolverSource: string
{
    case UrlParam = 'url_param';
    case UserMeta = 'user_meta';
    case Cookie   = 'cookie';
    case Geo      = 'geo';
    case Fallback = 'default';

    public function label(): string
    {
        return match ($this) {
            self::UrlParam => __('URL parameter', 'fchub-multi-currency'),
            self::UserMeta => __('User preference', 'fchub-multi-currency'),
            self::Cookie   => __('Cookie (guest)', 'fchub-multi-currency'),
            self::Geo      => __('Geolocation', 'fchub-multi-currency'),
            self::Fallback => __('Store default', 'fchub-multi-currency'),
        };
    }
}
