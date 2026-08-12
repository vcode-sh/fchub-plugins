<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final class TargetClaimRepository implements TargetClaimStore
{
    private readonly string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cartshift_target_claims';
    }

    public function claimOrThrow(
        SourceIdentity $identity,
        int $targetId,
        string $runId,
        string $sourceFingerprint,
        string $targetFingerprint,
        MapState $state,
    ): MappingRecord {
        if (!in_array($identity->kind(), [RecordKind::Order, RecordKind::Subscription], true)) {
            throw new \InvalidArgumentException('Only exclusive target kinds may use target claims.');
        }

        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1) {
            throw new \InvalidArgumentException('Target claim run ID is invalid.');
        }

        if (DatabaseTransaction::depth() === 0) {
            throw new \RuntimeException('target_claim_requires_transaction');
        }

        $candidate = new MappingRecord(
            $identity,
            $targetId,
            $sourceFingerprint,
            $targetFingerprint,
            $state,
        );
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert($this->table, [
            'entity_type' => $identity->entityType,
            'target_id' => $targetId,
            'source_key' => $identity->sourceKey,
            'source_id' => $identity->sourceId,
            'run_id' => $runId,
            'source_fingerprint' => $sourceFingerprint,
            'target_fingerprint' => $targetFingerprint,
            'claim_state' => $state->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stored = $this->read($identity->entityType, $targetId);

        if ($stored === null) {
            throw new \RuntimeException('target_claim_write_failed');
        }

        if (!$this->compatible($stored, $candidate, $runId)) {
            throw new TargetAlreadyClaimed();
        }

        if ($inserted === false || $inserted === 1) {
            return $candidate;
        }

        throw new \RuntimeException('target_claim_affected_row_mismatch');
    }

    /** @return array<string, mixed>|null */
    private function read(string $entityType, int $targetId): ?array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT entity_type, target_id, source_key, source_id, run_id,
                    source_fingerprint, target_fingerprint, claim_state
             FROM {$this->table}
             WHERE entity_type = %s AND target_id = %d LIMIT 1",
            $entityType,
            $targetId,
        ), ARRAY_A);

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('target_claim_read_failed');
        }

        if (!is_array($rows) || !isset($rows[0])) {
            return null;
        }

        return (array) $rows[0];
    }

    /** @param array<string, mixed> $stored */
    private function compatible(array $stored, MappingRecord $candidate, string $runId): bool
    {
        return ($stored['source_key'] ?? null) === $candidate->identity->sourceKey
            && ($stored['entity_type'] ?? null) === $candidate->identity->entityType
            && (string) ($stored['source_id'] ?? '') === $candidate->identity->sourceId
            && (int) ($stored['target_id'] ?? 0) === $candidate->targetId
            && ($stored['run_id'] ?? null) === $runId
            && ($stored['source_fingerprint'] ?? null) === $candidate->sourceFingerprint
            && ($stored['target_fingerprint'] ?? null) === $candidate->targetFingerprint
            && ($stored['claim_state'] ?? null) === $candidate->state->value;
    }
}
