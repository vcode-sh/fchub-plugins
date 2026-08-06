<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\Plan;

defined('ABSPATH') || exit;

final class PlanSlug
{
    public const MAX_LENGTH = 100;

    public static function canonicalize(string $value, int $maxLength = self::MAX_LENGTH): string
    {
        $slug = sanitize_title($value);
        if ($slug === '' || $maxLength < 1) {
            return '';
        }

        return rtrim(utf8_uri_encode(rawurldecode($slug), $maxLength), '-');
    }

    public static function appendSuffix(string $base, int $counter): string
    {
        $suffix = '-' . max(1, $counter);
        $base = self::canonicalize($base, self::MAX_LENGTH - strlen($suffix));

        return $base === '' ? '' : $base . $suffix;
    }
}
