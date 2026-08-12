<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Package\AssetManifestEntry;
use CartShift\Domain\Transfer\Execution\FilesystemSagaRepository;
use CartShift\Domain\Transfer\Product\FluentCartDownloadStager;
use CartShift\Domain\Transfer\Product\StagedAsset;
use CartShift\Domain\Transfer\Product\WordPressMediaGateway;
use CartShift\Domain\Transfer\Product\WordPressMediaStager;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class AssetStagerTest extends PluginTestCase
{
    private string $root;
    private string $package;
    private string $uploads;
    private string $downloads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/cartshift-staging-' . bin2hex(random_bytes(8));
        $this->package = $this->root . '/package';
        $this->uploads = $this->root . '/uploads';
        $this->downloads = $this->uploads . '/fluent-cart';
        mkdir($this->package . '/assets', 0700, true);
        mkdir($this->uploads, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testMissingDownloadBlocksBeforeAnyTargetRecordWrite(): void
    {
        $gateway = new RecordingMediaGateway();
        $stager = new WordPressMediaStager($this->uploads, $gateway);

        try {
            $stager->stage($this->asset('missing'), $this->context());
            self::fail('A missing package asset was staged.');
        } catch (SourceRecordException $exception) {
            self::assertSame('asset_missing', $exception->reasonCode);
        }

        self::assertSame(0, $gateway->insertCalls);
        self::assertSame([], $gateway->attachments);
    }

    public function testPackageHashMismatchBlocksMediaBeforeAttachmentInsert(): void
    {
        [$asset] = $this->packageAsset('photo.jpg', "\xff\xd8\xffphoto");
        file_put_contents($this->package . '/assets/' . $asset->sha256, 'tampered package asset');
        $gateway = new RecordingMediaGateway();

        try {
            (new WordPressMediaStager($this->uploads, $gateway))->stage($asset, $this->context());
            self::fail('A package asset with the wrong bytes was inserted as media.');
        } catch (SourceRecordException $exception) {
            self::assertSame('asset_hash_mismatch', $exception->reasonCode);
        }

        self::assertSame(0, $gateway->insertCalls);
    }

    public function testMediaIsVerifiedBeforeInsertAndCarriesMigrationOwnershipMeta(): void
    {
        [$asset, $bytes] = $this->packageAsset('photo.jpg', "\xff\xd8\xffphoto");
        $gateway = new RecordingMediaGateway();
        $stager = new WordPressMediaStager($this->uploads, $gateway);

        $staged = $stager->stage($asset, $this->context());
        $stager->verify($staged);

        self::assertSame(1, $gateway->insertCalls);
        self::assertSame($asset->sha256, hash_file('sha256', $staged->targetPath));
        self::assertSame(strlen($bytes), filesize($staged->targetPath));
        self::assertSame($asset->sha256, $gateway->meta[$staged->targetId]['_cartshift_asset_sha256']);
        self::assertSame('source-runtime-sha', $gateway->meta[$staged->targetId]['_cartshift_source_runtime']);
        self::assertSame('run-42', $gateway->meta[$staged->targetId]['_cartshift_migration_run']);
        self::assertSame(0600, fileperms($staged->targetPath) & 0777);
    }

    public function testTamperedMediaFailsVerificationAndRollbackRefusesDeletion(): void
    {
        [$asset] = $this->packageAsset('photo.jpg', "\xff\xd8\xffphoto");
        $gateway = new RecordingMediaGateway();
        $stager = new WordPressMediaStager($this->uploads, $gateway);
        $staged = $stager->stage($asset, $this->context());
        file_put_contents($staged->targetPath, 'replaced-after-stage');

        try {
            $stager->verify($staged);
            self::fail('A tampered media asset verified successfully.');
        } catch (SourceRecordException $exception) {
            self::assertSame('asset_hash_mismatch', $exception->reasonCode);
        }

        $stager->rollback($staged);
        self::assertFileExists($staged->targetPath);
        self::assertArrayHasKey($staged->targetId, $gateway->attachments);
    }

    public function testRollbackDeletesOnlyUnchangedMigrationCreatedMediaPair(): void
    {
        [$asset] = $this->packageAsset('photo.jpg', "\xff\xd8\xffphoto");
        $gateway = new RecordingMediaGateway();
        $stager = new WordPressMediaStager($this->uploads, $gateway);
        $staged = $stager->stage($asset, $this->context());

        $stager->rollback($staged);

        self::assertFileDoesNotExist($staged->targetPath);
        self::assertNotContains($staged->targetId, array_keys($gateway->attachments));
    }

    public function testSameSiteMediaReuseRequiresExactApprovedRuntimeHashAndAttachment(): void
    {
        [$asset] = $this->packageAsset('photo.jpg', "\xff\xd8\xffphoto");
        $file = $this->uploads . '/existing-photo.jpg';
        copy($this->package . '/assets/' . $asset->sha256, $file);
        $gateway = new RecordingMediaGateway();
        $gateway->attachments[77] = $file;
        $gateway->meta[77] = [
            '_cartshift_asset_sha256' => $asset->sha256,
            '_cartshift_source_runtime' => 'source-runtime-sha',
        ];
        $context = new StageContext($this->package, 'run-42', 'source-runtime-sha', [
            $asset->sha256 => [
                'attachment_id' => 77,
                'file' => $file,
                'source_runtime_fingerprint' => 'source-runtime-sha',
            ],
        ]);
        $stager = new WordPressMediaStager($this->uploads, $gateway);

        $staged = $stager->stage($asset, $context);

        self::assertFalse($staged->createdByMigration);
        self::assertSame(77, $staged->targetId);
        self::assertSame(0, $gateway->insertCalls);
        $stager->rollback($staged);
        self::assertFileExists($file);
        self::assertArrayHasKey(77, $gateway->attachments);

        $stale = new StageContext($this->package, 'run-42', 'source-runtime-sha', [
            $asset->sha256 => [
                'attachment_id' => 77,
                'file' => $file,
                'source_runtime_fingerprint' => 'different-runtime',
            ],
        ]);
        try {
            $stager->stage($asset, $stale);
            self::fail('A stale same-site link decision was reused.');
        } catch (SourceRecordException $exception) {
            self::assertSame('asset_link_mismatch', $exception->reasonCode);
        }
    }

    public function testDownloadUsesContentAddressedRelativePathAndRejectsExpiryUnitDrift(): void
    {
        [$asset] = $this->packageAsset('../../manual.pdf', "%PDF-1.4\ndownload");
        $stager = new FluentCartDownloadStager($this->downloads, 'local');
        $staged = $stager->stage($asset, $this->context());

        self::assertSame($asset->sha256 . '--manual.pdf', $staged->relativePath);
        self::assertSame(realpath($this->downloads) . '/' . $staged->relativePath, $staged->targetPath);
        self::assertStringNotContainsString('..', $staged->relativePath);
        $stager->verify($staged);
        self::assertSame(
            ['download_limit' => '3', 'download_expiry' => ''],
            $stager->settings(3, -1),
        );

        try {
            $stager->settings(3, 14);
            self::fail('Woo days were copied into FluentCart months.');
        } catch (SourceRecordException $exception) {
            self::assertSame('unsupported_download_policy', $exception->reasonCode);
        }
    }

    public function testDownloadStorageIsCreatedOnlyWhenAnAssetIsActuallyStaged(): void
    {
        new FluentCartDownloadStager($this->downloads, 'local');

        self::assertDirectoryDoesNotExist($this->downloads);

        [$asset] = $this->packageAsset('manual.pdf', "%PDF-1.4\ndownload");
        (new FluentCartDownloadStager($this->downloads, 'local'))->stage($asset, $this->context());

        self::assertDirectoryExists($this->downloads);
    }

    public function testDownloadDriverAndRollbackFailClosed(): void
    {
        [$asset] = $this->packageAsset('manual.pdf', "%PDF-1.4\ndownload");

        try {
            (new FluentCartDownloadStager($this->downloads, 's3'))->stage($asset, $this->context());
            self::fail('An unproved target storage driver was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame('target_asset_driver_unsupported', $exception->reasonCode);
        }

        $local = new FluentCartDownloadStager($this->downloads, 'local');
        $staged = $local->stage($asset, $this->context());
        $local->rollback($staged);
        self::assertFileDoesNotExist($staged->targetPath);

        $staged = $local->stage($asset, $this->context());
        file_put_contents($staged->targetPath, 'operator replacement');
        $local->rollback($staged);
        self::assertFileExists($staged->targetPath);
    }

    public function testExecutableDownloadExtensionIsRejectedBeforeCopy(): void
    {
        [$asset] = $this->packageAsset('payload.php', '<?php echo "no";');
        $stager = new FluentCartDownloadStager($this->downloads, 'local');

        try {
            $stager->stage($asset, $this->context());
            self::fail('An executable download was copied directly into FluentCart storage.');
        } catch (SourceRecordException $exception) {
            self::assertSame('target_asset_type_unsupported', $exception->reasonCode);
        }

        self::assertSame([], glob($this->downloads . '/*') ?: []);
    }

    public function testDownloadCrashLeavesPendingSagaAndRetryAdoptsOwnedBytesBeforeFinalising(): void
    {
        [$asset] = $this->packageAsset('manual.pdf', "%PDF-1.4\ndownload");
        $evidence = $this->root . '/evidence';
        mkdir($evidence, 0700);
        $saga = new FilesystemSagaRepository($evidence);
        $context = new StageContext($this->package, 'run-42', 'source-runtime-sha', generation: 1, filesystemSaga: $saga);
        $stager = new FluentCartDownloadStager($this->downloads, 'local');

        DatabaseTransaction::begin();
        $first = $stager->stage($asset, $context);
        DatabaseTransaction::reset(); // Simulated process death: no PHP callbacks run.

        self::assertNotNull($first->filesystemOperationId);
        self::assertSame('pending', $saga->state('run-42', $first->filesystemOperationId));
        self::assertFileExists($first->targetPath);

        DatabaseTransaction::begin();
        $retry = $stager->stage($asset, $context);
        DatabaseTransaction::commit();

        self::assertTrue($retry->createdByMigration, 'Crash-owned bytes were misclassified as unrelated pre-existing content.');
        self::assertSame($first->filesystemOperationId, $retry->filesystemOperationId);
        self::assertSame('final', $saga->state('run-42', $retry->filesystemOperationId));
    }

    public function testFinalisedDownloadReuseDoesNotClaimTheOwningReceiptsSagaOperation(): void
    {
        [$asset] = $this->packageAsset('manual.pdf', "%PDF-1.4\ndownload");
        $evidence = $this->root . '/evidence';
        mkdir($evidence, 0700);
        $saga = new FilesystemSagaRepository($evidence);
        $context = new StageContext($this->package, 'run-42', 'source-runtime-sha', generation: 1, filesystemSaga: $saga);
        $stager = new FluentCartDownloadStager($this->downloads, 'local');

        DatabaseTransaction::begin();
        $first = $stager->stage($asset, $context);
        DatabaseTransaction::commit();
        DatabaseTransaction::begin();
        $reused = $stager->stage($asset, $context);
        DatabaseTransaction::commit();

        self::assertNotNull($first->filesystemOperationId);
        self::assertSame('final', $saga->state('run-42', $first->filesystemOperationId));
        self::assertFalse($reused->createdByMigration);
        self::assertNull($reused->filesystemOperationId);
        self::assertSame([], $reused->filesystemOperations);
        self::assertSame($first->targetPath, $reused->targetPath);
    }

    public function testMediaCrashRecoversOnlyTheExactPendingOwnedFile(): void
    {
        [$asset] = $this->packageAsset('photo.jpg', "\xff\xd8\xffphoto");
        $evidence = $this->root . '/evidence';
        mkdir($evidence, 0700);
        $saga = new FilesystemSagaRepository($evidence);
        $context = new StageContext($this->package, 'run-42', 'source-runtime-sha', generation: 1, filesystemSaga: $saga);
        $gateway = new RecordingMediaGateway();
        $stager = new WordPressMediaStager($this->uploads, $gateway);

        DatabaseTransaction::begin();
        $first = $stager->stage($asset, $context);
        DatabaseTransaction::reset();
        unset($gateway->attachments[$first->targetId], $gateway->meta[$first->targetId]); // MariaDB connection rollback after process death.

        DatabaseTransaction::begin();
        $retry = $stager->stage($asset, $context);
        DatabaseTransaction::commit();

        self::assertSame(2, $gateway->insertCalls);
        self::assertSame($first->filesystemOperationId, $retry->filesystemOperationId);
        self::assertSame('final', $saga->state('run-42', $retry->filesystemOperationId));
        self::assertSame($asset->sha256, hash_file('sha256', $retry->targetPath));
    }

    public function testGeneratedMediaFilesReceiveIndependentDurableSagaOperations(): void
    {
        [$asset] = $this->packageAsset('photo.jpg', "\xff\xd8\xffphoto");
        $evidence = $this->root . '/evidence';
        mkdir($evidence, 0700);
        $saga = new FilesystemSagaRepository($evidence);
        $context = new StageContext($this->package, 'run-42', 'source-runtime-sha', generation: 1, filesystemSaga: $saga);
        $gateway = new RecordingMediaGateway();
        $gateway->generateDerivative = true;

        DatabaseTransaction::begin();
        $staged = (new WordPressMediaStager($this->uploads, $gateway))->stage($asset, $context);
        DatabaseTransaction::commit();

        self::assertCount(2, $staged->filesystemOperations);
        self::assertCount(2, array_unique(array_keys($staged->filesystemOperations)));
        foreach ($staged->filesystemOperations as $operationId => $path) {
            self::assertFileExists($path);
            self::assertSame('final', $saga->state('run-42', $operationId));
        }
    }

    /** @return array{AssetManifestEntry, string} */
    private function packageAsset(string $name, string $contents): array
    {
        $hash = hash('sha256', $contents);
        file_put_contents($this->package . '/assets/' . $hash, $contents);
        chmod($this->package . '/assets/' . $hash, 0600);

        return [new AssetManifestEntry($hash, strlen($contents), $this->mime($name), $name, 'local'), $contents];
    }

    private function asset(string $name): AssetManifestEntry
    {
        return new AssetManifestEntry(str_repeat('a', 64), 7, 'application/pdf', $name, 'local');
    }

    private function context(): StageContext
    {
        return new StageContext($this->package, 'run-42', 'source-runtime-sha');
    }

    private function mime(string $name): string
    {
        return str_ends_with($name, '.jpg') ? 'image/jpeg' : 'application/pdf';
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

final class RecordingMediaGateway implements WordPressMediaGateway
{
    public int $insertCalls = 0;
    public bool $generateDerivative = false;
    /** @var array<int, string> */
    public array $attachments = [];
    /** @var array<int, array<string, string>> */
    public array $meta = [];

    public function insert(string $file, string $mimeType, string $title): int
    {
        $this->insertCalls++;
        $id = 100 + $this->insertCalls;
        $this->attachments[$id] = $file;
        return $id;
    }

    public function generateMetadata(int $attachmentId, string $file): void
    {
        if ($this->generateDerivative) {
            file_put_contents(dirname($file) . '/photo-150x150.jpg', 'generated thumbnail');
        }
    }

    public function updateMeta(int $attachmentId, string $key, string $value): void
    {
        $this->meta[$attachmentId][$key] = $value;
    }

    public function file(int $attachmentId): ?string
    {
        return $this->attachments[$attachmentId] ?? null;
    }

    public function files(int $attachmentId): array
    {
        $file = $this->file($attachmentId);
        if ($file === null) {
            return [];
        }
        $files = [$file];
        $derivative = dirname($file) . '/photo-150x150.jpg';
        if (is_file($derivative)) {
            $files[] = $derivative;
        }
        return $files;
    }

    public function meta(int $attachmentId, string $key): ?string
    {
        return $this->meta[$attachmentId][$key] ?? null;
    }

    public function delete(int $attachmentId): bool
    {
        if (!isset($this->attachments[$attachmentId])) {
            return false;
        }
        foreach ($this->files($attachmentId) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        unset($this->attachments[$attachmentId], $this->meta[$attachmentId]);
        return true;
    }
}
