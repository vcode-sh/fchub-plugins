<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Decision;

use CartShift\Domain\Transfer\Audit\LoadedWooSourceApi;
use CartShift\Domain\Transfer\Audit\LoadedWooTransferAuditor;
use CartShift\Domain\Transfer\Graph\SourceClosureResolver;
use CartShift\Domain\Transfer\Package\DecisionBoundSourceRepository;
use CartShift\Domain\Transfer\Package\LoadedWooReverseDependencyLookup;
use CartShift\Domain\Transfer\Package\LoadedWooRootCensus;
use CartShift\Domain\Transfer\Package\LoadedWooTransferRecordLoader;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Production, source-only composition for decision proposals. */
final class LoadedWooDecisionProposalPipeline
{
    public static function create(): TransferDecisionProposalPipeline
    {
        $auditor = LoadedWooTransferAuditor::create();
        return new TransferDecisionProposalPipeline(
            $auditor->audit(...),
            static function (TransferSelection $selection, TransferDecisionSet $decisions): iterable {
                $source = new LoadedWooSourceApi();
                $census = new LoadedWooRootCensus($source);
                $loader = LoadedWooTransferRecordLoader::fromLoadedRuntime($selection, $decisions, $census);
                $reverse = null;
                $repository = new DecisionBoundSourceRepository(
                    $selection,
                    $decisions,
                    $census,
                    array_fill_keys(
                        array_map(static fn (RecordKind $kind): string => $kind->value, [
                            RecordKind::Product,
                            RecordKind::Customer,
                            RecordKind::Order,
                            RecordKind::Subscription,
                        ]),
                        $loader->load(...),
                    ),
                    static function ($record, string $kind) use (&$reverse): iterable {
                        if (!$reverse instanceof LoadedWooReverseDependencyLookup) {
                            throw new \RuntimeException('reverse_dependency_source_index_unavailable');
                        }
                        yield from $reverse->records($record, $kind);
                    },
                );
                $reverse = new LoadedWooReverseDependencyLookup(
                    $selection->sourceKey,
                    $census,
                    $repository->lookup(...),
                );
                return (new SourceClosureResolver())->resolve(
                    $selection,
                    $repository->roots(),
                    $repository->lookup(...),
                    $repository->reverseLookup(...),
                )->records;
            },
        );
    }
}
