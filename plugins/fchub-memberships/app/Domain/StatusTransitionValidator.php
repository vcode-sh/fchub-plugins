<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

class StatusTransitionValidator
{
    private const TRANSITIONS = [
        'active'  => ['expired', 'revoked', 'paused'],
        'paused'  => ['active', 'revoked', 'expired'],
        'expired' => ['active'],
        'revoked' => ['active'],
    ];

    public static function isValid(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function getValidTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::isValid($from, $to)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid status transition from "%s" to "%s"', esc_html($from), esc_html($to))
            );
        }
    }

    public static function getAllStatuses(): array
    {
        return ['active', 'paused', 'expired', 'revoked'];
    }
}
