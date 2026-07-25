<?php

declare(strict_types=1);

namespace FChubHub\Tests\Unit;

use FChubHub\Support\AssetManifest;
use PHPUnit\Framework\TestCase;

final class AssetManifestTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDir = sys_get_temp_dir() . '/fchub-asset-manifest-' . uniqid('', true);
        mkdir($this->fixtureDir . '/.vite', 0777, true);
    }

    protected function tearDown(): void
    {
        $manifestPath = $this->fixtureDir . '/.vite/manifest.json';

        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }

        if (is_dir($this->fixtureDir . '/.vite')) {
            rmdir($this->fixtureDir . '/.vite');
        }

        if (is_dir($this->fixtureDir)) {
            rmdir($this->fixtureDir);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, array<string, mixed>> $manifest
     */
    private function writeManifest(array $manifest): string
    {
        $path = $this->fixtureDir . '/.vite/manifest.json';
        file_put_contents($path, (string) json_encode($manifest));

        return $path;
    }

    public function testResolveReturnsTheScriptStylesAndVersionForTheEntry(): void
    {
        $manifestPath = $this->writeManifest([
            'resources/admin/main.js' => [
                'file' => 'assets/fchub-admin.js',
                'css'  => ['assets/fchub-admin.css'],
            ],
        ]);

        $manifest = new AssetManifest($this->fixtureDir);
        $resolved = $manifest->resolve('resources/admin/main.js');

        self::assertSame([
            'script'  => 'assets/fchub-admin.js',
            'styles'  => ['assets/fchub-admin.css'],
            'version' => (string) filemtime($manifestPath),
        ], $resolved);
    }

    public function testResolveWalksImportsRecursivelyAndDedupesCssWithoutLoopingForever(): void
    {
        // main -> _a -> _b -> _a (cycle back to an already-visited entry).
        $this->writeManifest([
            'resources/admin/main.js' => [
                'file'    => 'assets/fchub-admin.js',
                'css'     => ['assets/main.css'],
                'imports' => ['_a.js'],
            ],
            '_a.js' => [
                'file'    => 'assets/a.js',
                'css'     => ['assets/a.css', 'assets/main.css'],
                'imports' => ['_b.js'],
            ],
            '_b.js' => [
                'file'    => 'assets/b.js',
                'css'     => ['assets/b.css'],
                'imports' => ['_a.js'],
            ],
        ]);

        $manifest = new AssetManifest($this->fixtureDir);
        $resolved = $manifest->resolve('resources/admin/main.js');

        self::assertSame(
            ['assets/main.css', 'assets/a.css', 'assets/b.css'],
            $resolved['styles']
        );
    }

    public function testResolveReturnsNullWhenTheManifestFileIsMissing(): void
    {
        $manifest = new AssetManifest($this->fixtureDir . '/does-not-exist');

        self::assertNull($manifest->resolve('resources/admin/main.js'));
    }

    public function testResolveReturnsNullWhenTheManifestContainsMalformedJson(): void
    {
        file_put_contents($this->fixtureDir . '/.vite/manifest.json', '{not valid json');

        $manifest = new AssetManifest($this->fixtureDir);

        self::assertNull($manifest->resolve('resources/admin/main.js'));
    }

    public function testResolveReturnsNullWhenTheManifestFileCannotBeRead(): void
    {
        $path = $this->writeManifest([
            'resources/admin/main.js' => ['file' => 'assets/fchub-admin.js'],
        ]);

        chmod($path, 0000);

        if (is_readable($path)) {
            // The current process can read regardless of permission bits —
            // typically because it's running as root. Restore and skip
            // rather than assert something the filesystem can't back up.
            chmod($path, 0644);
            self::markTestSkipped('This process ignores file permissions (likely running as root).');
        }

        try {
            $manifest = new AssetManifest($this->fixtureDir);

            self::assertNull($manifest->resolve('resources/admin/main.js'));
        } finally {
            chmod($path, 0644);
        }
    }

    public function testResolveReturnsNullWhenTheEntryKeyIsUnknown(): void
    {
        $this->writeManifest([
            'resources/admin/main.js' => [
                'file' => 'assets/fchub-admin.js',
            ],
        ]);

        $manifest = new AssetManifest($this->fixtureDir);

        self::assertNull($manifest->resolve('resources/admin/other.js'));
    }

    public function testResolveAcceptsATrailingSlashOnTheDistPath(): void
    {
        $this->writeManifest([
            'resources/admin/main.js' => [
                'file' => 'assets/fchub-admin.js',
            ],
        ]);

        $manifest = new AssetManifest($this->fixtureDir . '/');

        self::assertNotNull($manifest->resolve('resources/admin/main.js'));
    }

    public function testResolveReturnsNullWhenTheEntryHasNoCssImports(): void
    {
        $this->writeManifest([
            'resources/admin/main.js' => [
                'file' => 'assets/fchub-admin.js',
            ],
        ]);

        $manifest = new AssetManifest($this->fixtureDir);
        $resolved = $manifest->resolve('resources/admin/main.js');

        self::assertSame([], $resolved['styles']);
    }
}
