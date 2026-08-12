<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Graph;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class SourceClosureResolver
{
    /**
     * @param iterable<RecordEnvelope> $roots
     * @param callable(SourceIdentity): ?RecordEnvelope $lookup
     * @param null|callable(RecordEnvelope, string): iterable<RecordEnvelope> $reverseLookup
     */
    public function resolve(
        TransferSelection $selection,
        iterable $roots,
        callable $lookup,
        ?callable $reverseLookup = null,
    ): SourceClosureResult {
        $records = [];
        $queue = [];
        foreach ($roots as $root) {
            $this->assertRecord($root, $selection->sourceKey);
            $queue[] = $root;
        }

        while ($queue !== []) {
            /** @var RecordEnvelope $record */
            $record = array_shift($queue);
            $canonical = $record->identity->canonical();
            if (isset($records[$canonical])) {
                if (!hash_equals($records[$canonical]->privateContentDigest, $record->privateContentDigest)) {
                    throw new SourceRecordException('dependency_ambiguous', 'Dependency identity resolved to conflicting source records.');
                }
                continue;
            }
            $records[$canonical] = $record;

            foreach ($this->dependencies($record) as $identity) {
                if ($identity->sourceKey !== $selection->sourceKey) {
                    throw new SourceRecordException('dependency_source_mismatch', 'Dependency belongs to a different source namespace.');
                }
                if (isset($records[$identity->canonical()])) {
                    continue;
                }
                $dependency = $this->embeddedDependency($record, $identity);
                if (!$dependency instanceof RecordEnvelope && $identity->entityType === RecordKind::Product->value && str_contains($identity->sourceId, ':variation:')) {
                    $dependency = $lookup(new SourceIdentity($identity->sourceKey, RecordKind::Product->value, explode(':variation:', $identity->sourceId, 2)[0]));
                }
                $dependency ??= $lookup($identity);
                if (!$dependency instanceof RecordEnvelope) {
                    throw new SourceRecordException('dependency_missing', 'A required dependency could not be resolved.');
                }
                $this->assertRecord($dependency, $selection->sourceKey);
                $variationSatisfiedByParent = $identity->entityType === RecordKind::Product->value
                    && str_contains($identity->sourceId, ':variation:')
                    && $dependency->identity->sourceId === explode(':variation:', $identity->sourceId, 2)[0];
                if ($dependency->identity->canonical() !== $identity->canonical() && !$variationSatisfiedByParent) {
                    throw new SourceRecordException('dependency_ambiguous', 'Dependency lookup returned a different identity.');
                }
                $queue[] = $dependency;
            }

            if ($reverseLookup !== null) {
                foreach ($selection->reverseDependencies as $kind) {
                    foreach ($reverseLookup($record, $kind) as $reverse) {
                        $this->assertRecord($reverse, $selection->sourceKey);
                        if ($reverse->identity->entityType !== $kind) {
                            throw new SourceRecordException('dependency_ambiguous', 'Reverse dependency lookup returned the wrong kind.');
                        }
                        $queue[] = $reverse;
                    }
                }
            }
        }

        $records = array_values($records);
        usort($records, $this->compare(...));
        $closure = array_map(static fn (RecordEnvelope $record): array => [
            'identity' => $record->identity->canonical(),
            'structural_fingerprint' => $record->structuralFingerprint,
            'private_content_digest' => $record->privateContentDigest,
        ], $records);

        return new SourceClosureResult(
            $records,
            $selection->fingerprint(),
            CanonicalJson::fingerprint(['materialized_closure' => $closure]),
        );
    }

    /** @return list<SourceIdentity> */
    private function dependencies(RecordEnvelope $record): array
    {
        $canonical = [];
        $explicit = $record->payload['dependencies'] ?? [];
        if (!is_array($explicit) || !array_is_list($explicit)) {
            throw new SourceRecordException('dependency_shape_invalid', 'Record dependency list is malformed.');
        }
        foreach ($explicit as $identity) {
            if (!is_string($identity)) {
                throw new SourceRecordException('dependency_shape_invalid', 'Record dependency identity is malformed.');
            }
            $canonical[] = $identity;
        }

        if ($record->identity->kind() === RecordKind::Order) {
            foreach (['customer', 'parent_order'] as $field) {
                if (is_string($record->payload[$field] ?? null) && $record->payload[$field] !== '') {
                    $canonical[] = $record->payload[$field];
                }
            }
            foreach (($record->payload['product_lines'] ?? []) as $line) {
                if (!is_array($line)) continue;
                foreach (['product', 'variation'] as $field) {
                    if (is_string($line[$field] ?? null) && $line[$field] !== '') $canonical[] = $line[$field];
                }
            }
        }
        if ($record->identity->kind() === RecordKind::Product) {
            foreach (['upsell_products', 'cross_sell_products'] as $field) {
                foreach (($record->payload[$field] ?? []) as $identity) if (is_string($identity)) $canonical[] = $identity;
            }
            foreach (($record->payload['taxonomies'] ?? []) as $taxonomy) {
                if (!is_array($taxonomy)) continue;
                foreach (['term_identity', 'parent'] as $field) if (is_string($taxonomy[$field] ?? null) && $taxonomy[$field] !== '') $canonical[] = $taxonomy[$field];
            }
            foreach (['media', 'downloads'] as $field) {
                foreach ($this->productAssets($record->payload, $field) as $asset) {
                    if (is_string($asset['identity'] ?? null)) {
                        $canonical[] = $asset['identity'];
                    }
                }
            }
        }

        $canonical = array_values(array_unique($canonical));
        sort($canonical, SORT_STRING);
        try {
            return array_map(SourceIdentity::fromCanonical(...), $canonical);
        } catch (\Throwable) {
            throw new SourceRecordException('dependency_shape_invalid', 'Record dependency identity is malformed.');
        }
    }

    private function assertRecord(mixed $record, string $sourceKey): void
    {
        if (!$record instanceof RecordEnvelope || $record->identity->sourceKey !== $sourceKey) {
            throw new SourceRecordException('dependency_source_mismatch', 'Closure contains a record from another source namespace.');
        }
    }

    private function embeddedDependency(RecordEnvelope $owner, SourceIdentity $identity): ?RecordEnvelope
    {
        if ($owner->identity->kind() !== RecordKind::Product) return null;
        if ($identity->kind() === RecordKind::TaxonomyTerm) {
            foreach (($owner->payload['taxonomies'] ?? []) as $taxonomy) {
                if (!is_array($taxonomy) || ($taxonomy['term_identity'] ?? null) !== $identity->canonical()) continue;
                $payload = array_intersect_key($taxonomy, array_flip([
                    'taxonomy', 'term_identity', 'name', 'slug', 'description', 'parent',
                ]));
                $dependencies = [];
                if (is_string($taxonomy['parent'] ?? null) && $taxonomy['parent'] !== '') $dependencies[] = $taxonomy['parent'];
                $payload['dependencies'] = $dependencies;
                return RecordEnvelope::forPayload($owner->schemaVersion, $identity, $payload);
            }
        }
        $field = match ($identity->kind()) {
            RecordKind::MediaAsset => 'media',
            RecordKind::DownloadAsset => 'downloads',
            default => null,
        };
        if ($field !== null) {
            foreach ($this->productAssets($owner->payload, $field) as $asset) {
                if (!is_array($asset) || ($asset['identity'] ?? null) !== $identity->canonical()) continue;
                if ($identity->kind() === RecordKind::MediaAsset) {
                    $asset = array_intersect_key($asset, array_flip([
                        'identity', 'locator', 'mime_type', 'size', 'expected_sha256',
                    ]));
                }
                $asset['dependencies'] = [];
                return RecordEnvelope::forPayload($owner->schemaVersion, $identity, $asset);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private function productAssets(array $payload, string $field): array
    {
        $assets = [];
        $rootAssets = $payload[$field] ?? [];
        if (!is_array($rootAssets) || !array_is_list($rootAssets)) {
            throw new SourceRecordException('dependency_shape_invalid', 'Product asset dependencies are malformed.');
        }
        foreach ($rootAssets as $asset) {
            if (!is_array($asset)) {
                throw new SourceRecordException('dependency_shape_invalid', 'Product asset dependency is malformed.');
            }
            $assets[] = $asset;
        }
        $variations = $payload['variations'] ?? [];
        if (!is_array($variations) || !array_is_list($variations)) {
            throw new SourceRecordException('dependency_shape_invalid', 'Product variation dependencies are malformed.');
        }
        foreach ($variations as $variation) {
            if (!is_array($variation)) {
                throw new SourceRecordException('dependency_shape_invalid', 'Product variation dependency is malformed.');
            }
            $variationAssets = $variation[$field] ?? [];
            if (!is_array($variationAssets) || !array_is_list($variationAssets)) {
                throw new SourceRecordException('dependency_shape_invalid', 'Product variation asset dependencies are malformed.');
            }
            foreach ($variationAssets as $asset) {
                if (!is_array($asset)) {
                    throw new SourceRecordException('dependency_shape_invalid', 'Product variation asset dependency is malformed.');
                }
                $assets[] = $asset;
            }
        }
        return $assets;
    }

    private function compare(RecordEnvelope $left, RecordEnvelope $right): int
    {
        $leftRank = array_search($left->identity->kind(), RecordKind::cases(), true);
        $rightRank = array_search($right->identity->kind(), RecordKind::cases(), true);
        $kind = $leftRank <=> $rightRank;
        return $kind !== 0 ? $kind : strnatcmp($left->identity->sourceId, $right->identity->sourceId);
    }
}
