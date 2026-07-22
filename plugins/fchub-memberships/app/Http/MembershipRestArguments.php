<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

final class MembershipRestArguments
{
    public static function grant(): array
    {
        return [
            'user_id' => self::positiveIdArgument(),
            'plan_id' => self::positiveIdArgument(),
            'expires_at' => self::dateArgument(false, true),
        ];
    }

    public static function revoke(): array
    {
        return [
            'user_id' => self::positiveIdArgument(),
            'plan_id' => self::positiveIdArgument(),
            'reason' => self::reasonArgument(),
        ];
    }

    public static function pause(): array
    {
        return [
            'grant_id' => self::positiveIdArgument(),
            'reason' => self::reasonArgument(),
        ];
    }

    public static function resume(): array
    {
        return [
            'grant_id' => self::positiveIdArgument(),
        ];
    }

    public static function extend(): array
    {
        return [
            'user_id' => self::positiveIdArgument(),
            'plan_id' => self::positiveIdArgument(),
            'expires_at' => self::dateArgument(true, false),
        ];
    }

    public static function bulkGrant(): array
    {
        return [
            'user_ids' => self::userIdsArgument(),
            'plan_id' => self::positiveIdArgument(),
            'expires_at' => self::dateArgument(false, true),
        ];
    }

    public static function bulkRevoke(): array
    {
        return [
            'user_ids' => self::userIdsArgument(),
            'plan_id' => self::positiveIdArgument(),
            'reason' => self::reasonArgument(),
        ];
    }

    public static function bulkExtend(): array
    {
        return [
            'user_ids' => self::userIdsArgument(),
            'plan_id' => self::positiveIdArgument(),
            'expires_at' => self::dateArgument(true, false),
        ];
    }

    public static function positiveId(mixed $value): bool
    {
        $isInteger = function_exists('rest_is_integer')
            ? rest_is_integer($value)
            : is_numeric($value) && round((float) $value) === (float) $value;

        return $isInteger && (float) $value > 0;
    }

    public static function isoMysqlDate(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (!is_string($value) || $value === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    public static function sanitizeIsoMysqlDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return sanitize_text_field((string) $value);
    }

    public static function userIds(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 100) {
            return false;
        }

        foreach ($value as $userId) {
            if (!self::positiveId($userId)) {
                return false;
            }
        }

        return count(array_unique($value, SORT_REGULAR)) === count($value);
    }

    public static function sanitizeUserIds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_map('absint', $value));
    }

    private static function positiveIdArgument(): array
    {
        return [
            'required' => true,
            'type' => 'integer',
            'minimum' => 1,
            'sanitize_callback' => 'absint',
            'validate_callback' => [self::class, 'positiveId'],
        ];
    }

    private static function dateArgument(bool $required, bool $nullable): array
    {
        return [
            'required' => $required,
            'type' => $nullable ? ['string', 'null'] : 'string',
            'sanitize_callback' => [self::class, 'sanitizeIsoMysqlDate'],
            'validate_callback' => [self::class, 'isoMysqlDate'],
        ];
    }

    private static function reasonArgument(): array
    {
        return [
            'required' => false,
            'type' => 'string',
            'maxLength' => 500,
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => 'rest_validate_request_arg',
        ];
    }

    private static function userIdsArgument(): array
    {
        return [
            'required' => true,
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 100,
            'uniqueItems' => true,
            'items' => [
                'type' => 'integer',
                'minimum' => 1,
            ],
            'sanitize_callback' => [self::class, 'sanitizeUserIds'],
            'validate_callback' => [self::class, 'userIds'],
        ];
    }
}
