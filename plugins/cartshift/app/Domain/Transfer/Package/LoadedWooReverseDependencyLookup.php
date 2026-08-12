<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Lazy exact reverse index built from the same strict records that would enter the package. */
final class LoadedWooReverseDependencyLookup
{
    /** @var \Closure(SourceIdentity): ?RecordEnvelope */
    private readonly \Closure $lookup;

    /** @var array<string, array<string, list<RecordEnvelope>>> */
    private array $indexes = [];

    /** @param callable(SourceIdentity): ?RecordEnvelope $lookup */
    public function __construct(
        private readonly string $sourceKey,
        private readonly SourceRootCensus $census,
        callable $lookup,
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);
        $this->lookup = $lookup(...);
    }

    /** @return iterable<RecordEnvelope> */
    public function records(RecordEnvelope $owner, string $kind): iterable
    {
        if (!in_array($kind, [RecordKind::Order->value, RecordKind::Subscription->value], true)) {
            throw new SourceRecordException('dependency_ambiguous', 'Reverse dependency kind is unsupported.');
        }
        if ($owner->identity->sourceKey !== $this->sourceKey) {
            throw new SourceRecordException('dependency_source_mismatch', 'Reverse dependency owner changed namespace.');
        }
        $this->indexes[$kind] ??= $this->build($kind);
        yield from $this->indexes[$kind][$owner->identity->canonical()] ?? [];
    }

    /** @return array<string, list<RecordEnvelope>> */
    private function build(string $kind): array
    {
        $selection = new TransferSelection(
            $this->sourceKey,
            SelectionClause::none(),
            SelectionClause::none(),
            $kind === RecordKind::Order->value ? SelectionClause::all() : SelectionClause::none(),
            $kind === RecordKind::Subscription->value ? SelectionClause::all() : SelectionClause::none(),
        );
        $index = [];
        $seen = [];
        foreach ($this->census->identities($selection) as $identity) {
            if ($identity->entityType !== $kind || isset($seen[$identity->canonical()])) {
                if (isset($seen[$identity->canonical()])) {
                    throw new SourceRecordException('dependency_ambiguous', 'Reverse source census contains a duplicate identity.');
                }
                continue;
            }
            $seen[$identity->canonical()] = true;
            $record = ($this->lookup)($identity);
            if (!$record instanceof RecordEnvelope) {
                continue; // Exact audit exclusions remain exclusions when reverse closure scans the kind.
            }
            foreach ($this->dependencies($record) as $dependency) {
                $index[$dependency][] = $record;
            }
        }
        foreach ($index as &$records) {
            usort($records, static fn (RecordEnvelope $left, RecordEnvelope $right): int =>
                strnatcmp($left->identity->sourceId, $right->identity->sourceId));
        }
        unset($records);
        ksort($index, SORT_STRING);

        return $index;
    }

    /** @return list<string> */
    private function dependencies(RecordEnvelope $record): array
    {
        $dependencies = $record->payload['dependencies'] ?? [];
        if (!is_array($dependencies) || !array_is_list($dependencies)) {
            throw new SourceRecordException('dependency_shape_invalid', 'Reverse source record dependency list is malformed.');
        }
        if ($record->identity->kind() === RecordKind::Order) {
            foreach (['customer', 'parent_order'] as $field) {
                if (is_string($record->payload[$field] ?? null) && $record->payload[$field] !== '') {
                    $dependencies[] = $record->payload[$field];
                }
            }
            foreach (($record->payload['product_lines'] ?? []) as $line) {
                if (!is_array($line)) continue;
                if (is_string($line['product'] ?? null)) $dependencies[] = $line['product'];
                if (is_string($line['variation'] ?? null)) {
                    $dependencies[] = $line['variation'];
                    $variation = SourceIdentity::fromCanonical($line['variation']);
                    if (str_contains($variation->sourceId, ':variation:')) {
                        $dependencies[] = (new SourceIdentity(
                            $variation->sourceKey,
                            RecordKind::Product->value,
                            explode(':variation:', $variation->sourceId, 2)[0],
                        ))->canonical();
                    }
                }
            }
        }
        if ($record->identity->kind() === RecordKind::Subscription) {
            if (is_string($record->payload['customer_identity'] ?? null)) {
                $dependencies[] = $record->payload['customer_identity'];
            }
            foreach (($record->payload['related_orders'] ?? []) as $relation) {
                if (is_array($relation) && is_string($relation['identity'] ?? null)) {
                    $dependencies[] = $relation['identity'];
                }
            }
            foreach (($record->payload['items'] ?? []) as $item) {
                if (!is_array($item)) continue;
                foreach (['product_identity', 'variation_identity'] as $field) {
                    if (is_string($item[$field] ?? null)) $dependencies[] = $item[$field];
                }
            }
        }
        $canonical = [];
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency)) {
                throw new SourceRecordException('dependency_shape_invalid', 'Reverse source dependency identity is malformed.');
            }
            $identity = SourceIdentity::fromCanonical($dependency);
            if ($identity->sourceKey !== $this->sourceKey) {
                throw new SourceRecordException('dependency_source_mismatch', 'Reverse source dependency changed namespace.');
            }
            $canonical[$identity->canonical()] = true;
        }
        $values = array_keys($canonical);
        sort($values, SORT_STRING);

        return $values;
    }
}
