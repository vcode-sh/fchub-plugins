<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Core;

use FChubMemberships\Tests\Unit\PluginTestCase;

final class DistributionHygieneContractTest extends PluginTestCase
{
    public function test_clean_checkout_does_not_ignore_vite_build_output(): void
    {
        $pluginRoot = dirname(__DIR__, 3);
        $command = sprintf(
            'git -C %s check-ignore --no-index assets/dist/future-generated-asset.js',
            escapeshellarg($pluginRoot),
        );

        exec($command, $output, $exitCode);

        self::assertSame(1, $exitCode, 'assets/dist must remain visible to Git after npm run build.');
    }

    public function test_vite_manifest_references_a_complete_generated_bundle(): void
    {
        $distRoot = dirname(__DIR__, 3) . '/assets/dist';
        $manifest = json_decode(
            (string) file_get_contents($distRoot . '/.vite/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertNotEmpty($manifest);

        foreach ($manifest as $entryName => $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('file', $entry);
            self::assertFileExists($distRoot . '/' . $entry['file'], $entryName);

            foreach (['css', 'assets'] as $pathCollection) {
                foreach ($entry[$pathCollection] ?? [] as $path) {
                    self::assertFileExists($distRoot . '/' . $path, $entryName);
                }
            }

            foreach (['imports', 'dynamicImports'] as $importCollection) {
                foreach ($entry[$importCollection] ?? [] as $import) {
                    self::assertArrayHasKey($import, $manifest, $entryName);
                }
            }
        }
    }

    public function test_release_zip_keeps_vite_build_output(): void
    {
        $viteExclusions = array_filter(
            $this->ignoreLines('.distignore'),
            static fn (string $line): bool => str_starts_with($line, 'assets/dist'),
        );

        self::assertSame([], array_values($viteExclusions));
    }

    public function test_release_zip_excludes_local_development_outputs(): void
    {
        $distignore = $this->ignoreLines('.distignore');

        self::assertContains('smoke/', $distignore);
        self::assertContains('test-results/', $distignore);
        self::assertContains('coverage/', $distignore);
    }

    /**
     * @return list<string>
     */
    private function ignoreLines(string $filename): array
    {
        $contents = file(dirname(__DIR__, 3) . '/' . $filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        self::assertIsArray($contents);

        return array_values(array_filter(
            array_map('trim', $contents),
            static fn (string $line): bool => !str_starts_with($line, '#'),
        ));
    }
}
