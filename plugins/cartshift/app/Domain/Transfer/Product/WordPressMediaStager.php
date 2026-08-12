<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Package\AssetManifestEntry;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\StageContext;

defined('ABSPATH') || exit;

final class WordPressMediaStager implements TargetAssetStager
{
    private string $uploadsRoot;
    private WordPressMediaGateway $gateway;

    public function __construct(string $uploadsRoot, ?WordPressMediaGateway $gateway = null)
    {
        $canonical = realpath($uploadsRoot);
        if ($canonical === false || !is_dir($canonical) || is_link($uploadsRoot)) {
            throw new \InvalidArgumentException('Target upload root must be a real directory.');
        }
        $this->uploadsRoot = rtrim($canonical, '/');
        $this->gateway = $gateway ?? new LoadedWordPressMediaGateway();
    }

    public function stage(AssetManifestEntry $asset, StageContext $context): StagedAsset
    {
        $source = $context->assetPath($asset);
        $this->verifyPath($source, $asset->sha256, $asset->bytes, 'asset_missing');

        if (isset($context->approvedMediaLinks[$asset->sha256])) {
            return $this->approvedReuse($asset, $context);
        }

        $relative = 'cartshift-staging/' . $context->migrationId . '/'
            . $asset->sha256 . '--' . self::sanitiseName($asset->originalName);
        $target = $this->uploadsRoot . '/' . $relative;
        $directory = dirname($target);

        $operationId = null;
        if ($context->filesystemSaga !== null) {
            $operationId = $context->filesystemSaga->operationId(
                $context->migrationId,
                $context->generation,
                'media',
                $asset->sha256,
                $relative,
                $target,
            );
            $state = $context->filesystemSaga->stateIfExists($context->migrationId, $operationId);
            if (file_exists($target) || is_link($target)) {
                if ($state !== 'pending') {
                    throw new SourceRecordException('filesystem_saga_target_conflict', 'Media target exists without a recoverable pending saga.');
                }
                $this->verifyPath($target, $asset->sha256, $asset->bytes, 'asset_hash_mismatch');
                if (!unlink($target)) {
                    throw new SourceRecordException('filesystem_saga_target_conflict', 'Pending media target could not be recovered safely.');
                }
            } elseif (in_array($state, ['final', 'reverted'], true)) {
                throw new SourceRecordException('filesystem_saga_target_conflict', 'Terminal media saga has no matching target file.');
            }
            $operationId = $context->filesystemSaga->begin(
                $context->migrationId,
                $context->generation,
                'media',
                $asset->sha256,
                $asset->bytes,
                $relative,
                $target,
            );
        }

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new SourceRecordException('target_write_failed', 'Media staging directory could not be created.');
        }
        if (realpath($directory) !== $directory) {
            throw new SourceRecordException('target_write_failed', 'Media staging directory traverses a symlink.');
        }
        chmod($directory, 0700);

        try {
            $this->copyExclusive($source, $target, $asset);
        } catch (\Throwable $exception) {
            if ($operationId !== null && !file_exists($target) && !is_link($target)) {
                $context->filesystemSaga?->revert($context->migrationId, $operationId, $target);
            }
            throw $exception;
        }
        $attachmentId = null;
        $filesystemOperations = $operationId === null ? [] : [$operationId => $target];
        try {
            $attachmentId = $this->gateway->insert($target, $asset->mimeType, $asset->originalName);
            $this->gateway->generateMetadata($attachmentId, $target);
            $this->gateway->updateMeta($attachmentId, '_cartshift_asset_sha256', $asset->sha256);
            $this->gateway->updateMeta($attachmentId, '_cartshift_source_runtime', $context->sourceRuntimeFingerprint);
            $this->gateway->updateMeta($attachmentId, '_cartshift_migration_run', $context->migrationId);
            if ($context->filesystemSaga !== null) {
                foreach ($this->gateway->files($attachmentId) as $path) {
                    $canonical = realpath($path);
                    if ($canonical === false || is_link($path) || !is_file($canonical)
                        || !str_starts_with($canonical, $this->uploadsRoot . '/')) {
                        throw new \RuntimeException('Generated attachment file escaped the target upload root.');
                    }
                    if ($canonical === realpath($target)) {
                        continue;
                    }
                    $hash = hash_file('sha256', $canonical);
                    $bytes = filesize($canonical);
                    if (!is_string($hash) || !is_int($bytes)) {
                        throw new \RuntimeException('Generated attachment file could not be fingerprinted.');
                    }
                    $derivedRelative = ltrim(substr($canonical, strlen($this->uploadsRoot)), '/');
                    $derivedOperation = $context->filesystemSaga->begin(
                        $context->migrationId,
                        $context->generation,
                        'media',
                        $hash,
                        $bytes,
                        $derivedRelative,
                        $canonical,
                    );
                    $filesystemOperations[$derivedOperation] = $canonical;
                }
            }
        } catch (\Throwable $exception) {
            $attachmentDeleted = $attachmentId === null;
            if ($attachmentId !== null) {
                $attachmentDeleted = $this->gateway->delete($attachmentId);
            }
            if ($attachmentDeleted && is_file($target) && hash_file('sha256', $target) === $asset->sha256) {
                @unlink($target);
            }
            if ($attachmentDeleted) {
                foreach ($filesystemOperations as $candidateOperation => $path) {
                    if (!file_exists($path) && !is_link($path)
                        && $context->filesystemSaga?->stateIfExists($context->migrationId, $candidateOperation) === 'pending') {
                        $context->filesystemSaga->revert($context->migrationId, $candidateOperation, $path);
                    }
                }
            }
            throw new SourceRecordException('target_write_failed', 'WordPress rejected a staged media asset: ' . $exception->getMessage());
        }

