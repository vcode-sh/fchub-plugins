<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Graph\TransferDependencyGraph;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;

defined('ABSPATH') || exit;

final readonly class TransferPlan
{
    /** @param list<RecordEnvelope> $records */
    private function __construct(
        public PreparedTransfer $prepared,
        public array $records,
        public TransferDecisionSet $decisions,
    ) {
    }

    /** @param iterable<RecordEnvelope> $records */
    public static function build(
        PreparedTransfer $prepared,
        iterable $records,
        TransferDecisionSet $decisions,
        ?TransferDependencyGraph $graph = null,
    ): self {
        if (!hash_equals($prepared->targetState->decisionHash, $decisions->fingerprint())) {
            throw new \RuntimeException('prepared_decision_fingerprint_changed');
        }
        $records = is_array($records) ? array_values($records) : iterator_to_array($records, false);
        $closure = ($graph ?? new TransferDependencyGraph())->validate($records, $decisions);
        if (!$closure->closed) {
            throw new \RuntimeException('transfer_dependency_graph_blocked:' . implode(',', $closure->reasonCodes));
        }
        foreach ($closure->orderedRecords as $record) {
            if ($record->identity->sourceKey !== $prepared->sourceKey) {
                throw new \RuntimeException('transfer_record_source_namespace_changed');
            }
        }
        $rootRecords = array_values(array_filter(
            $closure->orderedRecords,
            static fn (RecordEnvelope $record): bool => in_array($record->identity->kind(), [
                RecordKind::Product,
                RecordKind::Customer,
                RecordKind::Order,
                RecordKind::Subscription,
            ], true),
        ));
        return new self($prepared, $rootRecords, $decisions);
    }
}
