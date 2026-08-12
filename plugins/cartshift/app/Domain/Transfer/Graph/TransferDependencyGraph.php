<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Graph;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final class TransferDependencyGraph
{
    /** @param iterable<RecordEnvelope> $records */
    public function validate(iterable $records, TransferDecisionSet $decisions): DependencyClosureResult
    {
        $nodes = [];
        $reasons = [];
        foreach ($records as $record) {
            if (!$record instanceof RecordEnvelope) { $reasons[] = 'record_invalid'; continue; }
            $canonical = $record->identity->canonical();
            if (isset($nodes[$canonical])) { $reasons[] = 'record_duplicate'; continue; }
            $nodes[$canonical] = $record;
        }
        foreach ($decisions->decisions as $canonical => $decision) {
            if (!isset($nodes[$canonical])) {
                if (($decision['action'] ?? null) !== 'excluded_by_policy') {
                    $reasons[] = 'decision_unknown_record';
                }
                continue;
            }
            if (!hash_equals($nodes[$canonical]->sourceContentDigest, (string) $decision['source_fingerprint'])) $reasons[] = 'decision_stale';
        }

        $dependencies = [];
        $dependants = [];
        foreach ($nodes as $canonical => $record) {
            $required = [];
            try {
                $required = $this->dependencies($record, true);
                $dependencies[$canonical] = $this->dependencies($record, false);
            }
            catch (\Throwable) { $reasons[] = 'dependency_shape_invalid'; $dependencies[$canonical] = []; }
            foreach ($required ?? [] as $dependency) {
                if (!isset($nodes[$dependency])) $reasons[] = 'dependency_missing';
            }
            foreach ($dependencies[$canonical] as $dependency) {
                if (!isset($nodes[$dependency])) continue;
                $dependants[$dependency][] = $canonical;
            }
        }
        $reasons = array_values(array_unique($reasons));
        sort($reasons);
        if ($reasons !== []) return new DependencyClosureResult(false, [], $reasons);

        $indegree = array_map('count', $dependencies);
        $ready = array_keys(array_filter($indegree, static fn (int $count): bool => $count === 0));
        $ordered = [];
        while ($ready !== []) {
            usort($ready, fn (string $a, string $b): int => $this->compare($nodes[$a], $nodes[$b]));
            $canonical = array_shift($ready);
            $ordered[] = $nodes[$canonical];
            foreach ($dependants[$canonical] ?? [] as $dependant) {
                if (--$indegree[$dependant] === 0) $ready[] = $dependant;
            }
        }
        if (count($ordered) !== count($nodes)) return new DependencyClosureResult(false, [], ['dependency_cycle']);
        return new DependencyClosureResult(true, $ordered, []);
    }

    /** @return list<string> */
    private function dependencies(RecordEnvelope $record, bool $includeSoftProductRelations): array
    {
        $items = $record->payload['dependencies'] ?? [];
        if (!is_array($items) || !array_is_list($items)) throw new \InvalidArgumentException('Dependency list is invalid.');
        if ($record->identity->kind() === RecordKind::Order) {
            foreach (['customer', 'parent_order'] as $field) if (is_string($record->payload[$field] ?? null) && $record->payload[$field] !== '') $items[] = $record->payload[$field];
            foreach (($record->payload['product_lines'] ?? []) as $line) {
                if (!is_array($line)) continue;
                if (is_string($line['product'] ?? null)) $items[] = $line['product'];
                if (is_string($line['variation'] ?? null)) {
                    $variation = SourceIdentity::fromCanonical($line['variation']);
                    $items[] = str_contains($variation->sourceId, ':variation:')
                        ? (new SourceIdentity($variation->sourceKey, RecordKind::Product->value, explode(':variation:', $variation->sourceId, 2)[0]))->canonical()
                        : $variation->canonical();
                }
            }
        }
        if ($record->identity->kind() === RecordKind::Product) {
            if ($includeSoftProductRelations) {
                foreach (['upsell_products', 'cross_sell_products'] as $field) foreach (($record->payload[$field] ?? []) as $item) if (is_string($item)) $items[] = $item;
            }
            foreach (($record->payload['taxonomies'] ?? []) as $item) if (is_array($item) && is_string($item['term_identity'] ?? null)) $items[] = $item['term_identity'];
            foreach (['media', 'downloads'] as $field) foreach (($record->payload[$field] ?? []) as $item) if (is_array($item) && is_string($item['identity'] ?? null)) $items[] = $item['identity'];
        }
        foreach ($items as $item) SourceIdentity::fromCanonical($item);
        $items = array_values(array_unique($items));
        sort($items);
        return $items;
    }

    private function compare(RecordEnvelope $left, RecordEnvelope $right): int
    {
        $kind = array_search($left->identity->kind(), RecordKind::cases(), true) <=> array_search($right->identity->kind(), RecordKind::cases(), true);
        return $kind !== 0 ? $kind : strnatcmp($left->identity->sourceId, $right->identity->sourceId);
    }
}
