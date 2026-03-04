<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;

defined('ABSPATH') || exit;

final class RatesCacheStore
{
    private const CACHE_GROUP = 'fchub_mc_rates';
    private const TTL = 3600;

    public function get(string $baseCurrency, string $quoteCurrency): ?ExchangeRate
    {
        $key = self::cacheKey($baseCurrency, $quoteCurrency);
        $cached = wp_cache_get($key, self::CACHE_GROUP);

        if ($cached === false || !is_array($cached)) {
            return null;
        }

        return ExchangeRate::from($cached);
    }

    public function set(ExchangeRate $rate): void
    {
        $key = self::cacheKey($rate->baseCurrency, $rate->quoteCurrency);

        wp_cache_set($key, [
            'base_currency'  => $rate->baseCurrency,
            'quote_currency' => $rate->quoteCurrency,
            'rate'           => $rate->rate,
            'provider'       => $rate->provider->value,
            'fetched_at'     => $rate->fetchedAt,
        ], self::CACHE_GROUP, self::TTL);
    }

    public function flush(): void
    {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
        }
    }

    private static function cacheKey(string $base, string $quote): string
    {
        return strtoupper($base) . '_' . strtoupper($quote);
    }
}
