<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\CsvSanitizer;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class CsvSanitizerTest extends PluginTestCase
{
    public function test_formula_prefixes_are_neutralised(): void
    {
        foreach ([
            '=SUM(1,1)',
            '+cmd',
            '-cmd',
            '@cmd',
            "\tcmd",
            "\rcmd",
            "\ncmd",
        ] as $value) {
            self::assertSame("'" . $value, CsvSanitizer::sanitizeCell($value));
        }
    }

    public function test_ordinary_and_empty_values_are_unchanged(): void
    {
        self::assertSame('Alice Example', CsvSanitizer::sanitizeCell('Alice Example'));
        self::assertSame('', CsvSanitizer::sanitizeCell(''));
    }
}
