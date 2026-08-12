<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Package\SourceInstanceRegistry;
use CartShift\Tests\Unit\PluginTestCase;

final class SourceInstanceRegistryTest extends PluginTestCase
{
    private string $root;
    protected function setUp(): void { parent::setUp(); $this->root = sys_get_temp_dir() . '/cartshift-registry-' . bin2hex(random_bytes(8)); mkdir($this->root, 0700); }
    protected function tearDown(): void { foreach (glob($this->root . '/*') ?: [] as $file) unlink($file); rmdir($this->root); parent::tearDown(); }

    public function testBindingRequiresExactApprovalAndCannotBeRebound(): void
    {
        $path = $this->root . '/sources.json';
        $registry = new SourceInstanceRegistry($path);
        $first = str_repeat('1', 64);

        try {
            $registry->bindOwnerApproved('shop-alpha', $first, str_repeat('0', 64));
            self::fail('Unapproved source binding was accepted.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($path);
        }

        $registry->bindOwnerApproved('shop-alpha', $first, SourceInstanceRegistry::approval('shop-alpha', $first));
        self::assertSame(0600, fileperms($path) & 0777);
        $registry->requireBinding('shop-alpha', $first);
        self::assertSame($first, $registry->binding('shop-alpha'));
        self::assertNull($registry->binding('another-shop'));

        $this->expectException(\RuntimeException::class);
        $second = str_repeat('2', 64);
        $registry->bindOwnerApproved('shop-alpha', $second, SourceInstanceRegistry::approval('shop-alpha', $second));
    }

    public function testWorldReadableOrMalformedRegistryFailsClosed(): void
    {
        $path = $this->root . '/sources.json';
        file_put_contents($path, '{"bindings":{"shop-alpha":"secret"}}');
        chmod($path, 0644);

        $this->expectException(\RuntimeException::class);
        new SourceInstanceRegistry($path);
    }

    public function testInspectingAnAbsentBindingDoesNotMutateItsPrivateDirectory(): void
    {
        $path = $this->root . '/sources.json';
        $beforeMode = fileperms($this->root) & 0777;
        $beforeMtime = filemtime($this->root);
        $registry = new SourceInstanceRegistry($path);

        self::assertNull($registry->binding('shop-alpha'));
        self::assertFileDoesNotExist($path);
        self::assertSame($beforeMode, fileperms($this->root) & 0777);
        self::assertSame($beforeMtime, filemtime($this->root));
    }
}
