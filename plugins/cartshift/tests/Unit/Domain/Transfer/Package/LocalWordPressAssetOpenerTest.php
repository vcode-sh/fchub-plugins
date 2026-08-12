<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Package\LocalWordPressAssetOpener;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;

final class LocalWordPressAssetOpenerTest extends PluginTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-assets-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/2026', 0700);
        file_put_contents($this->root . '/2026/private file.pdf', 'asset bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/escape');
        @unlink($this->root . '/2026/private file.pdf');
        @rmdir($this->root . '/2026');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testSameUploadUrlAndAbsoluteUploadPathOpenTheExactLocalBytes(): void
    {
        $opener = new LocalWordPressAssetOpener('https://shop.test/wp-content/uploads', $this->root);
        $url = $opener(['locator' => 'https://shop.test/wp-content/uploads/2026/private%20file.pdf']);
        $path = $opener(['locator' => $this->root . '/2026/private file.pdf']);

        self::assertSame('asset bytes', stream_get_contents($url));
        self::assertSame('asset bytes', stream_get_contents($path));
        fclose($url);
        fclose($path);
    }

    public function testRemoteOriginEncodedTraversalAndSymlinkAreAllHardStops(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'cartshift-outside-');
        file_put_contents($outside, 'outside');
        symlink($outside, $this->root . '/escape');
        $opener = new LocalWordPressAssetOpener('https://shop.test/wp-content/uploads', $this->root);

        try {
            foreach ([
                'https://cdn.example.test/private.pdf',
                'https://shop.test/wp-content/uploads/%2e%2e/secret',
                $this->root . '/escape',
            ] as $locator) {
                try {
                    $opener(['locator' => $locator]);
                    self::fail('An unapproved asset locator escaped the local upload root.');
                } catch (SourceRecordException $exception) {
                    self::assertSame('asset_locator_unsupported', $exception->reasonCode);
                }
            }
        } finally {
            unlink($outside);
        }
    }
}
