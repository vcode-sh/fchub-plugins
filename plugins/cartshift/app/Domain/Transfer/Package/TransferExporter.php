<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\AssessmentContext;
use CartShift\Domain\Transfer\AssessmentOutcome;
use CartShift\Domain\Transfer\Graph\SourceClosureResolver;
use CartShift\Domain\Transfer\Graph\TransferDependencyGraph;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordAssessor;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

final readonly class TransferExporter
{
    /** @param array<string, RecordAssessor> $assessors */
    public function __construct(
        private SourceClosureResolver $closureResolver,
        private TransferPackageWriter $writer,
        private SourceInstanceRegistry $sourceRegistry,
        private array $assessors,
    ) {
        foreach ($assessors as $kind => $assessor) {
            if (!is_string($kind) || !$assessor instanceof RecordAssessor) {
                throw new \InvalidArgumentException('Transfer exporter assessors must be keyed by record kind.');
            }
        }
    }

    /**
     * @param iterable<RecordEnvelope> $roots
     * @param callable(SourceIdentity): ?RecordEnvelope $lookup
     * @param null|callable(RecordEnvelope, string): iterable<RecordEnvelope> $reverseLookup
     * @param array<string, mixed> $runtime
     * @param callable(array{identity:string,sha256:string|null,bytes:int|null,locator:string}): resource $assetOpener
     */
    public function export(
        SourceIdentity $source,
        TransferSelection $selection,
        TransferDecisionSet $decisions,
        iterable $roots,
        callable $lookup,
        ?callable $reverseLookup,
        array $runtime,
        callable $assetOpener,
    ): string {
        $sourceInstance = $runtime['source_instance_fingerprint'] ?? null;
        if (!is_string($sourceInstance)) {
            throw new \InvalidArgumentException('Export runtime lacks a source-instance fingerprint.');
        }
        $this->sourceRegistry->requireBinding($source->sourceKey, $sourceInstance);
        $closure = $this->closureResolver->resolve($selection, $roots, $lookup, $reverseLookup);
        $validated = (new TransferDependencyGraph())->validate($closure->records, $decisions);
        if (!$validated->closed) {
            // NAME THE REASONS. The closure already computed why it is open;
            // discarding them left "invalid" with nothing to act on, which is
            // the same unactionable shape the per-record refusal below had.
            throw new SourceRecordException('dependency_graph_blocked', sprintf(
                'Export dependency graph or decision set is invalid: %s.',
                implode(', ', $validated->reasonCodes) ?: 'no reason reported',
            ));
        }
        $context = new AssessmentContext([
            'selection_fingerprint' => $closure->rootSelectionFingerprint,
            'closure_fingerprint' => $closure->materializedClosureFingerprint,
            'decision_fingerprint' => $decisions->fingerprint(),
            'decisions' => $decisions,
        ]);

        foreach ($validated->orderedRecords as $record) {
            $assessor = $this->assessors[$record->identity->entityType] ?? null;
            if (!$assessor instanceof RecordAssessor) {
                throw new SourceRecordException('assessor_missing', 'A selected record kind has no registered assessor.');
            }
            $assessment = $assessor->assess($record, $context);
            if (!in_array($assessment->outcome, [AssessmentOutcome::Ready, AssessmentOutcome::Linked], true)) {
                // NAME THE RECORD. The assessment already knows which row it is
                // and why it stopped; discarding both left an operator with
                // "a selected record" and no way to find it among thousands.
                // The identity is a source key, kind and ID — the same triple
                // every other refusal in the transfer contract reports.
                throw new SourceRecordException('record_blocked', sprintf(
                    'Source record %s did not pass source assessment: %s (%s).',
                    $record->identity->canonical(),
                    $assessment->reasonCode,
                    $assessment->outcome->value,
                ));
            }
        }

        $references = [];
        $knownHashes = [];
        foreach ($validated->orderedRecords as $record) {
            foreach ($this->assetReferences($record) as $reference) {
                $hash = $reference['sha256'];
                if ($hash !== null) {
                    if (isset($knownHashes[$hash]) && $knownHashes[$hash]['bytes'] !== null && $reference['bytes'] !== null && $knownHashes[$hash]['bytes'] !== $reference['bytes']) {
                        throw new SourceRecordException('asset_hash_conflict', 'One asset content address has conflicting byte declarations.');
                    }
                    if (isset($knownHashes[$hash])) continue;
                    $knownHashes[$hash] = $reference;
                }
                $references[] = $reference;
            }
        }

        $assets = [];
        $resolvedHashes = [];
        try {
            foreach ($references as $reference) {
                $stream = $assetOpener($reference);
                if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
                    if (is_resource($stream)) fclose($stream);
                    throw new SourceRecordException('asset_missing', 'Approved asset opener returned no readable stream.');
                }
                if ($reference['sha256'] === null) {
                    [$hash, $bytes, $stream] = $this->hashAndSpool($stream);
                    $reference['sha256'] = $hash;
                    $reference['bytes'] = $bytes;
                }
                $resolvedHashes[$reference['identity']] = $reference['sha256'];
                if (isset($assets[$reference['sha256']])) {
                    fclose($stream);
                    continue;
                }
                $assets[$reference['sha256']] = ['sha256' => $reference['sha256'], 'bytes' => $reference['bytes'], 'stream' => $stream];
            }
            ksort($assets);
            $packageRecords = array_map(fn (RecordEnvelope $record): RecordEnvelope => $this->sanitizeForPackage($record, $resolvedHashes), $validated->orderedRecords);
            return $this->writer->write($source, $selection, $packageRecords, array_values($assets), $runtime);
        } catch (\Throwable $exception) {
            foreach ($assets as $asset) {
                if (is_resource($asset['stream'])) fclose($asset['stream']);
            }
            throw $exception;
        }
    }

    /** @return list<array{identity:string,sha256:string|null,bytes:int|null,locator:string}> */
    private function assetReferences(RecordEnvelope $record): array
    {
        $assets = $record->payload['assets'] ?? [];
        if (!is_array($assets) || !array_is_list($assets)) {
            throw new SourceRecordException('asset_reference_invalid', 'Record asset references are malformed.');
        }
        $result = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)
                || !is_string($asset['sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $asset['sha256']) !== 1
                || (($asset['bytes'] ?? null) !== null && (!is_int($asset['bytes']) || $asset['bytes'] < 0))
                || !is_string($asset['locator'] ?? null)
                || $asset['locator'] === '') {
                throw new SourceRecordException('asset_reference_invalid', 'Record asset reference is incomplete.');
            }
            $result[] = ['identity' => $record->identity->canonical(), 'sha256' => $asset['sha256'], 'bytes' => $asset['bytes'] ?? null, 'locator' => $asset['locator']];
        }
        if ($record->identity->entityType === 'media_asset') {
            $hash = $record->payload['expected_sha256'] ?? null;
            $locator = $record->payload['locator'] ?? null;
            $bytes = $record->payload['size'] ?? null;
            if (($hash === null || is_string($hash)) && is_string($locator)) $result[] = ['identity' => $record->identity->canonical(), 'sha256' => $hash, 'bytes' => is_int($bytes) ? $bytes : null, 'locator' => $locator];
        }
        if ($record->identity->entityType === 'download_asset') {
            $hash = $record->payload['content_sha256'] ?? null;
            $locator = $record->payload['locator'] ?? null;
            if (($hash === null || is_string($hash)) && is_string($locator)) $result[] = ['identity' => $record->identity->canonical(), 'sha256' => $hash, 'bytes' => null, 'locator' => $locator];
        }
        return $result;
    }

    /** @param array<string, string> $resolvedHashes */
    private function sanitizeForPackage(RecordEnvelope $record, array $resolvedHashes): RecordEnvelope
    {
        $payload = $record->payload;
        $payload = $this->withoutAssetLocators($payload, $resolvedHashes);
        $resolved = $resolvedHashes[$record->identity->canonical()] ?? null;
        if ($resolved !== null && $record->identity->entityType === 'media_asset') $payload['expected_sha256'] = $resolved;
        if ($resolved !== null && $record->identity->entityType === 'download_asset') $payload['content_sha256'] = $resolved;
        return RecordEnvelope::forPackagedPayload($record, $payload);
    }

    /** @param array<string, mixed> $payload @param array<string, string> $resolvedHashes @return array<string, mixed> */
    private function withoutAssetLocators(array $payload, array $resolvedHashes): array
    {
        $identity = is_string($payload['identity'] ?? null) ? $payload['identity'] : null;
        $resolved = $identity !== null ? ($resolvedHashes[$identity] ?? null) : null;
        if ($resolved !== null) {
            if (array_key_exists('expected_sha256', $payload)) $payload['expected_sha256'] = $resolved;
            if (array_key_exists('content_sha256', $payload)) $payload['content_sha256'] = $resolved;
        }
        foreach ($payload as $key => $value) {
            if ($key === 'locator') {
                if ($resolved !== null && is_string($value) && $value !== '') {
                    $payload[$key] = $this->packageLocator($value, $resolved);
                } else {
                    unset($payload[$key]);
                }
                continue;
            }
            if (is_array($value)) {
                $payload[$key] = array_is_list($value)
                    ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->withoutAssetLocators($item, $resolvedHashes) : $item, $value)
                    : $this->withoutAssetLocators($value, $resolvedHashes);
            }
        }
        return $payload;
    }

    private function packageLocator(string $sourceLocator, string $sha256): string
    {
        $path = parse_url($sourceLocator, PHP_URL_PATH);
        $name = is_string($path) ? basename(rawurldecode($path)) : '';
        if ($name === '' || $name === '.' || $name === '/') {
            $host = parse_url($sourceLocator, PHP_URL_HOST);
            $name = is_string($host) ? basename(rawurldecode($host)) : '';
        }
        if ($name === '' || $name === '.' || $name === '/') {
            $name = $sha256;
        }
        $name = substr($name, 0, 160);
        return 'https://cartshift-package.invalid/assets/' . $sha256 . '/' . rawurlencode($name);
    }

    /** @param resource $source @return array{string, int, resource} */
    private function hashAndSpool(mixed $source): array
    {
        $spool = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (!is_resource($spool)) {
            fclose($source);
            throw new SourceRecordException('asset_spool_failed', 'Private asset spool could not be opened.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) throw new SourceRecordException('asset_missing', 'Source asset could not be read completely.');
                if ($chunk === '') continue;
                if (fwrite($spool, $chunk) !== strlen($chunk)) throw new SourceRecordException('asset_spool_failed', 'Private asset spool could not be written completely.');
                hash_update($hash, $chunk);
                $bytes += strlen($chunk);
            }
            rewind($spool);
        } catch (\Throwable $exception) {
            fclose($spool);
            throw $exception;
        } finally {
            fclose($source);
        }
        return [hash_final($hash), $bytes, $spool];
    }
}
