<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final class AssetExporter
{
    private string $packageDirectory;
    private string $uploadRoot;

    /**
     * @param array<string, AuthenticatedAssetSourceAdapter> $remoteAdapters
     */
    public function __construct(
        string $packageDirectory,
        string $approvedUploadRoot,
        private readonly array $remoteAdapters = [],
    ) {
        $this->packageDirectory = $this->directory($packageDirectory, 'package_asset_directory_invalid');
        $this->uploadRoot = $this->directory($approvedUploadRoot, 'asset_source_root_invalid');

        foreach ($remoteAdapters as $name => $adapter) {
            if (!is_string($name) || $name === '' || $name === 'local' || !$adapter instanceof AuthenticatedAssetSourceAdapter) {
                throw new \InvalidArgumentException('Authenticated asset adapters must have unique non-local names.');
            }
        }

        $assets = $this->packageDirectory . '/assets';
        if (!is_dir($assets) && !mkdir($assets, 0700, true) && !is_dir($assets)) {
            throw new \RuntimeException('Package asset directory could not be created.');
        }
        if (is_link($assets) || realpath($assets) !== $assets) {
            throw new SourceRecordException('package_asset_directory_invalid', 'Package assets must be a real local directory.');
        }
        chmod($assets, 0700);
    }

    public function export(
        string $locator,
        ?string $originalName = null,
        string $sourceKind = 'local',
        ?string $expectedSha256 = null,
    ): AssetManifestEntry {
        if ($expectedSha256 !== null && preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1) {
            throw new \InvalidArgumentException('Expected asset hash must be lowercase SHA-256.');
        }

        [$stream, $sourceName] = $this->open($locator, $sourceKind);
        $name = $this->originalName($originalName ?? $sourceName);
        $assets = $this->packageDirectory . '/assets';
        $temporary = tempnam($assets, '.cartshift-asset-');
        if ($temporary === false) {
            fclose($stream);
            throw new SourceRecordException('target_write_failed', 'A private asset temporary file could not be created.');
        }

        chmod($temporary, 0600);
        $output = fopen($temporary, 'w+b');
        if (!is_resource($output)) {
            fclose($stream);
            @unlink($temporary);
            throw new SourceRecordException('target_write_failed', 'A private asset temporary file could not be opened.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $failure = null;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new SourceRecordException('asset_missing', 'Source asset could not be read completely.');
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($hash, $chunk);
                $written = fwrite($output, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new SourceRecordException('target_write_failed', 'Package asset could not be written completely.');
                }
                $bytes += $written;
            }
            if (!fflush($output) || (function_exists('fsync') && !fsync($output))) {
                throw new SourceRecordException('target_write_failed', 'Package asset could not be flushed to stable storage.');
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            fclose($stream);
            fclose($output);
        }

        if ($failure !== null) {
            @unlink($temporary);
            throw $failure;
        }

        $sha256 = hash_final($hash);
        if ($expectedSha256 !== null && !hash_equals($expectedSha256, $sha256)) {
            @unlink($temporary);
            throw new SourceRecordException('asset_hash_mismatch', 'Source asset does not match its approved hash.');
        }

        try {
            $this->verifyFile($temporary, $sha256, $bytes);
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
        $mimeType = $this->mimeType($temporary);
        $final = $assets . '/' . $sha256;
        if (file_exists($final) || is_link($final)) {
            @unlink($temporary);
            $this->verifyFile($final, $sha256, $bytes);
            return new AssetManifestEntry($sha256, $bytes, $mimeType, $name, $sourceKind);
        }

        $partial = $final . '.partial';
        if (file_exists($partial) || is_link($partial)) {
            @unlink($temporary);
            throw new SourceRecordException('target_write_failed', 'A stale or competing partial asset exists.');
        }

        if (!rename($temporary, $partial)) {
            @unlink($temporary);
            throw new SourceRecordException('target_write_failed', 'Package asset could not enter its verified partial state.');
        }
        chmod($partial, 0600);

        try {
            $this->verifyFile($partial, $sha256, $bytes);
            if (!rename($partial, $final)) {
                throw new SourceRecordException('target_write_failed', 'Verified package asset could not be promoted atomically.');
            }
            chmod($final, 0600);
            $this->verifyFile($final, $sha256, $bytes);
        } catch (\Throwable $exception) {
            @unlink($partial);
            throw $exception;
        }

        return new AssetManifestEntry($sha256, $bytes, $mimeType, $name, $sourceKind);
    }

    /** @return array{resource, string} */
    private function open(string $locator, string $sourceKind): array
    {
        if ($sourceKind !== 'local') {
            $adapter = $this->remoteAdapters[$sourceKind] ?? null;
            if (!$adapter instanceof AuthenticatedAssetSourceAdapter) {
                throw new SourceRecordException('asset_source_unsupported', 'Private asset locator has no approved authenticated adapter.');
            }

            $stream = $adapter->open($locator);
            if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw new SourceRecordException('asset_missing', 'Authenticated asset adapter returned no readable stream.');
            }
            stream_set_blocking($stream, true);
            return [$stream, $adapter->originalName($locator)];
        }

        if (!file_exists($locator)) {
            throw new SourceRecordException('asset_missing', 'Source asset is missing.');
        }
        if (is_link($locator)) {
            throw new SourceRecordException('asset_source_unapproved', 'Symlinked source assets are not approved.');
        }

        $path = realpath($locator);
        if ($path === false || !$this->isUnder($path, $this->uploadRoot)) {
            throw new SourceRecordException('asset_source_unapproved', 'Local source asset is outside the approved upload root.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new SourceRecordException('asset_missing', 'Source asset is not a readable regular file.');
        }

        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new SourceRecordException('asset_missing', 'Source asset could not be opened.');
        }
        $opened = fstat($stream);
        $current = stat($path);
        $currentPath = realpath($path);
        if ($opened === false
            || $current === false
            || $currentPath !== $path
            || $opened['dev'] !== $current['dev']
            || $opened['ino'] !== $current['ino']) {
            fclose($stream);
            throw new SourceRecordException('asset_source_unapproved', 'Local source asset changed while it was being opened.');
        }
        return [$stream, basename($path)];
    }

    private function verifyFile(string $path, string $sha256, int $bytes): void
    {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new SourceRecordException('asset_hash_mismatch', 'Asset target is not a readable regular file.');
        }
        $actualBytes = filesize($path);
        $actualHash = hash_file('sha256', $path);
        if ($actualBytes !== $bytes || $actualHash === false || !hash_equals($sha256, $actualHash)) {
            throw new SourceRecordException('asset_hash_mismatch', 'Asset length or SHA-256 differs from its manifest.');
        }
    }

    private function mimeType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (!is_string($mime) || $mime === '') {
            throw new SourceRecordException('asset_mime_unknown', 'Asset MIME type could not be detected from its bytes.');
        }
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }

    private function originalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new SourceRecordException('asset_name_invalid', 'Asset original name is invalid.');
        }
        return $name;
    }

    private function directory(string $path, string $reason): string
    {
        $canonical = realpath($path);
        if ($canonical === false || !is_dir($canonical) || is_link($path)) {
            throw new SourceRecordException($reason, 'Required asset directory is unavailable.');
        }
        return rtrim($canonical, '/');
    }

    private function isUnder(string $candidate, string $root): bool
    {
        return str_starts_with($candidate, rtrim($root, '/') . '/');
    }
}
