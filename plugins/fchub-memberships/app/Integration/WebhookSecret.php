<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

final class WebhookSecret
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @return array{webhook_secret_configured:bool} */
    public static function metadata(array $settings): array
    {
        return ['webhook_secret_configured' => !empty($settings['webhook_secret'])];
    }

    public static function sign(string $body, array $settings): string
    {
        $secret = (string) ($settings['webhook_secret'] ?? '');
        return $secret === '' ? '' : hash_hmac('sha256', $body, $secret);
    }
}
