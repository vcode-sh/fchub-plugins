<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Package\TransferPackageReader;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Package\TransferPackageWriter;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferPackageReaderTest extends PluginTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-reader-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function testRecordsAndAssetsAreReadOnlyStreamsBoundToValidatedManifest(): void
    {
        $bytes = str_repeat('asset-', 4096);
        $hash = hash('sha256', $bytes);
        $path = $this->package($bytes);
        $reader = new TransferPackageReader($path, new TransferPackageValidator());

        $records = $reader->records();
        self::assertInstanceOf(\Generator::class, $records, 'Reader must stream records rather than returning an in-memory catalogue.');
        self::assertSame('shop-alpha:order:41', $records->current()->identity->canonical());

        $stream = $reader->openAsset($hash);
        self::assertSame($bytes, stream_get_contents($stream));
        fclose($stream);

        $this->expectException(\InvalidArgumentException::class);
        $reader->openAsset(str_repeat('0', 64));
    }

    public function testReaderRefusesPackageAfterManifestOrRecordTampering(): void
    {
        $path = $this->package('asset');
        $recordPath = $path . '/records.ndjson';
        file_put_contents($recordPath, str_replace('Ada', 'Eve', (string) file_get_contents($recordPath)));

        $this->expectException(\RuntimeException::class);
        new TransferPackageReader($path, new TransferPackageValidator());
    }

    public function testReaderRevalidatesWhenPackageChangesAfterConstruction(): void
    {
        $path = $this->package('asset');
        $reader = new TransferPackageReader($path, new TransferPackageValidator());
        file_put_contents($path . '/records.ndjson', "{}\n");

        $this->expectException(\RuntimeException::class);
        $reader->records()->current();
    }

    private function package(string $assetBytes): string
    {
        $record = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'order', '41'), ['billing_name' => 'Ada']);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $assetBytes);
        rewind($stream);
        return (new TransferPackageWriter(new TransferPackageValidator()))->write(
            new SourceIdentity('shop-alpha', 'order', '1'),
            new TransferSelection('shop-alpha', SelectionClause::none(), SelectionClause::none(), SelectionClause::ids([41]), SelectionClause::none()),
            [$record],
            [['sha256' => hash('sha256', $assetBytes), 'bytes' => strlen($assetBytes), 'stream' => $stream]],
            [
                'destination' => $this->root,
                'source_instance_fingerprint' => str_repeat('1', 64),
                'source_url_hash' => str_repeat('2', 64),
                'source_runtime_fingerprint' => str_repeat('3', 64),
                'source_settings_fingerprint' => str_repeat('4', 64),
                'source_capability_fingerprint' => str_repeat('5', 64),
                'cartshift_version' => '2.0.0',
                'woocommerce_version' => '11.0.0',
                'wcs_version' => null,
                'created_at_utc' => '2026-08-10T12:00:00Z',
            ],
        );
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) $this->remove($path . '/' . $entry);
        rmdir($path);
    }
}
