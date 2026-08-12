<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\RecordEnvelope;

defined('ABSPATH') || exit;

interface TransferJournal
{
    public function start(PreparedTransfer $prepared): void;
    public function prepared(string $runId): PreparedTransfer;
    public function state(string $runId): TransferRunState;
    public function attempt(string $runId): int;
    public function generation(string $runId): int;
    public function interruptedFrom(string $runId): ?TransferRunState;
    public function failedFrom(string $runId): ?TransferRunState;
    public function transition(string $runId, TransferRunState $expected, TransferRunState $next, bool $newAttempt = false): void;
    public function successfulReceipt(string $runId, RecordEnvelope $record, int $generation): ?TransferReceipt;
    public function commitReceipt(TransferReceipt $receipt): void;
    /** @return list<TransferReceipt> */
    public function pendingReceipts(string $runId): array;
    public function markReceiptExported(TransferReceipt $receipt): void;
    /** @return list<TransferReceipt> */
    public function receipts(string $runId): array;
    public function markRecordRolledBack(TransferReceipt $receipt): void;
    public function markCatalogueStatusRestored(TransferReceipt $receipt): void;
}
