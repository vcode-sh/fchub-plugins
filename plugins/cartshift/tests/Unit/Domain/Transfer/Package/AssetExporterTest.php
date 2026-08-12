<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Package\AssetExporter;
use CartShift\Domain\Transfer\Package\AuthenticatedAssetSourceAdapter;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;

final class AssetExporterTest extends PluginTestCase
{
    private string $root;
    private string $uploads;
    private string $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/cartshift-assets-' . bin2hex(random_bytes(8));
        $this->uploads = $this->root . '/uploads';
        $this->package = $this->root . '/package';
        mkdir($this->uploads, 0700, true);
        mkdir($this->package, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testTwoDifferentFilesWithSameBasenameRemainDistinct(): void
    {
        $left = $this->source('a/manual.pdf', "%PDF-1.4\nleft");
        $right = $this->source('b/manual.pdf', "%PDF-1.4\nright");
        $exporter = new AssetExporter($this->package, $this->uploads);

        $leftAsset = $exporter->export($left);
        $rightAsset = $exporter->export($right);

        self::assertNotSame($leftAsset->sha256, $rightAsset->sha256);
        self::assertFileExists($this->package . '/assets/' . $leftAsset->sha256);
        self::assertFileExists($this->package . '/assets/' . $rightAsset->sha256);
        self::assertSame('manual.pdf', $leftAsset->originalName);
        self::assertSame('application/pdf', $leftAsset->mimeType);
    }

    public function testIdenticalContentIsReusedAndStoredPrivately(): void
    {
        $left = $this->source('a/photo.jpg', "\xff\xd8\xffshared");
        $right = $this->source('b/copy.jpg', "\xff\xd8\xffshared");
        $exporter = new AssetExporter($this->package, $this->uploads);

        $first = $exporter->export($left);
        $target = $this->package . '/assets/' . $first->sha256;
        $inode = fileinode($target);
        $second = $exporter->export($right);

        self::assertSame($first->sha256, $second->sha256);
        self::assertSame($inode, fileinode($target));
        self::assertSame(0600, fileperms($target) & 0777);
        self::assertSame([], glob($this->package . '/assets/*.partial') ?: []);
    }

    public function testMissingOutsideAndSymlinkSourcesFailClosed(): void
    {
        $exporter = new AssetExporter($this->package, $this->uploads);

        foreach ([$this->uploads . '/missing.pdf', $this->root . '/outside.pdf'] as $path) {
            if (str_contains($path, 'outside')) {
                file_put_contents($path, 'outside');
            }

            try {
                $exporter->export($path);
                self::fail('An unavailable or unapproved asset was exported.');
            } catch (SourceRecordException $exception) {
                self::assertContains($exception->reasonCode, ['asset_missing', 'asset_source_unapproved']);
            }
        }

        $outside = $this->root . '/outside.pdf';
        $link = $this->uploads . '/linked.pdf';
        symlink($outside, $link);

        try {
            $exporter->export($link);
            self::fail('A symlinked source was exported.');
        } catch (SourceRecordException $exception) {
            self::assertSame('asset_source_unapproved', $exception->reasonCode);
        }
    }

    public function testExpectedHashAndExistingCollisionAreVerified(): void
    {
        $source = $this->source('manual.pdf', "%PDF-1.4\nexpected");
        $exporter = new AssetExporter($this->package, $this->uploads);

        try {
            $exporter->export($source, expectedSha256: str_repeat('0', 64));
            self::fail('An unexpected source hash was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame('asset_hash_mismatch', $exception->reasonCode);
        }

        $expected = hash_file('sha256', $source);
        file_put_contents($this->package . '/assets/' . $expected, 'collision');

        try {
            $exporter->export($source);
            self::fail('A corrupt content-address collision was reused.');
        } catch (SourceRecordException $exception) {
            self::assertSame('asset_hash_mismatch', $exception->reasonCode);
        }
    }

    public function testPrivateRemoteLocatorRequiresNamedAuthenticatedAdapter(): void
    {
        $locator = 'private://bucket/manual.pdf';
        $withoutAdapter = new AssetExporter($this->package, $this->uploads);

        try {
            $withoutAdapter->export($locator, sourceKind: 'customer-downloads');
            self::fail('A private locator was opened without an approved adapter.');
        } catch (SourceRecordException $exception) {
            self::assertSame('asset_source_unsupported', $exception->reasonCode);
        }

        $adapter = new class implements AuthenticatedAssetSourceAdapter {
            public function open(string $locator): mixed
            {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, "remote secret bytes\n");
                rewind($stream);
                return $stream;
            }

            public function originalName(string $locator): string
            {
                return 'manual.pdf';
            }
        };
        $exporter = new AssetExporter(
            $this->package,
            $this->uploads,
            ['customer-downloads' => $adapter],
        );

        $asset = $exporter->export($locator, sourceKind: 'customer-downloads');

        self::assertSame('customer-downloads', $asset->sourceKind);
        self::assertSame('manual.pdf', $asset->originalName);
        self::assertStringNotContainsString('private://', json_encode($asset->toArray(), JSON_THROW_ON_ERROR));
    }

    private function source(string $relative, string $contents): string
    {
        $path = $this->uploads . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
        return $path;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            return;
        }

        foreach (new \FilesystemIterator($path) as $entry) {
            $this->removeTree($entry->getPathname());
        }
        rmdir($path);
    }
}