        $staged = new StagedAsset(
            $asset->sha256,
            $asset->bytes,
            $target,
            $relative,
            'media',
            true,
            $context->migrationId,
            $context->sourceRuntimeFingerprint,
            $attachmentId,
            filesystemOperationId: $operationId,
            filesystemOperations: $filesystemOperations,
        );
        $this->verify($staged);
        foreach ($filesystemOperations as $candidateOperation => $path) {
            \CartShift\Support\DatabaseTransaction::afterCommit(
                fn () => $context->filesystemSaga?->finalise($context->migrationId, $candidateOperation, $path),
            );
        }
        return $staged;
    }

    public function verify(StagedAsset $asset): void
    {
        if ($asset->kind !== 'media' || $asset->targetId === null) {
            throw new SourceRecordException('asset_hash_mismatch', 'Staged media identity is incomplete.');
        }
        $this->verifyPath($asset->targetPath, $asset->sha256, $asset->bytes, 'asset_hash_mismatch');

        $attached = $this->gateway->file($asset->targetId);
        $attachedCanonical = $attached === null ? false : realpath($attached);
        $targetCanonical = realpath($asset->targetPath);
        if ($attachedCanonical === false
            || $targetCanonical === false
            || !str_starts_with($targetCanonical, $this->uploadsRoot . '/')
            || $attachedCanonical !== $targetCanonical) {
            throw new SourceRecordException('asset_hash_mismatch', 'Attachment points at a different target file.');
        }

        if ($this->gateway->meta($asset->targetId, '_cartshift_asset_sha256') !== $asset->sha256
            || $this->gateway->meta($asset->targetId, '_cartshift_source_runtime') !== $asset->sourceRuntimeFingerprint) {
            throw new SourceRecordException('asset_hash_mismatch', 'Attachment ownership metadata does not match the staged asset.');
        }

        if ($asset->createdByMigration
            && $this->gateway->meta($asset->targetId, '_cartshift_migration_run') !== $asset->migrationId) {
            throw new SourceRecordException('asset_hash_mismatch', 'Attachment migration owner does not match the current stage.');
        }
    }

    public function rollback(StagedAsset $asset): void
    {
        if (!$asset->createdByMigration || $asset->kind !== 'media' || $asset->targetId === null) {
            return;
        }

        try {
            $this->verify($asset);
        } catch (\Throwable) {
            return;
        }

        if (!$this->gateway->delete($asset->targetId)) {
            return;
        }
        if (is_file($asset->targetPath) && hash_file('sha256', $asset->targetPath) === $asset->sha256) {
            @unlink($asset->targetPath);
        }
    }

    public function rollbackWithSaga(StagedAsset $asset, StageContext $context): void
    {
        $this->rollback($asset);
        foreach ($asset->filesystemOperations as $operationId => $path) {
            if (!file_exists($path) && !is_link($path)
                && $context->filesystemSaga?->stateIfExists($context->migrationId, $operationId) === 'pending') {
                $context->filesystemSaga->revert($context->migrationId, $operationId, $path);
            }
        }
    }

    private function approvedReuse(AssetManifestEntry $asset, StageContext $context): StagedAsset
    {
        $link = $context->approvedMediaLinks[$asset->sha256];
        $id = $link['attachment_id'] ?? 0;
        $file = $link['file'] ?? '';
        $runtime = $link['source_runtime_fingerprint'] ?? '';
        if (!is_int($id) || $id <= 0 || !is_string($file) || $file === '' || $runtime !== $context->sourceRuntimeFingerprint) {
            throw new SourceRecordException('asset_link_mismatch', 'Approved media reuse decision is incomplete or stale.');
        }

        $staged = new StagedAsset(
            $asset->sha256,
            $asset->bytes,
            $file,
            ltrim(str_replace($this->uploadsRoot, '', $file), '/'),
            'media',
            false,
            $context->migrationId,
            $context->sourceRuntimeFingerprint,
            $id,
        );
        $this->verify($staged);
        return $staged;
    }

    private function copyExclusive(string $source, string $target, AssetManifestEntry $asset): void
    {
        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new SourceRecordException('target_write_failed', 'Media staging target already exists or cannot be opened.');
        }

        $failure = null;
        try {
            $copied = stream_copy_to_stream($input, $output);
            if ($copied !== $asset->bytes) {
                throw new SourceRecordException('target_write_failed', 'Media staging copy was incomplete.');
            }
            if (!fflush($output) || (function_exists('fsync') && !fsync($output))) {
                throw new SourceRecordException('target_write_failed', 'Media staging copy could not be flushed to stable storage.');
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($failure !== null) {
            @unlink($target);
            throw $failure;
        }
        chmod($target, 0600);
        $this->verifyPath($target, $asset->sha256, $asset->bytes, 'asset_hash_mismatch');
    }

    private function verifyPath(string $path, string $sha256, int $bytes, string $missingReason): void
    {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new SourceRecordException($missingReason, 'Asset is missing or is not a readable regular file.');
        }
        $hash = hash_file('sha256', $path);
        if (filesize($path) !== $bytes || $hash === false || !hash_equals($sha256, $hash)) {
            throw new SourceRecordException('asset_hash_mismatch', 'Asset length or SHA-256 differs from its manifest.');
        }
    }

    private static function sanitiseName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
        $name = trim($name, '.-_');
        return $name !== '' ? substr($name, 0, 119) : 'asset';
    }
}
