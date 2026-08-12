<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class TransferPackageValidator
{
    public function validate(string $path): PackageValidationResult
    {
        try {
            $this->assertValid($path);
            return new PackageValidationResult(true);
        } catch (\Throwable $exception) {
            return new PackageValidationResult(false, [$exception->getMessage()]);
        }
    }

    public function assertValid(string $path): TransferManifest
    {
        $canonical = realpath($path);
        if ($path === '' || $path[0] !== '/' || $canonical === false || is_link($path) || !is_dir($path)) {
            throw new \RuntimeException('Transfer package is not a real directory.');
        }
        $webRoot = defined('ABSPATH') ? realpath(ABSPATH) : false;
        if ($webRoot !== false && ($canonical === $webRoot || str_starts_with($canonical . '/', $webRoot . '/'))) {
            throw new \RuntimeException('Transfer package must remain outside the WordPress web root.');
        }
        if (((fileperms($path) & 0777) & 0077) !== 0) {
            throw new \RuntimeException('Transfer package directory permissions are not private.');
        }
        $entries = $this->entries($path);
        if ($entries !== ['assets', 'manifest.json', 'records.ndjson'] || is_link($path . '/assets') || !is_dir($path . '/assets')) {
            throw new \RuntimeException('Transfer package contains an unexpected path.');
        }
        if (((fileperms($path . '/assets') & 0777) & 0077) !== 0) {
            throw new \RuntimeException('Transfer asset directory permissions are not private.');
        }
        foreach (['manifest.json', 'records.ndjson'] as $file) {
            if (is_link($path . '/' . $file) || !is_file($path . '/' . $file)) {
                throw new \RuntimeException('Transfer package contains an invalid file.');
            }
            if (((fileperms($path . '/' . $file) & 0777) & 0077) !== 0) {
                throw new \RuntimeException('Transfer package file permissions are not private.');
            }
        }

        $manifestBytes = file_get_contents($path . '/manifest.json');
        if (!is_string($manifestBytes)) {
            throw new \RuntimeException('Transfer manifest cannot be read.');
        }
        try {
            $decoded = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \JsonException('Manifest root is not an object.');
            }
            $manifest = TransferManifest::fromArray($decoded);
        } catch (\Throwable) {
            throw new \RuntimeException('Transfer manifest is malformed.');
        }
        if (!hash_equals($manifest->canonicalJson(), $manifestBytes)) {
            throw new \RuntimeException('Transfer manifest is not canonically serialized.');
        }

        $recordsPath = $path . '/records.ndjson';
        if (filesize($recordsPath) !== $manifest->recordsBytes || !hash_equals($manifest->recordsSha256, (string) hash_file('sha256', $recordsPath))) {
            throw new \RuntimeException('Transfer record stream hash or byte count differs from its manifest.');
        }

        $counts = [];
        $hashes = [];
        $previous = null;
        foreach ($this->recordArrays($recordsPath) as $data) {
            $record = self::hydrateRecord($data);
            $canonical = $record->identity->canonical();
            if ($previous instanceof RecordEnvelope && $this->compareRecords($previous, $record) >= 0) {
                throw new \RuntimeException('Transfer records are duplicated or not canonically ordered.');
            }
            $previous = $record;
            $counts[$record->identity->entityType] = ($counts[$record->identity->entityType] ?? 0) + 1;
            $hashes[$canonical] = $record->privateContentDigest;
        }
        ksort($counts);
        ksort($hashes);
        if ($counts !== $manifest->recordCounts || $hashes !== $manifest->recordSha256) {
            throw new \RuntimeException('Transfer record counts or fingerprints differ from the manifest.');
        }

        $assetEntries = $this->entries($path . '/assets');
        $assetMap = [];
        $assetBytes = 0;
        foreach ($assetEntries as $name) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $name) !== 1 || is_link($path . '/assets/' . $name) || !is_file($path . '/assets/' . $name)) {
                throw new \RuntimeException('Transfer package contains an invalid asset path.');
            }
            if (((fileperms($path . '/assets/' . $name) & 0777) & 0077) !== 0) {
                throw new \RuntimeException('Transfer asset permissions are not private.');
            }
            $actual = hash_file('sha256', $path . '/assets/' . $name);
            if (!is_string($actual) || !hash_equals($name, $actual)) {
                throw new \RuntimeException('Transfer asset differs from its content address.');
            }
            $assetMap[$name] = $actual;
            $assetBytes += (int) filesize($path . '/assets/' . $name);
        }
        ksort($assetMap);
        if (count($assetEntries) !== $manifest->assetCount || $assetBytes !== $manifest->assetsBytes || $assetMap !== $manifest->assetSha256) {
            throw new \RuntimeException('Transfer asset inventory differs from its manifest.');
        }

        return $manifest;
    }

    /** @param array<string, mixed> $data */
    public static function hydrateRecord(array $data): RecordEnvelope
    {
        $expected = ['identity', 'payload', 'private_content_digest', 'schema_version', 'source_content_digest', 'structural_fingerprint'];
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== $expected || !is_array($data['identity']) || !is_array($data['payload'])) {
            throw new \RuntimeException('Transfer record shape is invalid.');
        }
        $identity = $data['identity'];
        $identityKeys = array_keys($identity);
        sort($identityKeys);
        if ($identityKeys !== ['entity_type', 'source_id', 'source_key']) {
            throw new \RuntimeException('Transfer record identity shape is invalid.');
        }
        try {
            return new RecordEnvelope(
                $data['schema_version'],
                new SourceIdentity($identity['source_key'], $identity['entity_type'], $identity['source_id']),
                $data['structural_fingerprint'],
                $data['private_content_digest'],
                $data['payload'],
                $data['source_content_digest'],
            );
        } catch (\Throwable) {
            throw new \RuntimeException('Transfer record contract is invalid.');
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function recordArrays(string $path): \Generator
    {
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Transfer record stream cannot be opened.');
        }
        try {
            while (($line = fgets($stream)) !== false) {
                if (!str_ends_with($line, "\n") || trim($line) === '') {
                    throw new \RuntimeException('Transfer record stream contains an invalid line.');
                }
                try {
                    $data = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    throw new \RuntimeException('Transfer record stream contains malformed JSON.');
                }
                if (!is_array($data)) {
                    throw new \RuntimeException('Transfer record stream contains a non-object row.');
                }
                if (!hash_equals(CanonicalJson::encode($data) . "\n", $line)) {
                    throw new \RuntimeException('Transfer record stream contains a non-canonical row.');
                }
                yield $data;
            }
            if (!feof($stream)) {
                throw new \RuntimeException('Transfer record stream could not be read completely.');
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return list<string> */
    private function entries(string $path): array
    {
        $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);
        return $entries;
    }

    private function compareRecords(RecordEnvelope $left, RecordEnvelope $right): int
    {
        $leftRank = array_search($left->identity->kind(), RecordKind::cases(), true);
        $rightRank = array_search($right->identity->kind(), RecordKind::cases(), true);
        $kind = $leftRank <=> $rightRank;
        return $kind !== 0 ? $kind : strnatcmp($left->identity->sourceId, $right->identity->sourceId);
    }
}
