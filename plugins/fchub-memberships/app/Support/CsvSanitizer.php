<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class CsvSanitizer
{
    public static function sanitizeCell(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r\n") !== false) {
            return "'" . $value;
        }

        return $value;
    }
}
