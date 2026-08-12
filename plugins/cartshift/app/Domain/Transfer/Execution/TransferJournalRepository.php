<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Support\CanonicalJson;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final readonly class TransferJournalRepository implements TransferJournal
{
    public function __construct(
        private PreparedTransferRepository $descriptors,
        private ?object $database = null,
    ) {
    }

    public function start(PreparedTransfer $prepared): void
    {
        $stored = $this->descriptors->get($prepared->runId);
        if (!hash_equals($stored->descriptorHash(), $prepared->descriptorHash())) {
            throw new \RuntimeException('prepared_transfer_descriptor_changed');
        }
        $db = $this->db();
        $now = $this->now();
        $inserted = $db->insert($this->runsTable(), [
            'run_id' => $prepared->runId,
            'descriptor_hash' => $prepared->descriptorHash(),
            'package_hash' => $prepared->targetState->packageHash,
            'decision_hash' => $prepared->targetState->decisionHash,
            'runtime_hash' => $prepared->targetState->compatibilityHash,
            'settings_hash' => $prepared->targetState->settingsHash,
            'target_hash' => $prepared->targetState->targetHash,
            'state' => TransferRunState::Prepared->value,
            'resume_state' => null,
            'attempt' => 0,
            'generation' => $prepared->generation,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === false) {
            $this->assertRunMatches($this->readRun($prepared->runId), $prepared);
        } else {
            $this->assertRunMatches($this->readRun($prepared->runId), $prepared);
        }
    }

    public function prepared(string $runId): PreparedTransfer
    {
        return $this->descriptors->get($runId);
    }

    public function state(string $runId): TransferRunState
    {
        $state = TransferRunState::tryFrom((string) $this->readRun($runId)->state);
        if ($state === null) throw new \RuntimeException('transfer_run_state_unknown');
        return $state;
    }

    public function attempt(string $runId): int
    {
        return (int) $this->readRun($runId)->attempt;
    }

    public function generation(string $runId): int
    {
        $generation = (int) $this->readRun($runId)->generation;
        if ($generation < 1) {
            throw new \RuntimeException('transfer_run_generation_invalid');
        }
        return $generation;
    }

    public function interruptedFrom(string $runId): ?TransferRunState
    {
        $value = $this->readRun($runId)->resume_state ?? null;
        if ($value === null || $value === '') return null;
        $state = TransferRunState::tryFrom((string) $value);
        if ($state === null || !in_array($state, [TransferRunState::Staging, TransferRunState::Reconciling, TransferRunState::CatalogueActivating], true)) {
            throw new \RuntimeException('transfer_run_resume_state_invalid');
        }
        return $state;
    }

    public function failedFrom(string $runId): ?TransferRunState
    {
        $row = $this->readRun($runId);
        if (($row->state ?? null) !== TransferRunState::Failed->value) return null;
        $value = $row->resume_state ?? null;
        if ($value === null || $value === '') return null;
        $state = TransferRunState::tryFrom((string) $value);
        if ($state === null || !in_array($state, [
            TransferRunState::Staging,
            TransferRunState::Reconciling,
            TransferRunState::CatalogueActivating,
            TransferRunState::RollingBack,
        ], true)) {
            throw new \RuntimeException('transfer_run_failed_phase_invalid');
        }
        return $state;
    }

    public function transition(string $runId, TransferRunState $expected, TransferRunState $next, bool $newAttempt = false): void
    {
        if (!$expected->canTransitionTo($next)) throw new \RuntimeException('transfer_run_transition_illegal');
        $prepared = $this->prepared($runId);
        $row = $this->readRun($runId);
        $this->assertRunMatches($row, $prepared);
        if ($expected === TransferRunState::Interrupted && $this->interruptedFrom($runId) !== $next) {
            throw new \RuntimeException('transfer_run_interrupted_phase_mismatch');
        }
        $attempt = (int) $row->attempt;
        $where = $this->runIdentity($prepared) + [
            'state' => $expected->value,
            'attempt' => $attempt,
            'generation' => (int) $row->generation,
        ];
        $resumeState = match (true) {
            $next === TransferRunState::Interrupted => $expected->value,
            $next === TransferRunState::Failed && $expected === TransferRunState::Interrupted => $this->interruptedFrom($runId)?->value,
            $next === TransferRunState::Failed => $expected->value,
            default => null,
        };
        $updated = $this->db()->update($this->runsTable(), [
            'state' => $next->value,
            'resume_state' => $resumeState,
            'attempt' => $newAttempt ? $attempt + 1 : $attempt,
            'updated_at' => $this->now(),
        ], $where);
        if ($updated !== 1) throw new \RuntimeException('transfer_run_transition_conflict');
        $after = $this->readRun($runId);
        if ((string) $after->state !== $next->value || (int) $after->attempt !== ($newAttempt ? $attempt + 1 : $attempt)) {
            throw new \RuntimeException('transfer_run_transition_not_persisted');
        }
    }

    public function successfulReceipt(string $runId, RecordEnvelope $record, int $generation): ?TransferReceipt
    {
        $rows = $this->db()->get_results($this->db()->prepare(
            "SELECT payload, payload_hash FROM {$this->outboxTable()}
             WHERE run_id = %s AND record_kind = %s AND source_identity = %s AND generation = %d
             ORDER BY id ASC LIMIT 2",
            $runId,
            $record->identity->entityType,
            $record->identity->canonical(),
            $generation,
        ));
        if (trim((string) ($this->db()->last_error ?? '')) !== '') throw new \RuntimeException('transfer_outbox_read_failed');
        if (!is_array($rows) || $rows === []) return null;
        if (count($rows) !== 1) throw new \RuntimeException('transfer_outbox_duplicate');
        $receipt = $this->hydrateReceipt($rows[0]);
        if (!hash_equals($record->privateContentDigest, $receipt->sourceFingerprint)) {
            throw new \RuntimeException('receipt_source_fingerprint_changed');
        }
        return $receipt;
    }

    public function commitReceipt(TransferReceipt $receipt): void
    {
        if (DatabaseTransaction::depth() < 1) throw new \RuntimeException('transfer_receipt_requires_active_transaction');
        $payload = CanonicalJson::encode($receipt->toArray());
        $targetIds = CanonicalJson::encode($receipt->targetIds);
        $recordInserted = $this->db()->insert($this->recordsTable(), [
            'run_id' => $receipt->runId,
            'record_kind' => $receipt->recordKind,
            'source_identity' => $receipt->sourceIdentity,
            'generation' => $receipt->generation,
            'source_fingerprint' => $receipt->sourceFingerprint,
            'target_fingerprint' => $receipt->afterFingerprint,
            'action' => $receipt->action,
            'state' => 'successful',
            'target_ids' => $targetIds,
            'before_hash' => $receipt->beforeFingerprint,
            'after_hash' => $receipt->afterFingerprint,
            'error_code' => null,
            'created_at' => $this->mysqlTime($receipt->startedAtUtc),
            'updated_at' => $this->mysqlTime($receipt->completedAtUtc),
        ]);
        if ($recordInserted !== 1) throw new \RuntimeException('transfer_journal_record_write_failed');
        $outboxInserted = $this->db()->insert($this->outboxTable(), [
            'run_id' => $receipt->runId,
            'record_kind' => $receipt->recordKind,
            'source_identity' => $receipt->sourceIdentity,
            'generation' => $receipt->generation,
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'exported_at' => null,
            'created_at' => $this->mysqlTime($receipt->completedAtUtc),
        ]);
        if ($outboxInserted !== 1) throw new \RuntimeException('transfer_receipt_outbox_write_failed');
    }

    public function pendingReceipts(string $runId): array
    {
        return $this->readReceipts($runId, true);
    }

    public function markReceiptExported(TransferReceipt $receipt): void
    {
        $payload = CanonicalJson::encode($receipt->toArray());
        $updated = $this->db()->update($this->outboxTable(), ['exported_at' => $this->now()], [
            'run_id' => $receipt->runId,
            'record_kind' => $receipt->recordKind,
            'source_identity' => $receipt->sourceIdentity,
            'generation' => $receipt->generation,
            'payload_hash' => hash('sha256', $payload),
            'exported_at' => null,
        ]);
        if ($updated !== 1) throw new \RuntimeException('transfer_receipt_outbox_export_conflict');
    }

    public function receipts(string $runId): array
    {
        return $this->readReceipts($runId, false);
    }

    public function markRecordRolledBack(TransferReceipt $receipt): void
    {
        if (DatabaseTransaction::depth() < 1) {
            throw new \RuntimeException('transfer_record_rollback_requires_active_transaction');
        }
        $updated = $this->db()->update($this->recordsTable(), [
            'state' => 'rolled_back',
            'updated_at' => $this->now(),
        ], [
            'run_id' => $receipt->runId,
            'record_kind' => $receipt->recordKind,
            'source_identity' => $receipt->sourceIdentity,
            'generation' => $receipt->generation,
            'state' => 'successful',
            'after_hash' => $receipt->afterFingerprint,
        ]);
        if ($updated !== 1) throw new \RuntimeException('transfer_record_rollback_journal_conflict');
        $mappingTargets = [$receipt->sourceIdentity => $receipt->targetIds['primary']];
        foreach ($receipt->targetIds as $canonical => $targetId) {
            try {
                \CartShift\Domain\Transfer\SourceIdentity::fromCanonical($canonical);
                $mappingTargets[$canonical] = $targetId;
            } catch (\InvalidArgumentException) {
                // Operational target IDs without a source identity are not ID-map rows.
            }
        }
        foreach ($mappingTargets as $canonical => $targetId) {
            $identity = \CartShift\Domain\Transfer\SourceIdentity::fromCanonical($canonical);
            $mapUpdate = $this->db()->update($this->db()->prefix . 'cartshift_id_map', [
                'record_state' => 'rolled_back',
                'updated_at' => $this->now(),
            ], [
                'source_key' => $identity->sourceKey,
                'entity_type' => $identity->entityType,
                'wc_id' => $identity->sourceId,
                'fc_id' => $targetId,
                'migration_id' => $receipt->runId,
                'is_simulated' => 0,
                'target_fingerprint' => $receipt->afterFingerprint,
            ]);
            if ($mapUpdate === false) throw new \RuntimeException('transfer_record_rollback_map_failure');
            if ($mapUpdate !== 1 && !$this->sharedMapBelongsToAnotherReceipt($identity, $targetId, $receipt)) {
                throw new \RuntimeException('transfer_record_rollback_map_conflict');
            }
        }
        if (in_array($receipt->recordKind, ['order', 'subscription'], true)) {
            $claimUpdate = $this->db()->update($this->db()->prefix . 'cartshift_target_claims', [
                'claim_state' => 'rolled_back',
                'updated_at' => $this->now(),
            ], [
                'entity_type' => $receipt->recordKind,
                'target_id' => $receipt->targetIds['primary'],
                'run_id' => $receipt->runId,
                'source_fingerprint' => $receipt->sourceFingerprint,
                'target_fingerprint' => $receipt->afterFingerprint,
                'claim_state' => 'reconciled',
            ]);
            if ($claimUpdate !== 1) {
                throw new \RuntimeException('transfer_record_rollback_claim_conflict');
            }
        }
    }

    private function sharedMapBelongsToAnotherReceipt(
        \CartShift\Domain\Transfer\SourceIdentity $identity,
        int $targetId,
        TransferReceipt $receipt,
    ): bool {
        $db = $this->db();
        $maps = $db->get_results($db->prepare(
            "SELECT target_fingerprint, record_state FROM {$db->prefix}cartshift_id_map
             WHERE source_key = %s AND entity_type = %s AND wc_id = %s AND fc_id = %d
               AND migration_id = %s AND is_simulated = 0 ORDER BY id ASC LIMIT 2",
            $identity->sourceKey, $identity->entityType, $identity->sourceId, $targetId, $receipt->runId,
        ));
        if (trim((string) ($db->last_error ?? '')) !== '' || !is_array($maps) || count($maps) !== 1) return false;
        $fingerprint = (string) ($maps[0]->target_fingerprint ?? '');
        if (($maps[0]->record_state ?? null) !== 'reconciled'
            || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1
            || hash_equals($receipt->afterFingerprint, $fingerprint)) {
            return false;
        }
        $owners = $db->get_results($db->prepare(
            "SELECT after_hash FROM {$this->recordsTable()}
             WHERE run_id = %s AND generation = %d AND state = 'successful' AND after_hash = %s
             ORDER BY id ASC LIMIT 2",
            $receipt->runId, $receipt->generation, $fingerprint,
        ));
        return trim((string) ($db->last_error ?? '')) === '' && is_array($owners) && count($owners) === 1
            && hash_equals($fingerprint, (string) ($owners[0]->after_hash ?? ''));
    }

    public function markCatalogueStatusRestored(TransferReceipt $receipt): void
    {
        if ($receipt->recordKind !== 'catalogue_status' || $receipt->action !== 'catalogue_status') {
            throw new \InvalidArgumentException('Only catalogue status receipts may be marked restored.');
        }
        $updated = $this->db()->update($this->recordsTable(), [
            'state' => 'restored',
            'updated_at' => $this->now(),
        ], [
            'run_id' => $receipt->runId,
            'record_kind' => $receipt->recordKind,
            'source_identity' => $receipt->sourceIdentity,
            'generation' => $receipt->generation,
            'state' => 'successful',
            'after_hash' => $receipt->afterFingerprint,
        ]);
        if ($updated !== 1) {
            throw new \RuntimeException('catalogue_status_restore_journal_conflict');
        }
    }

    /** @return list<TransferReceipt> */
    private function readReceipts(string $runId, bool $pendingOnly): array
    {
        $predicate = $pendingOnly ? ' AND exported_at IS NULL' : '';
        $rows = $this->db()->get_results($this->db()->prepare(
            "SELECT payload, payload_hash FROM {$this->outboxTable()} WHERE run_id = %s{$predicate} ORDER BY id ASC",
            $runId,
        ));
        if (trim((string) ($this->db()->last_error ?? '')) !== '') throw new \RuntimeException('transfer_outbox_read_failed');
        return array_map(fn (object $row): TransferReceipt => $this->hydrateReceipt($row), is_array($rows) ? $rows : []);
    }

    private function hydrateReceipt(object $row): TransferReceipt
    {
        $payload = (string) ($row->payload ?? '');
        $hash = (string) ($row->payload_hash ?? '');
        if ($payload === '' || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1 || !hash_equals($hash, hash('sha256', $payload))) {
            throw new \RuntimeException('transfer_receipt_outbox_hash_mismatch');
        }
        $data = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('transfer_receipt_outbox_payload_invalid');
        return TransferReceipt::fromArray($data);
    }

    private function readRun(string $runId): object
    {
        $rows = $this->db()->get_results($this->db()->prepare(
            "SELECT run_id, descriptor_hash, package_hash, decision_hash, runtime_hash, settings_hash,
                    target_hash, state, resume_state, attempt, generation, created_at, updated_at
             FROM {$this->runsTable()} WHERE run_id = %s LIMIT 2",
            $runId,
        ));
        if (trim((string) ($this->db()->last_error ?? '')) !== '') throw new \RuntimeException('transfer_run_read_failed');
        if (!is_array($rows) || count($rows) !== 1) throw new \RuntimeException('transfer_run_missing_or_duplicate');
        return $rows[0];
    }

    private function assertRunMatches(object $row, PreparedTransfer $prepared): void
    {
        foreach ($this->runIdentity($prepared) as $field => $expected) {
            if (!isset($row->{$field}) || !hash_equals((string) $row->{$field}, (string) $expected)) {
                throw new \RuntimeException('transfer_run_descriptor_conflict:' . $field);
            }
        }
        if ((int) ($row->generation ?? 0) !== $prepared->generation) throw new \RuntimeException('transfer_run_generation_conflict');
    }

    /** @return array<string, string> */
    private function runIdentity(PreparedTransfer $prepared): array
    {
        return [
            'run_id' => $prepared->runId,
            'descriptor_hash' => $prepared->descriptorHash(),
            'package_hash' => $prepared->targetState->packageHash,
            'decision_hash' => $prepared->targetState->decisionHash,
            'runtime_hash' => $prepared->targetState->compatibilityHash,
            'settings_hash' => $prepared->targetState->settingsHash,
            'target_hash' => $prepared->targetState->targetHash,
        ];
    }

    private function db(): object
    {
        if ($this->database !== null) return $this->database;
        global $wpdb;
        if (!is_object($wpdb)) throw new \RuntimeException('transfer_journal_database_unavailable');
        return $wpdb;
    }

    private function runsTable(): string { return $this->db()->prefix . 'cartshift_transfer_runs'; }
    private function recordsTable(): string { return $this->db()->prefix . 'cartshift_transfer_records'; }
    private function outboxTable(): string { return $this->db()->prefix . 'cartshift_transfer_outbox'; }
    private function now(): string { return gmdate('Y-m-d H:i:s'); }
    private function mysqlTime(string $utc): string { return str_replace(['T', 'Z'], [' ', ''], $utc); }
}
