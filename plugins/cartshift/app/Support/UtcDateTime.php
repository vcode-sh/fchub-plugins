<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

final class UtcDateTime
{
    public static function canonical(mixed $value): ?string
    {
        $timestamp = self::timestamp($value);
        return $timestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    public static function target(mixed $value): ?string
    {
        $timestamp = self::timestamp($value);
        return $timestamp === null ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function targetFromCanonical(string $value): string
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Canonical UTC timestamp is invalid.');
        }
        $target = self::target($value);
        if ($target === null) {
            throw new \InvalidArgumentException('Canonical UTC timestamp is empty.');
        }
        return $target;
    }

    private static function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_object($value) && is_callable([$value, 'getTimestamp'])) {
            return (int) $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            try {
                return (new \DateTimeImmutable($value))->getTimestamp();
            } catch (\Throwable $exception) {
                throw new \InvalidArgumentException('Date cannot be converted through a UTC epoch.', 0, $exception);
            }
        }
        throw new \InvalidArgumentException('Date cannot be converted through a UTC epoch.');
    }
}
