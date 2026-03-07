<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

use FChubMultiCurrency\Storage\EventLogRepository;

defined('ABSPATH') || exit;

final class EventLogger
{
    public static function log(string $event, ?int $userId = null, ?array $payload = null, ?string $ip = null): void
    {
        try {
            (new EventLogRepository())->log(
                $event,
                $userId,
                self::hashIp($ip),
                $payload,
            );
        } catch (\Throwable $e) {
            Logger::debug('Event log write skipped', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function hashIp(?string $ip = null): ?string
    {
        $ip = $ip ?? (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '');
        if ($ip === '') {
            return null;
        }

        return substr(hash('sha256', $ip), 0, 16);
    }
}
