<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Exact selected roots plus lazy dependency hydration, with audit exclusions applied before source APIs run. */
final class DecisionBoundSourceRepository
{
    /** @var array<string, \Closure(SourceIdentity): ?RecordEnvelope> */
    private array $loaders = [];

    /** @var array<string, RecordEnvelope|null> */
    private array $cache = [];

    /** @var (\Closure(RecordEnvelope, string): iterable<RecordEnvelope>)|null */
    private readonly ?\Closure $reverseLoader;

    /** @var array<string, true> */
    private array $excluded;

    /**
     * @param array<string, callable(SourceIdentity): ?RecordEnvelope> $loaders
     * @param (callable(RecordEnvelope, string): iterable<RecordEnvelope>)|null $reverseLoader
     */
    public function __construct(
        private readonly TransferSelection $selection,
        TransferDecisionSet $decisions,
        private readonly SourceRootCensus $census,
        array $loaders,
        ?callable $reverseLoader = null,
    ) {
        $decisions->assertSourceKey($selection->sourceKey);
        foreach ($loaders as $kind => $loader) {
            if (RecordKind::tryFrom((string) $kind) === null || !is_callable($loader)) {
                throw new \InvalidArgumentException('Source record loaders must be keyed by canonical record kind.');
            }
            $this->loaders[(string) $kind] = $loader(...);
        }
        $this->reverseLoader = $reverseLoader === null ? null : $reverseLoader(...);
        $this->excluded = $this->excludedIdentities($decisions);
    }

    /** @return iterable<RecordEnvelope> */
    public function roots(): iterable
    {
        $identities = [];
        foreach ($this->census->identities($this->selection) as $identity) {
            if (!$identity instanceof SourceIdentity || $identity->sourceKey !== $this->selection->sourceKey) {
                throw new SourceRecordException('dependency_source_mismatch', 'Source root census changed namespace.');
            }
            $canonical = $identity->canonical();
            if (isset($identities[$canonical])) {
                throw new SourceRecordException(
                    $identity->entityType . '_source_identity_duplicate',
                    'Source root census returned a duplicate identity.',
                );
            }
            $identities[$canonical] = $identity;
        }

        $identities = array_values($identities);
        usort($identities, $this->compare(...));
        foreach ($identities as $identity) {
            if (isset($this->excluded[$identity->canonical()])) {
                continue;
            }
            $record = $this->lookup($identity);
            if (!$record instanceof RecordEnvelope) {
                throw new SourceRecordException('selection_identity_missing', 'Selected source root did not hydrate exactly once.');
            }
            yield $record;
        }
    }

    public function lookup(SourceIdentity $identity): ?RecordEnvelope
    {
        if ($identity->sourceKey !== $this->selection->sourceKey) {
            throw new SourceRecordException('dependency_source_mismatch', 'Dependency belongs to another source namespace.');
        }
        $canonical = $identity->canonical();
        if (isset($this->excluded[$canonical])) {
            return null;
        }
        if (array_key_exists($canonical, $this->cache)) {
            return $this->cache[$canonical];
        }
        $loader = $this->loaders[$identity->entityType] ?? null;
        if (!$loader instanceof \Closure) {
            return $this->cache[$canonical] = null;
        }
        $record = $loader($identity);
        if ($record === null) {
            return $this->cache[$canonical] = null;
        }
        if (!$record instanceof RecordEnvelope || $record->identity->canonical() !== $canonical) {
            throw new SourceRecordException('dependency_ambiguous', 'Dependency loader returned a different source record.');
        }

        return $this->cache[$canonical] = $record;
    }

    /** @return iterable<RecordEnvelope> */
    public function reverseLookup(RecordEnvelope $record, string $kind): iterable
    {
        if ($this->reverseLoader === null) {
            return;
        }
        foreach (($this->reverseLoader)($record, $kind) as $reverse) {
            if (!$reverse instanceof RecordEnvelope
                || $reverse->identity->sourceKey !== $this->selection->sourceKey
                || $reverse->identity->entityType !== $kind) {
                throw new SourceRecordException('dependency_ambiguous', 'Reverse dependency source returned another kind or namespace.');
            }
            yield $reverse;
        }
    }

    /** @return array<string, true> */
    private function excludedIdentities(TransferDecisionSet $decisions): array
    {
        $excluded = [];
        foreach ($decisions->auditFindings() as $decision) {
            if (($decision['action'] ?? null) !== 'excluded_by_policy') {
                continue;
            }
            $identity = SourceIdentity::fromCanonical((string) $decision['identity']);
            $code = (string) ($decision['finding_code'] ?? '');
            $root = $this->excludedRoot($identity, $code);
            if ($root instanceof SourceIdentity) {
                $excluded[$root->canonical()] = true;
            }
        }
        foreach ($decisions->decisions as $canonical => $decision) {
            if (($decision['action'] ?? null) === 'excluded_by_policy') {
                $excluded[$canonical] = true;
            }
        }
        ksort($excluded, SORT_STRING);

        return $excluded;
    }

    private function excludedRoot(SourceIdentity $identity, string $code): ?SourceIdentity
    {
        if ($identity->entityType === RecordKind::Customer->value) {
            return $identity;
        }
        if (preg_match('/\A[1-9][0-9]*\z/D', $identity->sourceId) === 1) {
            return $identity;
        }
        if ($identity->entityType === RecordKind::Product->value
            && str_contains($identity->sourceId, ':variation:')) {
            return new SourceIdentity(
                $identity->sourceKey,
                RecordKind::Product->value,
                explode(':variation:', $identity->sourceId, 2)[0],
            );
        }
        if ($identity->entityType === RecordKind::Order->value
            && $code === 'historical_product_missing'
            && preg_match('/\A([1-9][0-9]*):(?:item|product):/D', $identity->sourceId, $match) === 1) {
            return new SourceIdentity($identity->sourceKey, RecordKind::Order->value, $match[1]);
        }

        return null;
    }

    private function compare(SourceIdentity $left, SourceIdentity $right): int
    {
        $kind = array_search($left->kind(), RecordKind::cases(), true)
            <=> array_search($right->kind(), RecordKind::cases(), true);

        return $kind !== 0 ? $kind : strnatcmp($left->sourceId, $right->sourceId);
    }
}
