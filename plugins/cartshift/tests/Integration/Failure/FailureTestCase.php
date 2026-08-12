<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\ReceiptExporter;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Execution\TargetStateInspector;
use CartShift\Domain\Transfer\Execution\TransferJournal;
use CartShift\Domain\Transfer\Execution\TransferPlan;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\Execution\TransferRunBoundary;
use CartShift\Domain\Transfer\Execution\TransferRunState;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordReconciler;
use CartShift\Domain\Transfer\RecordWriter;
use CartShift\Domain\Transfer\ReconcileContext;
use CartShift\Domain\Transfer\ReconciliationResult;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\StageResult;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

abstract class FailureTestCase extends PluginTestCase
{
    final protected function prepared(?TargetStateFingerprint $target = null): PreparedTransfer
    {
        $target ??= $this->targetState();

        return new PreparedTransfer(
            'run-failure-matrix',
            '/srv/private/cartshift-transfer-v2-contract-package',
            $target->packageHash,
            $target,
            'rehearsal',
            [],
            false,
            '2026-08-10T12:00:00Z',
            'contract-source',
            1,
        );
    }

    final protected function targetState(): TargetStateFingerprint
    {
        return new TargetStateFingerprint(
            str_repeat('1', 64),
            TransferDecisionSet::empty()->fingerprint(),
            str_repeat('3', 64),
            str_repeat('4', 64),
            str_repeat('5', 64),
            str_repeat('6', 64),
            str_repeat('7', 64),
        );
    }

    final protected function record(string $kind = 'product', string $id = '41'): RecordEnvelope
    {
        return RecordEnvelope::forPayload(1, new SourceIdentity('contract-source', $kind, $id), [
            'dependencies' => [],
            'contract_value' => 'private fixture',
        ]);
    }

    /** @param list<RecordEnvelope> $records */
    final protected function plan(PreparedTransfer $prepared, array $records): TransferPlan
    {
        return TransferPlan::build($prepared, $records, TransferDecisionSet::empty());
    }

    final protected function context(?\CartShift\Domain\Transfer\Execution\FilesystemSagaRepository $saga = null): StageContext
    {
        return new StageContext(
            sys_get_temp_dir(),
            'run-failure-matrix',
            str_repeat('2', 64),
            filesystemSaga: $saga,
        );
    }
}

final class FailureGraph
{
    public int $targetRows = 0;
    public int $maps = 0;
    public int $writerCalls = 0;
}

final readonly class FailurePointWriter implements RecordWriter
{
    public function __construct(
        private FailureGraph $graph,
        private ?string $failurePoint = null,
        private array $filesystemOperationIds = [],
    ) {
    }

    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        ++$this->graph->writerCalls;
        $this->hit('before_target_row');
        ++$this->graph->targetRows;
        DatabaseTransaction::afterRollback(function (): void {
            --$this->graph->targetRows;
        });
        $this->hit('after_target_row');
        $this->hit('before_map_write');
        ++$this->graph->maps;
        DatabaseTransaction::afterRollback(function (): void {
            --$this->graph->maps;
        });
        $this->hit('after_map_write');

        return new StageResult(
            901,
            [],
            [],
            [],
            str_repeat('a', 64),
            false,
            $this->filesystemOperationIds,
        );
    }

    private function hit(string $point): void
    {
        if ($this->failurePoint === $point) {
            throw new \RuntimeException('injected_failure:' . $point);
        }
    }
}

final class FailureJournal implements TransferJournal
{
    /** @var array<string, array{prepared: PreparedTransfer, state: TransferRunState, resume: ?TransferRunState, attempt: int}> */
    private array $runs = [];
    /** @var array<string, TransferReceipt> */
    private array $receipts = [];
    /** @var array<string, bool> */
    private array $exported = [];

    public function __construct(private readonly ?string $failurePoint = null)
    {
    }

    public function start(PreparedTransfer $prepared): void
    {
        $this->runs[$prepared->runId] ??= [
            'prepared' => $prepared,
            'state' => TransferRunState::Prepared,
            'resume' => null,
            'attempt' => 0,
        ];
    }

    public function prepared(string $runId): PreparedTransfer
    {
        return $this->runs[$runId]['prepared'];
    }

    public function state(string $runId): TransferRunState
    {
        return $this->runs[$runId]['state'];
    }

    public function attempt(string $runId): int
    {
        return $this->runs[$runId]['attempt'];
    }

    public function generation(string $runId): int
    {
        return $this->prepared($runId)->generation;
    }

    public function interruptedFrom(string $runId): ?TransferRunState
    {
        return $this->runs[$runId]['resume'];
    }

    public function failedFrom(string $runId): ?TransferRunState
    {
        return $this->state($runId) === TransferRunState::Failed ? $this->runs[$runId]['resume'] : null;
    }

    public function transition(string $runId, TransferRunState $expected, TransferRunState $next, bool $newAttempt = false): void
    {
        if ($this->state($runId) !== $expected) {
            throw new \RuntimeException('transfer_run_transition_conflict');
        }
        if ($expected === TransferRunState::Interrupted && $this->runs[$runId]['resume'] !== $next) {
            throw new \RuntimeException('transfer_run_interrupted_phase_mismatch');
        }
        $previousResume = $this->runs[$runId]['resume'];
        $this->runs[$runId]['state'] = $next;
        $this->runs[$runId]['resume'] = match (true) {
            $next === TransferRunState::Interrupted => $expected,
            $next === TransferRunState::Failed && $expected === TransferRunState::Interrupted => $previousResume,
            $next === TransferRunState::Failed => $expected,
            default => null,
        };
        if ($newAttempt) {
            ++$this->runs[$runId]['attempt'];
        }
    }

