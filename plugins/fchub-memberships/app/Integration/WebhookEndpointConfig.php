<?php

declare(strict_types=1);

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

final class WebhookEndpointConfig
{
    private const STATUSES = ['draft', 'active', 'paused', 'deleted'];

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public static function migrateLegacy(array $settings): array
    {
        if (array_key_exists('webhook_endpoints', $settings)) {
            return $settings;
        }

        $secret = (string) ($settings['webhook_secret'] ?? '');
        $status = ($settings['webhook_enabled'] ?? 'no') === 'yes' ? 'active' : 'paused';
        $raw = (string) ($settings['webhook_urls'] ?? '');
        $urls = array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/\R/', $raw) ?: []
        ))));

        $settings['webhook_endpoints'] = array_map(
            static function (string $url) use ($secret, $status): array {
                $host = (string) (parse_url($url, PHP_URL_HOST) ?: 'Webhook endpoint');

                return [
                    'id' => 'legacy_' . substr(hash('sha256', $url), 0, 24),
                    'name' => $host,
                    'url' => $url,
                    'secret' => $secret,
                    'status' => $status,
                    'requires_rotation' => $secret !== '',
                    'last_test_status' => '',
                    'last_tested_at' => null,
                ];
            },
            $urls
        );

        return $settings;
    }

    /** @param array<string, mixed> $endpoint @return array<string, mixed> */
    public static function public(array $endpoint): array
    {
        return [
            'id' => (string) ($endpoint['id'] ?? ''),
            'name' => (string) ($endpoint['name'] ?? ''),
            'url' => (string) ($endpoint['url'] ?? ''),
            'status' => self::status($endpoint['status'] ?? 'draft'),
            'secret_configured' => (string) ($endpoint['secret'] ?? '') !== '',
            'requires_rotation' => ($endpoint['requires_rotation'] ?? false) === true,
            'last_test_status' => (string) ($endpoint['last_test_status'] ?? ''),
            'last_tested_at' => $endpoint['last_tested_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $settings @return list<array<string, mixed>> */
    public static function all(array $settings): array
    {
        $migrated = self::migrateLegacy($settings);
        $endpoints = is_array($migrated['webhook_endpoints'] ?? null)
            ? $migrated['webhook_endpoints']
            : [];

        return array_values(array_filter(
            array_map(
                static fn(mixed $endpoint): array => is_array($endpoint) ? $endpoint : [],
                $endpoints
            ),
            static fn(array $endpoint): bool => (string) ($endpoint['id'] ?? '') !== ''
                && (string) ($endpoint['url'] ?? '') !== ''
                && self::status($endpoint['status'] ?? 'draft') !== 'deleted'
        ));
    }

    /** @param array<string, mixed> $settings @return list<array<string, mixed>> */
    public static function active(array $settings): array
    {
        return array_values(array_filter(
            self::all($settings),
            static fn(array $endpoint): bool =>
                self::status($endpoint['status'] ?? 'draft') === 'active'
                && (string) ($endpoint['secret'] ?? '') !== ''
        ));
    }

    /** @param array<string, mixed> $settings @return array<string, mixed>|null */
    public static function find(array $settings, string $id): ?array
    {
        foreach (self::all($settings) as $endpoint) {
            if (hash_equals((string) ($endpoint['id'] ?? ''), $id)) {
                return $endpoint;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $settings @return array<string, mixed>|null */
    public static function findByUrl(array $settings, string $url): ?array
    {
        foreach (self::all($settings) as $endpoint) {
            if (hash_equals((string) ($endpoint['url'] ?? ''), $url)) {
                return $endpoint;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $settings */
    public static function secretForUrl(array $settings, string $url, bool $requireActive = false): ?string
    {
        $endpoint = self::findByUrl($settings, $url);
        if ($endpoint !== null) {
            if ($requireActive && self::status($endpoint['status'] ?? 'draft') !== 'active') {
                return null;
            }

            $secret = (string) ($endpoint['secret'] ?? '');
            return $secret === '' ? null : $secret;
        }

        $legacy = (string) ($settings['webhook_secret'] ?? '');
        return $legacy === '' ? null : $legacy;
    }

    private static function status(mixed $status): string
    {
        $status = (string) $status;
        return in_array($status, self::STATUSES, true) ? $status : 'draft';
    }
}
