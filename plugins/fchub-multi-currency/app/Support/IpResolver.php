<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

/**
 * Resolves the real client IP behind reverse proxies (Cloudflare, load balancers).
 */
final class IpResolver
{
    /**
     * @var string[] Headers to check, in priority order.
     */
    private const HEADERS = [
        'HTTP_CF_CONNECTING_IP',  // Cloudflare
        'HTTP_X_FORWARDED_FOR',   // Generic reverse proxy
        'REMOTE_ADDR',            // Direct connection
    ];

    public static function resolve(): string
    {
        foreach (self::HEADERS as $header) {
            if (!isset($_SERVER[$header]) || $_SERVER[$header] === '') {
                continue;
            }

            $value = sanitize_text_field(wp_unslash((string) $_SERVER[$header]));

            // X-Forwarded-For can contain multiple IPs — take the first (client IP)
            if ($header === 'HTTP_X_FORWARDED_FOR' && str_contains($value, ',')) {
                $value = trim(explode(',', $value, 2)[0]);
            }

            if ($value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }
}
