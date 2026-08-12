<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Audit\LoadedWooSourceApi;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Graph\SourceClosureResolver;
use CartShift\Domain\Transfer\Package\DecisionBoundSourceRepository;
use CartShift\Domain\Transfer\Package\LoadedWooReverseDependencyLookup;
use CartShift\Domain\Transfer\Package\LoadedWooRootCensus;
use CartShift\Domain\Transfer\Package\LoadedWooTransferRecordLoader;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Materialises source evidence once and answers deterministic reverse-dependency closures. */
final class GuidedSourceDependencyIndex
{
    /** @var array<string,RecordEnvelope> */
    private array $records = [];

    /** @var array<string,list<string>> */
    private array $dependants = [];

    /** @param iterable<RecordEnvelope> $records */
    public function __construct(iterable $records)
    {
        foreach ($records as $record) {
            if (!$record instanceof RecordEnvelope) {
                throw new \RuntimeException('guided_source_dependency_record_invalid');
            }
            $identity = $record->identity->canonical();
            if (isset($this->records[$identity])) {
                throw new \RuntimeException('guided_source_dependency_duplicate:' . $identity);
            }
            $this->records[$identity] = $record;
        }

        foreach ($this->records as $identity => $record) {
            foreach ($this->dependencies($record) as $dependency) {
                if ($dependency->sourceKey !== $record->identity->sourceKey) {
                    throw new \RuntimeException('guided_source_dependency_namespace_changed');
                }
                if (isset($this->records[$dependency->canonical()])) {
                    $this->dependants[$dependency->canonical()][] = $identity;
                }
            }
        }
        foreach ($this->dependants as &$dependants) {
            $dependants = array_values(array_unique($dependants));
            sort($dependants, SORT_STRING);
        }
        unset($dependants);

        $this->assertAcyclic();
    }

    /** Loads the selected source closure once for every guided decision builder. */
    public static function forLoadedSelection(
        TransferSelection $selection,
        TransferDecisionSet $decisions,
    ): self {
        $source = new LoadedWooSourceApi();
        $census = new LoadedWooRootCensus($source);
        $loader = LoadedWooTransferRecordLoader::fromLoadedRuntime($selection, $decisions, $census);
        $reverse = null;
        $repository = new DecisionBoundSourceRepository(
            $selection,
            $decisions,
            $census,
            array_fill_keys(array_map(static fn (RecordKind $kind): string => $kind->value, [
                RecordKind::Product,
                RecordKind::Customer,
                RecordKind::Order,
                RecordKind::Subscription,
            ]), $loader->load(...)),
            static function ($record, string $kind) use (&$reverse): iterable {
                if (!$reverse instanceof LoadedWooReverseDependencyLookup) {
                    throw new \RuntimeException('reverse_dependency_source_index_unavailable');
                }
                yield from $reverse->records($record, $kind);
            },
        );
        $reverse = new LoadedWooReverseDependencyLookup($selection->sourceKey, $census, $repository->lookup(...));
        $records = (new SourceClosureResolver())->resolve(
            $selection,
            $repository->roots(),
            $repository->lookup(...),
            $repository->reverseLookup(...),
        )->records;

        return new self($records);
    }

    /** @return list<RecordEnvelope> */
    public function records(?RecordKind $kind = null): array
    {
        $records = array_values($this->records);
        return $kind === null
            ? $records
            : array_values(array_filter(
                $records,
                static fn (RecordEnvelope $record): bool => $record->identity->kind() === $kind,
            ));
    }

    public function record(SourceIdentity $identity): RecordEnvelope
    {
        return $this->records[$identity->canonical()]
            ?? throw new \RuntimeException('guided_source_dependency_record_missing:' . $identity->canonical());
    }

    /** @return list<RecordEnvelope> */
    public function closure(SourceIdentity $root): array
    {
        $rootIdentity = $root->canonical();
        if (!isset($this->records[$rootIdentity])) {
            throw new \RuntimeException('guided_source_dependency_root_missing:' . $rootIdentity);
        }

        $included = [];
        $pending = [$rootIdentity];
        while ($pending !== []) {
            $identity = array_pop($pending);
            if ($identity === null || isset($included[$identity])) {
                continue;
            }
            $included[$identity] = true;
            foreach ($this->dependants[$identity] ?? [] as $dependant) {
                $pending[] = $dependant;
            }
        }

        $closure = array_values(array_intersect_key($this->records, $included));
        usort($closure, static function (RecordEnvelope $left, RecordEnvelope $right): int {
            $rank = [
                RecordKind::Product->value => 0,
                RecordKind::Customer->value => 1,
                RecordKind::Order->value => 2,
                RecordKind::Subscription->value => 3,
            ];
            return (($rank[$left->identity->entityType] ?? 4) <=> ($rank[$right->identity->entityType] ?? 4))
                ?: strnatcmp($left->identity->canonical(), $right->identity->canonical());
        });

        return $closure;
    }

    /** @return list<SourceIdentity> */
    private function dependencies(RecordEnvelope $record): array
    {
        $canonical = [];
        $append = static function (mixed $value) use (&$canonical): void {
            if ($value === null) {
                return;
            }
            if (!is_string($value)) {
                throw new \RuntimeException('guided_source_dependency_identity_invalid');
            }
            $identity = SourceIdentity::fromCanonical($value);
            $canonical[$identity->canonical()] = $identity;
        };

        $dependencies = $record->payload['dependencies'] ?? [];
        if (!is_array($dependencies) || !array_is_list($dependencies)) {
            throw new \RuntimeException('guided_source_dependency_list_invalid');
        }
        foreach ($dependencies as $dependency) {
            $append($dependency);
        }
        if ($record->identity->kind() === RecordKind::Order) {
            $append($record->payload['customer'] ?? null);
            $append($record->payload['parent_order'] ?? null);
            foreach ($record->payload['product_lines'] ?? [] as $line) {
                if (!is_array($line)) {
                    throw new \RuntimeException('guided_source_dependency_record_invalid');
                }
                $append($line['product'] ?? null);
            }
        }
        if ($record->identity->kind() === RecordKind::Subscription) {
            $append($record->payload['customer_identity'] ?? null);
            foreach ($record->payload['related_orders'] ?? [] as $reference) {
                if (!is_array($reference)) {
                    throw new \RuntimeException('guided_source_dependency_record_invalid');
                }
                $append($reference['identity'] ?? null);
            }
            foreach ($record->payload['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    throw new \RuntimeException('guided_source_dependency_record_invalid');
                }
                $append($item['product_identity'] ?? null);
            }
        }

        return array_values($canonical);
    }

    private function assertAcyclic(): void
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $identity) use (&$visit, &$visiting, &$visited): void {
            if (isset($visiting[$identity])) {
                throw new \RuntimeException('guided_source_dependency_cycle:' . $identity);
            }
            if (isset($visited[$identity])) {
                return;
            }
            $visiting[$identity] = true;
            foreach ($this->dependants[$identity] ?? [] as $dependant) {
                $visit($dependant);
            }
            unset($visiting[$identity]);
            $visited[$identity] = true;
        };
        foreach (array_keys($this->records) as $identity) {
            $visit($identity);
        }
    }
}
