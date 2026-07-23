<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

final class AccessApiCredential
{
    private const SECRET_PREFIX = 'fchub_';
    private const SECRET_BYTES = 24;
    private const DISPLAY_PREFIX_LENGTH = 12;

    /** @return array{secret:string, hash:string, prefix:string, rotated_at:string} */
    public static function generate(): array
    {
        $secret = self::SECRET_PREFIX . bin2hex(random_bytes(self::SECRET_BYTES));

        return [
            'secret' => $secret,
            'hash' => wp_hash_password($secret),
            'prefix' => substr($secret, 0, self::DISPLAY_PREFIX_LENGTH),
            'rotated_at' => (string) current_time('mysql', true),
        ];
    }

    public static function migratePlaintext(array $settings): array
    {
        if (!empty($settings['access_api_key_hash'])) {
            unset($settings['api_key']);
            return $settings;
        }

        $secret = (string) ($settings['api_key'] ?? '');
        unset($settings['api_key']);

        if ($secret === '') {
            return $settings;
        }

        $settings['access_api_key_hash'] = wp_hash_password($secret);
        $settings['access_api_key_prefix'] = substr($secret, 0, self::DISPLAY_PREFIX_LENGTH);
        $settings['access_api_key_rotated_at'] = (string) current_time('mysql', true);

        return $settings;
    }

    public static function verify(string $provided, array $settings): bool
    {
        if ($provided === '') {
            return false;
        }

        $hash = (string) ($settings['access_api_key_hash'] ?? '');
        return $hash !== '' && wp_check_password($provided, $hash);
    }

    /** @return array{configured:bool, prefix:?string, rotated_at:?string} */
    public static function metadata(array $settings): array
    {
        $configured = !empty($settings['access_api_key_hash']);

        return [
            'configured' => $configured,
            'prefix' => $configured ? (string) ($settings['access_api_key_prefix'] ?? '') : null,
            'rotated_at' => $configured ? (string) ($settings['access_api_key_rotated_at'] ?? '') : null,
        ];
    }

    public static function revoke(array $settings): array
    {
        unset(
            $settings['api_key'],
            $settings['access_api_key_hash'],
            $settings['access_api_key_prefix'],
            $settings['access_api_key_rotated_at']
        );

        return $settings;
    }
}
