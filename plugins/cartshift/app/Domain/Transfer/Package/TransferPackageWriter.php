<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class TransferPackageWriter
{
    public function __construct(private TransferPackageValidator $validator) {}

    /**
     * @param iterable<RecordEnvelope> $records
     * @param iterable<array{sha256: string, bytes: int|null, stream: resource}> $assets
     * @param array<string, mixed> $runtime
     */
    public function write(SourceIdentity $source, TransferSelection $selection, iterable $records, iterable $assets, array $runtime): string
    {
        if ($source->sourceKey !== $selection->sourceKey) {
            throw new \InvalidArgumentException('Package source and selection namespaces differ.');
        }
        $destination = TransferPackagePath::destination($this->requiredString($runtime, 'destination'));
        $temporary = $destination . '/.cartshift-transfer-v2-' . bin2hex(random_bytes(12));
        if (!mkdir($temporary, 0700) || !mkdir($temporary . '/assets', 0700)) {
            throw new \RuntimeException('Private package temporary directory could not be created.');
        }
        chmod($temporary, 0700);
        chmod($temporary . '/assets', 0700);

        try {
            [$recordCounts, $recordHashes, $recordsBytes, $recordsHash] = $this->writeRecords($temporary, $source->sourceKey, $records);
            [$assetHashes, $assetBytes] = $this->writeAssets($temporary, $assets);
            $manifest = new TransferManifest(
                $source->sourceKey,
                $this->requiredHash($runtime, 'source_instance_fingerprint'),
                $this->requiredHash($runtime, 'source_url_hash'),
                $this->requiredHash($runtime, 'source_runtime_fingerprint'),
                $this->requiredHash($runtime, 'source_settings_fingerprint'),
                $this->requiredHash($runtime, 'source_capability_fingerprint'),
                $selection->fingerprint(),
                $this->requiredString($runtime, 'cartshift_version'),
                $this->requiredString($runtime, 'woocommerce_version'),
                isset($runtime['wcs_version']) ? $this->requiredString($runtime, 'wcs_version') : null,
                $this->requiredString($runtime, 'created_at_utc'),
                $recordCounts,
                $recordHashes,
                count($assetHashes),
                $recordsBytes,
                $assetBytes,
                $recordsHash,
                $assetHashes,
            );
            $this->writeFile($temporary . '/manifest.json', $manifest->canonicalJson());
            $this->syncDirectory($temporary . '/assets');
            $this->syncDirectory($temporary);
            $validated = $this->validator->assertValid($temporary);

            $final = $destination . '/' . TransferPackagePath::completedName($source->sourceKey, $selection->fingerprint(), $recordsHash);
            if (file_exists($final) || is_link($final)) {
                $existing = $this->validator->assertValid($final);
                if ($existing->semanticArray() !== $validated->semanticArray()) {
                    throw new \RuntimeException('Existing immutable package differs from the requested package.');
                }
                $this->removeOwnTemporary($temporary, $destination);
                return $final;
            }
            $this->syncDirectory($destination);
            if (!rename($temporary, $final)) {
                throw new \RuntimeException('Validated package could not be promoted atomically.');
            }
            chmod($final, 0700);
            $this->syncDirectory($destination);
            return $final;
        } catch (\Throwable $exception) {
            $this->removeOwnTemporary($temporary, $destination);
            throw $exception;
        }
    }

    /** @param iterable<RecordEnvelope> $records @return array{array<string, int>, array<string, string>, int, string} */
    private function writeRecords(string $temporary, string $sourceKey, iterable $records): array
    {
        $path = $temporary . '/records.ndjson';
        $stream = fopen($path, 'x+b');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Private record stream could not be created.');
        }
        chmod($path, 0600);
        $hash = hash_init('sha256');
        $bytes = 0;
        $counts = [];
        $recordHashes = [];
        $previous = null;
        try {
            foreach ($records as $record) {
                if (!$record instanceof RecordEnvelope || $record->identity->sourceKey !== $sourceKey) {
                    throw new \InvalidArgumentException('Package records must be canonical envelopes in one source namespace.');
                }
                $canonical = $record->identity->canonical();
                if ($previous instanceof RecordEnvelope && $this->compareRecords($previous, $record) >= 0) {
                    throw new \InvalidArgumentException('Package record iterator must be unique and canonically sorted.');
                }
                $previous = $record;
                $line = CanonicalJson::encode([
                    'schema_version' => $record->schemaVersion,
                    'identity' => [
                        'source_key' => $record->identity->sourceKey,
                        'entity_type' => $record->identity->entityType,
                        'source_id' => $record->identity->sourceId,
                    ],
                    'structural_fingerprint' => $record->structuralFingerprint,
                    'private_content_digest' => $record->privateContentDigest,
                    'source_content_digest' => $record->sourceContentDigest,
                    'payload' => $record->payload,
                ]) . "\n";
                $written = fwrite($stream, $line);
                if ($written !== strlen($line)) {
                    throw new \RuntimeException('Private record stream could not be written completely.');
                }
                hash_update($hash, $line);
                $bytes += $written;
                $counts[$record->identity->entityType] = ($counts[$record->identity->entityType] ?? 0) + 1;
                $recordHashes[$canonical] = $record->privateContentDigest;
            }
            if (!fflush($stream) || !function_exists('fsync') || !fsync($stream)) {
                throw new \RuntimeException('Package filesystem cannot prove durable record writes.');
            }
        } finally {
            fclose($stream);
        }
        ksort($counts);
        ksort($recordHashes);
        return [$counts, $recordHashes, $bytes, hash_final($hash)];
    }

    /** @param iterable<array{sha256: string, bytes: int|null, stream: resource}> $assets @return array{array<string, string>, int} */
    private function writeAssets(string $temporary, iterable $assets): array
    {
        $hashes = [];
        $total = 0;
        foreach ($assets as $asset) {
            $declared = $asset['sha256'] ?? null;
            $declaredBytes = $asset['bytes'] ?? null;
            $input = $asset['stream'] ?? null;
            if (!is_string($declared) || preg_match('/\A[a-f0-9]{64}\z/D', $declared) !== 1 || ($declaredBytes !== null && (!is_int($declaredBytes) || $declaredBytes < 0)) || !is_resource($input)) {
                throw new \InvalidArgumentException('Asset stream declaration is invalid.');
            }
            if (isset($hashes[$declared])) {
                throw new \InvalidArgumentException('Duplicate asset content address was supplied.');
            }
            $path = $temporary . '/assets/' . $declared;
            $output = fopen($path, 'x+b');
            if (!is_resource($output)) {
                throw new \RuntimeException('Private asset file could not be created.');
            }
            chmod($path, 0600);
            $context = hash_init('sha256');
            $bytes = 0;
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 1024 * 1024);
                    if ($chunk === false) {
                        throw new \RuntimeException('Asset stream could not be read completely.');
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new \RuntimeException('Asset stream could not be written completely.');
                    }
                    hash_update($context, $chunk);
                    $bytes += strlen($chunk);
                }
                if (!fflush($output) || !function_exists('fsync') || !fsync($output)) {
                    throw new \RuntimeException('Package filesystem cannot prove durable asset writes.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            $actual = hash_final($context);
            if (($declaredBytes !== null && $bytes !== $declaredBytes) || !hash_equals($declared, $actual)) {
                throw new \RuntimeException('Asset stream differs from its declared hash or length.');
            }
            $hashes[$declared] = $actual;
            $total += $bytes;
        }
        ksort($hashes);
        return [$hashes, $total];
    }

    private function writeFile(string $path, string $bytes): void
    {
        $stream = fopen($path, 'x+b');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Private manifest could not be created.');
        }
        chmod($path, 0600);
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !function_exists('fsync') || !fsync($stream)) {
                throw new \RuntimeException('Package filesystem cannot prove durable manifest writes.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function syncDirectory(string $path): void
    {
        $stream = fopen($path, 'r');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Package directory cannot be opened for durability proof.');
        }
        try {
            // Every package file is flushed and fsynced before promotion. Some
            // otherwise perfectly usable filesystems (notably Docker Desktop
            // bind mounts and FUSE) reject fsync on directory handles with
            // EINVAL. PHP does not expose that errno, so directory fsync is a
            // best-effort extra; opening the directory and durable file writes
            // remain mandatory, as does the final atomic rename.
            if (function_exists('fsync')) @fsync($stream);
        } finally {
            fclose($stream);
        }
    }

    private function removeOwnTemporary(string $temporary, string $destination): void
    {
        $prefix = $destination . '/.cartshift-transfer-v2-';
        if (!str_starts_with($temporary, $prefix) || !is_dir($temporary) || is_link($temporary)) {
            return;
        }
        foreach (array_diff(scandir($temporary) ?: [], ['.', '..']) as $entry) {
            $path = $temporary . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) {
                    unlink($path . '/' . $child);
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($temporary);
    }

    /** @param array<string, mixed> $runtime */
    private function requiredString(array $runtime, string $key): string
    {
        $value = $runtime[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Package runtime descriptor is incomplete.');
        }
        return $value;
    }

    /** @param array<string, mixed> $runtime */
    private function requiredHash(array $runtime, string $key): string
    {
        $value = $this->requiredString($runtime, $key);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Package runtime fingerprint is invalid.');
        }
        return $value;
    }

    private function compareRecords(RecordEnvelope $left, RecordEnvelope $right): int
    {
        $leftRank = array_search($left->identity->kind(), RecordKind::cases(), true);
        $rightRank = array_search($right->identity->kind(), RecordKind::cases(), true);
        $kind = $leftRank <=> $rightRank;
        return $kind !== 0 ? $kind : strnatcmp($left->identity->sourceId, $right->identity->sourceId);
    }
}
