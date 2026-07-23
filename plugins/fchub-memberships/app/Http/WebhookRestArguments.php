<?php

declare(strict_types=1);

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

final class WebhookRestArguments
{
    /** @return array<string, array<string, mixed>> */
    public static function deliveries(): array
    {
        return [
            'page' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'default' => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => [self::class, 'positiveInteger'],
            ],
            'per_page' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'default' => 20,
                'sanitize_callback' => 'absint',
                'validate_callback' => [self::class, 'pageSize'],
            ],
            'status' => [
                'required' => false,
                'type' => 'string',
                'default' => '',
                'enum' => ['', 'pending', 'processing', 'retrying', 'succeeded', 'failed'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function retry(): array
    {
        return [
            'id' => [
                'required' => true,
                'type' => 'integer',
                'minimum' => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => [self::class, 'positiveInteger'],
            ],
        ];
    }

    public static function positiveInteger(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0;
    }

    public static function pageSize(mixed $value): bool
    {
        return self::positiveInteger($value) && (int) $value <= 100;
    }
}
