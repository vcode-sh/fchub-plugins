<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class PlanStatus
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const ARCHIVED = 'archived';

    /** @var array<string, string> */
    private const ALIASES = [
        'draft' => self::INACTIVE,
    ];

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::INACTIVE,
            self::ARCHIVED,
        ];
    }

    public static function isValid(?string $status): bool
    {
        if ($status === null || $status === '') {
            return false;
        }

        return in_array(self::normalize($status, ''), self::all(), true);
    }

    public static function normalize(?string $status, string $fallback = self::ACTIVE): string
    {
        $normalized = self::normalizeNullable($status);

        return $normalized ?? $fallback;
    }

    public static function normalizeNullable(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $value = strtolower(trim((string) $status));
        if ($value === '') {
            return null;
        }

        return self::ALIASES[$value] ?? (in_array($value, self::all(), true) ? $value : null);
    }
}