    public function successfulReceipt(string $runId, RecordEnvelope $record, int $generation): ?TransferReceipt
    {
        return $this->receipts[$this->key($record->identity->entityType, $record->identity->canonical())] ?? null;
    }

    public function commitReceipt(TransferReceipt $receipt): void
    {
        $this->hit('before_receipt_write');
        $key = $this->key($receipt->recordKind, $receipt->sourceIdentity);
        $beforeReceipt = $this->receipts[$key] ?? null;
        $beforeExported = $this->exported[$key] ?? null;
        $this->receipts[$key] = $receipt;
        $this->exported[$key] = false;
        DatabaseTransaction::afterRollback(function () use ($key, $beforeReceipt, $beforeExported): void {
            if ($beforeReceipt === null) {
                unset($this->receipts[$key], $this->exported[$key]);
                return;
            }
            $this->receipts[$key] = $beforeReceipt;
            $this->exported[$key] = (bool) $beforeExported;
        });
        $this->hit('after_receipt_write');
    }

    public function pendingReceipts(string $runId): array
    {
        return array_values(array_filter(
            $this->receipts,
            fn (TransferReceipt $receipt): bool => !$this->exported[$this->key($receipt->recordKind, $receipt->sourceIdentity)],
        ));
    }

    public function markReceiptExported(TransferReceipt $receipt): void
    {
        $this->exported[$this->key($receipt->recordKind, $receipt->sourceIdentity)] = true;
    }

    public function receipts(string $runId): array
    {
        return array_values($this->receipts);
    }

    public function markRecordRolledBack(TransferReceipt $receipt): void
    {
    }

    public function markCatalogueStatusRestored(TransferReceipt $receipt): void
    {
    }

    private function hit(string $point): void
    {
        if ($this->failurePoint === $point) {
            throw new \RuntimeException('injected_failure:' . $point);
        }
    }

    private function key(string $kind, string $identity): string
    {
        return $kind . '|' . $identity;
    }
}

class FailureBoundary implements TransferRunBoundary
{
    /** @var list<string> */
    public array $events = [];
    private ?string $holderId = null;

    public function __construct(private readonly ?string $failurePoint = null)
    {
    }

    public function acquire(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void
    {
        $this->events[] = 'acquire';
        $this->hit('lock_acquire');
        $this->holderId = $holderId;
    }

    public function renew(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void
    {
        $this->events[] = 'renew';
        $this->hit('lock_renew');
        if ($this->holderId !== null && $this->holderId !== $holderId) {
            throw new \RuntimeException('transfer_lease_renewal_conflict');
        }
    }

    public function recover(string $targetFingerprint, string $holderId, string $descriptorHash, string $recoveryEvidenceHash, int $ttl): void
    {
        $this->events[] = 'recover:' . $recoveryEvidenceHash;
        $this->hit('lock_recover');
        $this->holderId = $holderId;
    }

    public function release(string $targetFingerprint, string $holderId, string $descriptorHash): void
    {
        $this->events[] = 'release';
        if ($this->holderId === $holderId) $this->holderId = null;
    }

    public function criticalSection(string $targetFingerprint, string $holderId, string $descriptorHash, callable $mutation): mixed
    {
        $this->events[] = 'critical';
        $this->hit('before_critical_section');
        $result = $mutation();
        $this->hit('after_critical_section');
        return $result;
    }

    private function hit(string $point): void
    {
        if ($this->failurePoint === $point) {
            throw new \RuntimeException('injected_failure:' . $point);
        }
    }
}

final readonly class FixedFailureTargetState implements TargetStateInspector
{
    public function __construct(private TargetStateFingerprint $state)
    {
    }

    public function inspect(): TargetStateFingerprint
    {
        return $this->state;
    }
}

final class FailureReconciler implements RecordReconciler
{
    public int $calls = 0;

    public function __construct(private readonly bool $matches = true)
    {
    }

    public function reconcile(RecordEnvelope $record, ReconcileContext $context): ReconciliationResult
    {
        ++$this->calls;
        return new ReconciliationResult(
            $this->matches,
            $this->matches ? $context->expectedAfterFingerprint : str_repeat('f', 64),
            $this->matches ? [] : ['injected_target_drift'],
        );
    }
}

final class FailureExporter implements ReceiptExporter
{
    public int $files = 0;
    public int $calls = 0;
    /** @var array<string, true> */
    private array $written = [];
    private bool $failureConsumed = false;

    public function __construct(private readonly ?string $failurePoint = null)
    {
    }

    public function export(TransferReceipt $receipt): void
    {
        ++$this->calls;
        if ($this->failurePoint === 'before_receipt_export' && !$this->failureConsumed) {
            $this->failureConsumed = true;
            throw new \RuntimeException('injected_failure:before_receipt_export');
        }
        $this->written[$receipt->payloadHash()] = true;
        $this->files = count($this->written);
        if ($this->failurePoint === 'after_receipt_export' && !$this->failureConsumed) {
            $this->failureConsumed = true;
            throw new \RuntimeException('injected_failure:after_receipt_export');
        }
    }
}
