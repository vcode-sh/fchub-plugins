<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Package\AssetManifestEntry;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\StageContext;

defined('ABSPATH') || exit;

final class FluentCartDownloadStager implements TargetAssetStager
{
    private string $root;

    public function __construct(string $localRoot, private readonly string $driver)
    {
        if (is_link($localRoot)) {
            throw new \InvalidArgumentException('FluentCart download root must be a real directory.');
        }
        if (is_dir($localRoot)) {
            $canonical = realpath($localRoot);
            if ($canonical === false) {
                throw new \InvalidArgumentException('FluentCart download root must be a real directory.');
            }
            $this->root = rtrim($canonical, '/');
            chmod($this->root, 0700);
            return;
        }
        $parent = realpath(dirname($localRoot));
        $name = basename($localRoot);
        if ($parent === false || !is_dir($parent) || is_link(dirname($localRoot)) || in_array($name, ['', '.', '..'], true)) {
            throw new \InvalidArgumentException('FluentCart download root cannot be created safely.');
        }
        $this->root = rtrim($parent, '/') . '/' . $name;
    }

    public function stage(AssetManifestEntry $asset, StageContext $context): StagedAsset
    {
        if ($this->driver !== 'local') {
            throw new SourceRecordException('target_asset_driver_unsupported', 'Only the installed FluentCart local driver is proved.');
        }

        $source = $context->assetPath($asset);
        $this->verifyPath($source, $asset->sha256, $asset->bytes, 'asset_missing');
        $safeName = self::sanitiseName($asset->originalName);
        if (in_array(strtolower(pathinfo($safeName, PATHINFO_EXTENSION)), self::blockedExtensions(), true)) {
            throw new SourceRecordException('target_asset_type_unsupported', 'Executable local downloads are not accepted by FluentCart.');
        }
        $relative = $asset->sha256 . '--' . $safeName;
        if (strlen($relative) > 185) {
            throw new SourceRecordException('target_schema_unrepresentable', 'FluentCart download path exceeds the installed validator contract.');
        }
        $target = $this->root . '/' . $relative;

        $operationId = $context->filesystemSaga?->operationId(
            $context->migrationId,
            $context->generation,
            'download',
            $asset->sha256,
            $relative,
            $target,
        );
        $sagaState = $operationId === null ? null : $context->filesystemSaga?->stateIfExists($context->migrationId, $operationId);

        if (file_exists($target) || is_link($target)) {
            $this->verifyPath($target, $asset->sha256, $asset->bytes, 'asset_hash_mismatch');
            if (in_array($sagaState, ['reverted', 'rolled_back'], true)) {
                throw new SourceRecordException('filesystem_saga_target_conflict', 'A terminal download saga still has a target file.');
            }
            $ownedPending = $sagaState === 'pending';
            $staged = new StagedAsset(
                $asset->sha256,
                $asset->bytes,
                $target,
                $relative,
                'download',
                $ownedPending,
                $context->migrationId,
                $context->sourceRuntimeFingerprint,
                driver: 'local',
                filesystemOperationId: $ownedPending ? $operationId : null,
                filesystemOperations: $ownedPending && $operationId !== null ? [$operationId => $target] : [],
            );
            if ($ownedPending && $operationId !== null) {
                \CartShift\Support\DatabaseTransaction::afterCommit(
                    fn () => $context->filesystemSaga?->finalise($context->migrationId, $operationId, $target),
                );
            }
            return $staged;
        }
        if (in_array($sagaState, ['final', 'reverted'], true)) {
            throw new SourceRecordException('filesystem_saga_target_conflict', 'Terminal download saga has no matching target file.');
        }

        if ($context->filesystemSaga !== null) {
            $operationId = $context->filesystemSaga->begin(
                $context->migrationId,
                $context->generation,
                'download',
                $asset->sha256,
                $asset->bytes,
                $relative,
                $target,
            );
        }

        if (!is_dir($this->root) && !mkdir($this->root, 0700) && !is_dir($this->root)) {
            throw new SourceRecordException('target_write_failed', 'FluentCart download root could not be created.');
        }
        if (realpath($this->root) !== $this->root || is_link($this->root)) {
            throw new SourceRecordException('target_write_failed', 'FluentCart download root traverses a symlink.');
        }
        chmod($this->root, 0700);

        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new SourceRecordException('target_write_failed', 'FluentCart local download could not be opened for staging.');
        }
        $failure = null;
        try {
            $copied = stream_copy_to_stream($input, $output);
            if ($copied !== $asset->bytes) {
                throw new SourceRecordException('target_write_failed', 'FluentCart local download staging copy was incomplete.');
            }
            if (!fflush($output) || (function_exists('fsync') && !fsync($output))) {
                throw new SourceRecordException('target_write_failed', 'Download staging copy could not be flushed to stable storage.');
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($failure !== null) {
            @unlink($target);
            if ($operationId !== null && !file_exists($target) && !is_link($target)) {
                $context->filesystemSaga?->revert($context->migrationId, $operationId, $target);
            }
            throw $failure;
        }
        chmod($target, 0600);

        $staged = new StagedAsset(
            $asset->sha256,
            $asset->bytes,
            $target,
            $relative,
            'download',
            true,
            $context->migrationId,
            $context->sourceRuntimeFingerprint,
            driver: 'local',
            filesystemOperationId: $operationId,
            filesystemOperations: $operationId === null ? [] : [$operationId => $target],
        );
        $this->verify($staged);
        if ($operationId !== null) {
            \CartShift\Support\DatabaseTransaction::afterCommit(
                fn () => $context->filesystemSaga?->finalise($context->migrationId, $operationId, $target),
            );
        }
        return $staged;
    }

    public function verify(StagedAsset $asset): void
    {
        if ($asset->kind !== 'download' || $asset->driver !== 'local') {
            throw new SourceRecordException('asset_hash_mismatch', 'Staged FluentCart download identity is invalid.');
        }
        if ($asset->targetPath !== $this->root . '/' . $asset->relativePath) {
            throw new SourceRecordException('asset_hash_mismatch', 'Staged FluentCart download escaped its local root.');
        }
        $canonical = realpath($asset->targetPath);
        if (str_contains($asset->relativePath, '/')
            || str_contains($asset->relativePath, '\\')
            || $canonical === false
            || dirname($canonical) !== $this->root) {
            throw new SourceRecordException('asset_hash_mismatch', 'Staged FluentCart download path is not a local-root basename.');
        }
        $this->verifyPath($asset->targetPath, $asset->sha256, $asset->bytes, 'asset_hash_mismatch');
    }

    public function rollback(StagedAsset $asset): void
    {
        if (!$asset->createdByMigration || $asset->kind !== 'download') {
            return;
        }
        try {
            $this->verify($asset);
        } catch (\Throwable) {
            return;
        }
        @unlink($asset->targetPath);
    }

    public function rollbackWithSaga(StagedAsset $asset, StageContext $context): void
    {
        $this->rollback($asset);
        if ($asset->filesystemOperationId !== null && !file_exists($asset->targetPath) && !is_link($asset->targetPath)) {
            $context->filesystemSaga?->revert($context->migrationId, $asset->filesystemOperationId, $asset->targetPath);
        }
    }

    /** @return array{download_limit: string, download_expiry: string} */
    public function settings(int $wooDownloadLimit, int $wooExpiryDays): array
    {
        if ($wooDownloadLimit < -1 || $wooExpiryDays < -1) {
            throw new \InvalidArgumentException('WooCommerce download policy is invalid.');
        }
        if ($wooExpiryDays > 0) {
            throw new SourceRecordException(
                'unsupported_download_policy',
                'WooCommerce expiry is measured in days while installed FluentCart applies this field as months.',
            );
        }
        return [
            'download_limit' => $wooDownloadLimit > 0 ? (string) $wooDownloadLimit : '',
            'download_expiry' => '',
        ];
    }

    private function verifyPath(string $path, string $sha256, int $bytes, string $missingReason): void
    {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new SourceRecordException($missingReason, 'Download asset is missing or is not a readable regular file.');
        }
        $hash = hash_file('sha256', $path);
        if (filesize($path) !== $bytes || $hash === false || !hash_equals($sha256, $hash)) {
            throw new SourceRecordException('asset_hash_mismatch', 'Download length or SHA-256 differs from its manifest.');
        }
    }

    private static function sanitiseName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
        $name = trim($name, '.-_');
        return $name !== '' ? substr($name, 0, 119) : 'download';
    }

    /** @return list<string> */
    private static function blockedExtensions(): array
    {
        return ['php', 'phtml', 'html', 'htm', 'svg', 'exe', 'sh', 'bat', 'cmd', 'dll'];
    }
}
