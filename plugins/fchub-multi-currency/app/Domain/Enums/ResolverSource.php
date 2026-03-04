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
            self::UrlParam => 'URL parameter',
            self::UserMeta => 'User preference',
            self::Cookie   => 'Cookie (guest)',
            self::Geo      => 'Geolocation',
            self::Fallback => 'Store default',
        };
    }
}
