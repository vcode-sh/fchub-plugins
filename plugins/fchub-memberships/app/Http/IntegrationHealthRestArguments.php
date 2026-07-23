<?php

declare(strict_types=1);

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

final class IntegrationHealthRestArguments
{
    /** @return array<string, array<string, mixed>> */
    public static function reconcile(): array
    {
        return [
            'user_id' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => [self::class, 'positiveIdOrNull'],
            ],
            'scope' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['all'],
            ],
            'dry_run' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => [self::class, 'boolean'],
            ],
            'cursor' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 0,
                'default' => 0,
                'sanitize_callback' => 'absint',
                'validate_callback' => [self::class, 'nonNegativeInteger'],
            ],
            'watermark' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 0,
                'sanitize_callback' => 'absint',
                'validate_callback' => [self::class, 'nonNegativeIntegerOrNull'],
            ],
        ];
    }

    public static function positiveIdOrNull(mixed $value): bool
    {
        return $value === null || MembershipRestArguments::positiveId($value);
    }

    public static function boolean(mixed $value): bool
    {
        return !in_array($value, [false, 'false', '0', 0], true);
    }

    public static function nonNegativeInteger(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value >= 0;
    }

    public static function nonNegativeIntegerOrNull(mixed $value): bool
    {
        return $value === null || self::nonNegativeInteger($value);
    }
}
