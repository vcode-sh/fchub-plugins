<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Package\TransferPackageWriter;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferPackageValidatorTest extends PluginTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-validator-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function testRejectsUnexpectedPathsSymlinksAndCorruptAssetsWithoutLeakingPayload(): void
    {
        foreach (['unexpected', 'asset', 'symlink', 'permissions'] as $attack) {
            $path = $this->package();
            if ($attack === 'unexpected') {
                file_put_contents($path . '/debug.txt', 'Ada private@example.test');
            } elseif ($attack === 'asset') {
                $asset = (glob($path . '/assets/*') ?: [])[0];
                file_put_contents($asset, 'wrong');
            } else {
                if ($attack === 'symlink') {
                    $target = $this->root . '/outside';
                    file_put_contents($target, 'outside');
                    symlink($target, $path . '/assets/link');
                } else {
                    chmod($path . '/records.ndjson', 0644);
                }
            }

            $result = (new TransferPackageValidator())->validate($path);
            self::assertFalse($result->valid);
            self::assertNotSame([], $result->errors);
            self::assertStringNotContainsString('Ada', implode(' ', $result->errors));
            self::assertStringNotContainsString('private@example.test', implode(' ', $result->errors));
            $this->remove($path);
        }
    }

    public function testRejectsManifestVersionCountAndHashDrift(): void
    {
        foreach (['version', 'count', 'hash'] as $attack) {
            $path = $this->package();
            $manifestPath = $path . '/manifest.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
            if ($attack === 'version') $manifest['format_version'] = 99;
            if ($attack === 'count') $manifest['record_counts']['order'] = 2;
            if ($attack === 'hash') $manifest['records_sha256'] = str_repeat('0', 64);
            file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            self::assertFalse((new TransferPackageValidator())->validate($path)->valid, $attack);
            $this->remove($path);
        }
    }

    public function testWriterRejectsBadAssetBytesAndCleansOnlyItsTemporaryDirectory(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'actual');
        rewind($stream);
        file_put_contents($this->root . '/owner-file', 'keep');

        try {
            $this->writer()->write(
                $this->source(), $this->selection(), $this->records(),
                [['sha256' => hash('sha256', 'declared'), 'bytes' => 8, 'stream' => $stream]], $this->runtime(),
            );
            self::fail('Mismatched asset bytes were accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('hash or length', $exception->getMessage());
        }

        self::assertFileExists($this->root . '/owner-file');
        self::assertSame(['owner-file'], $this->entries($this->root));
    }

    private function package(): string
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'asset');
        rewind($stream);
        return $this->writer()->write($this->source(), $this->selection(), $this->records(), [
            ['sha256' => hash('sha256', 'asset'), 'bytes' => 5, 'stream' => $stream],
        ], $this->runtime());
    }

    private function writer(): TransferPackageWriter { return new TransferPackageWriter(new TransferPackageValidator()); }
    private function source(): SourceIdentity { return new SourceIdentity('shop-alpha', 'order', '1'); }
    private function selection(): TransferSelection { return new TransferSelection('shop-alpha', SelectionClause::none(), SelectionClause::none(), SelectionClause::ids([41]), SelectionClause::none()); }
    /** @return list<RecordEnvelope> */
    private function records(): array { return [RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'order', '41'), ['name' => 'Ada', 'email' => 'private@example.test'])]; }
    /** @return array<string, mixed> */
    private function runtime(): array { return [
        'destination' => $this->root, 'source_instance_fingerprint' => str_repeat('1', 64), 'source_url_hash' => str_repeat('2', 64),
        'source_runtime_fingerprint' => str_repeat('3', 64), 'source_settings_fingerprint' => str_repeat('4', 64),
        'source_capability_fingerprint' => str_repeat('5', 64), 'cartshift_version' => '2.0.0', 'woocommerce_version' => '11.0.0',
        'wcs_version' => null, 'created_at_utc' => '2026-08-10T12:00:00Z',
    ]; }
    /** @return list<string> */
    private function entries(string $path): array { $e = array_values(array_diff(scandir($path) ?: [], ['.', '..'])); sort($e); return $e; }
    private function remove(string $path): void { if (!file_exists($path) && !is_link($path)) return; if (is_file($path) || is_link($path)) { unlink($path); return; } foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) $this->remove($path . '/' . $entry); rmdir($path); }
}
