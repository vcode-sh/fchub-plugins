<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WordPressOrgReadmeContractTest extends TestCase
{
    public function testReadmeDeclaresReleaseAndExactServiceDisclosure(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $readmePath = $pluginRoot . '/readme.txt';

        self::assertFileExists($readmePath);
        $readme = (string) file_get_contents($readmePath);

        foreach ([
            '=== FCHub Fakturownia ===',
            'Contributors: vcodesh',
            'Tags: fluentcart, invoices, ksef, accounting',
            'Requires at least: 7.0',
            'Tested up to: 7.0',
            'Stable tag: 1.1.2',
            'Requires PHP: 8.3',
            'License: GPLv2 or later',
            'https://fakturownia.pl/api-przyklady',
            'https://fakturownia.pl/regulamin',
            'https://fakturownia.pl/polityka-prywatnosci',
            'https://fakturownia.pl/regulamin-powierzenia-2026',
            'gov_save_and_send',
            'FCHub receives none of this data',
            '== External services ==',
            '== Privacy ==',
            '= 1.1.2 =',
        ] as $requiredText) {
            self::assertStringContainsString($requiredText, $readme);
        }
    }

    public function testPackageShipsDirectoryReadmeAndLicence(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $distIgnore = (string) file_get_contents($pluginRoot . '/.distignore');

        self::assertFileExists($pluginRoot . '/LICENSE');
        self::assertStringNotContainsString('*.md', $distIgnore);
        self::assertStringNotContainsString("\nreadme.txt\n", "\n{$distIgnore}\n");
        self::assertStringNotContainsString("\nLICENSE\n", "\n{$distIgnore}\n");
        self::assertStringContainsString("\nREADME.md\n", "\n{$distIgnore}\n");
    }
}
