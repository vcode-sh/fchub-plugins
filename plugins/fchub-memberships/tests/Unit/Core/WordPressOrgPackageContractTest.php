<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Core;

use FChubMemberships\Tests\Unit\PluginTestCase;

final class WordPressOrgPackageContractTest extends PluginTestCase
{
    private string $packageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageRoot = sys_get_temp_dir() . '/fchub-memberships-package-' . bin2hex(random_bytes(8));
        mkdir($this->packageRoot, 0777, true);

        $pluginRoot = dirname(__DIR__, 3);
        $command = sprintf(
            'rsync -a --exclude-from=%s %s/ %s/',
            escapeshellarg($pluginRoot . '/.distignore'),
            escapeshellarg($pluginRoot),
            escapeshellarg($this->packageRoot),
        );
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->packageRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->packageRoot);

        parent::tearDown();
    }

    public function test_archive_contains_public_source_build_inputs_and_licences(): void
    {
        foreach ([
            'fchub-memberships.php',
            'readme.txt',
            'LICENSE',
            'licenses/Inter-OFL.txt',
            'licenses/THIRD-PARTY.txt',
            'resources/admin/main.js',
            'resources/admin/styles/variables.css',
            'resources/admin/fonts/inter-latin.woff2',
            'resources/admin/fonts/inter-latin-ext.woff2',
            'package.json',
            'package-lock.json',
            'vite.config.js',
        ] as $path) {
            self::assertFileExists($this->packageRoot . '/' . $path, $path);
        }

        self::assertNotSame(
            [],
            glob($this->packageRoot . '/assets/dist/assets/inter-latin-*.woff2') ?: [],
            'The built archive must contain the emitted Inter font assets.',
        );
    }

    public function test_archive_excludes_development_and_legacy_distribution_files(): void
    {
        foreach ([
            'tests',
            'node_modules',
            'vendor',
            'lib/GitHubUpdater.php',
            '.DS_Store',
        ] as $path) {
            self::assertFileDoesNotExist($this->packageRoot . '/' . $path, $path);
        }
    }

    public function test_wordpress_org_readme_has_release_metadata_and_required_disclosures(): void
    {
        $readmePath = $this->packageRoot . '/readme.txt';
        $readme = (string) file_get_contents($readmePath);

        self::assertLessThan(10_000, filesize($readmePath));
        self::assertStringContainsString('=== FCHub Memberships ===', $readme);
        self::assertStringContainsString('Contributors: vcodesh', $readme);
        self::assertStringContainsString('Stable tag: 1.4.1', $readme);
        self::assertStringContainsString('Requires PHP: 8.3', $readme);
        self::assertStringContainsString('== External services ==', $readme);
        self::assertStringContainsString('== Privacy ==', $readme);
        self::assertStringContainsString(
            'https://github.com/vcode-sh/fchub-plugins/tree/main/plugins/fchub-memberships',
            $readme,
        );
        self::assertStringContainsString('npm ci && npm run build', $readme);
    }
}
