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

final class TransferPackageWriterTest extends PluginTestCase
{
    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->destination = sys_get_temp_dir() . '/cartshift-package-' . bin2hex(random_bytes(8));
        mkdir($this->destination, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->destination);
        parent::tearDown();
    }

    public function testWritesExactPrivateHashSealedPackageAndReusesIdenticalBytes(): void
    {
        $records = $this->records();
        $assetBytes = "private download\n";
        $assetHash = hash('sha256', $assetBytes);
        $writer = new TransferPackageWriter(new TransferPackageValidator());

        $path = $writer->write(
            $this->source(),
            $this->selection(),
            $records,
            [$this->asset($assetBytes)],
            $this->runtime(),
        );

        $recordsHash = hash_file('sha256', $path . '/records.ndjson');
        self::assertSame(
            'cartshift-transfer-v2-shop-alpha-' . $this->selection()->fingerprint() . '-' . $recordsHash,
            basename($path),
        );
        self::assertSame(['assets', 'manifest.json', 'records.ndjson'], $this->entries($path));
        self::assertSame([$assetHash], $this->entries($path . '/assets'));
        self::assertSame(0700, fileperms($path) & 0777);
        self::assertSame(0600, fileperms($path . '/manifest.json') & 0777);
        self::assertSame(0600, fileperms($path . '/records.ndjson') & 0777);
        self::assertSame(0600, fileperms($path . '/assets/' . $assetHash) & 0777);

        $reader = new TransferPackageReader($path, new TransferPackageValidator());
        self::assertSame(['order' => 1, 'product' => 1], $reader->manifest()->recordCounts);
        self::assertSame(
            ['shop-alpha:product:9', 'shop-alpha:order:41'],
            array_map(static fn (RecordEnvelope $record): string => $record->identity->canonical(), iterator_to_array($reader->records())),
        );

        $inode = fileinode($path);
        $reused = $writer->write(
            $this->source(),
            $this->selection(),
            $records,
            [$this->asset($assetBytes)],
            $this->runtime(['created_at_utc' => '2035-01-01T00:00:00Z']),
        );
        self::assertSame($path, $reused);
        self::assertSame($inode, fileinode($reused), 'Identical packages are reused, never overwritten for a new timestamp.');
    }

    public function testRejectsUnsortedRecordsBeforeWritingAnyPackage(): void
    {
        $records = array_reverse($this->records());
        $writer = new TransferPackageWriter(new TransferPackageValidator());

        $this->expectException(\InvalidArgumentException::class);
        try {
            $writer->write($this->source(), $this->selection(), $records, [], $this->runtime());
        } finally {
            self::assertSame([], $this->entries($this->destination));
        }
    }

    public function testTamperedCompletedPackageIsNeverReusedOrOverwritten(): void
    {
        $writer = new TransferPackageWriter(new TransferPackageValidator());
        $path = $writer->write($this->source(), $this->selection(), $this->records(), [], $this->runtime());
        file_put_contents($path . '/records.ndjson', "{}\n");

        try {
            $writer->write($this->source(), $this->selection(), $this->records(), [], $this->runtime());
            self::fail('A corrupt same-path package was silently reused.');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString('Ada', $exception->getMessage());
        }

        self::assertSame("{}\n", file_get_contents($path . '/records.ndjson'), 'Existing evidence is not overwritten.');
    }

    /** @return list<RecordEnvelope> */
    private function records(): array
    {
        return [
            RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'product', '9'), ['title' => 'Tea']),
            RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'order', '41'), ['billing_name' => 'Ada']),
        ];
    }

    private function source(): SourceIdentity
    {
        return new SourceIdentity('shop-alpha', 'product', '1');
    }

    private function selection(): TransferSelection
    {
        return new TransferSelection(
            'shop-alpha',
            SelectionClause::ids([9]),
            SelectionClause::none(),
            SelectionClause::ids([41]),
            SelectionClause::none(),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function runtime(array $overrides = []): array
    {
        return $overrides + [
            'destination' => $this->destination,
            'source_instance_fingerprint' => str_repeat('1', 64),
            'source_url_hash' => str_repeat('2', 64),
            'source_runtime_fingerprint' => str_repeat('3', 64),
            'source_settings_fingerprint' => str_repeat('4', 64),
            'source_capability_fingerprint' => str_repeat('5', 64),
            'cartshift_version' => '2.0.0',
            'woocommerce_version' => '11.0.0',
            'wcs_version' => '8.7.0',
            'created_at_utc' => '2026-08-10T12:00:00Z',
        ];
    }

    /** @return array{sha256: string, bytes: int, stream: resource} */
    private function asset(string $bytes): array
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        return ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'stream' => $stream];
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($entries);
        return $entries;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
