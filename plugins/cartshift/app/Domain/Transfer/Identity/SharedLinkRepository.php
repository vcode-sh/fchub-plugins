<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final class SharedLinkRepository
{
    private readonly string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cartshift_shared_links';
    }

    public function storeOrThrow(LinkDecision $decision): LinkDecision
    {
        if (DatabaseTransaction::depth() === 0) {
            throw new \RuntimeException('shared_link_requires_transaction');
        }

        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert($this->table, [
            'source_key' => $decision->source->sourceKey,
            'entity_type' => $decision->source->entityType,
            'source_id' => $decision->source->sourceId,
            'target_id' => $decision->targetId,
            'target_fingerprint' => $decision->targetFingerprint,
            'decision_fingerprint' => $decision->decisionFingerprint,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stored = $this->read($decision);

        if ($stored === null) {
            throw new \RuntimeException('shared_link_write_failed');
        }

        if (
            (int) ($stored['target_id'] ?? 0) !== $decision->targetId
            || ($stored['target_fingerprint'] ?? null) !== $decision->targetFingerprint
            || ($stored['decision_fingerprint'] ?? null) !== $decision->decisionFingerprint
        ) {
            throw IdentityConflict::forIdentity($decision->source);
        }

        if ($inserted !== false && $inserted !== 1) {
            throw new \RuntimeException('shared_link_affected_row_mismatch');
        }

        return $decision;
    }

    /** @return array<string, mixed>|null */
    private function read(LinkDecision $decision): ?array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT target_id, target_fingerprint, decision_fingerprint
             FROM {$this->table}
             WHERE source_key = %s AND entity_type = %s AND source_id = %s LIMIT 1",
            $decision->source->sourceKey,
            $decision->source->entityType,
            $decision->source->sourceId,
        ), ARRAY_A);

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('shared_link_read_failed');
        }

        return is_array($rows) && isset($rows[0]) ? (array) $rows[0] : null;
    }
}
