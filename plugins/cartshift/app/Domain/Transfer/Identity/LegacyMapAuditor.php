<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\WooStorage;

defined('ABSPATH') || exit;

final class LegacyMapAuditor
{
    /** @var (\Closure(string): array<string, mixed>)|null */
    private readonly ?\Closure $snapshotReader;

    /** @param (callable(string): array<string, mixed>)|null $snapshotReader */
    public function __construct(?callable $snapshotReader = null)
    {
        $this->snapshotReader = $snapshotReader !== null ? $snapshotReader(...) : null;
    }

    public function inspect(string $sourceKey): TargetOwnershipReport
    {
        SourceIdentity::assertValidSourceKey($sourceKey);
        $snapshot = $this->snapshotReader !== null
            ? ($this->snapshotReader)($sourceKey)
            : $this->loadedSnapshot();
        $mappings = array_values((array) ($snapshot['mappings'] ?? []));
        $sourceMappings = array_values(array_filter(
            $mappings,
            static fn (array $row): bool => ($row['source_key'] ?? null) === $sourceKey
                && (int) ($row['is_simulated'] ?? 0) === 0
                && ($row['record_state'] ?? 'legacy') !== MapState::RolledBack->value,
        ));
        $mappingCounts = [];
        $legacyCounts = [];
        $missingCounts = [];
        $duplicateCounts = [];
        $blockers = [];
        $unfingerprinted = 0;
        $ownedOrders = 0;

        foreach ($sourceMappings as $row) {
            $entity = (string) ($row['entity_type'] ?? 'unknown');
            $identity = $this->identity($row);
            $mappingCounts[$entity] = ($mappingCounts[$entity] ?? 0) + 1;

            if (($row['record_state'] ?? 'legacy') === MapState::Legacy->value) {
                $legacyCounts[$entity] = ($legacyCounts[$entity] ?? 0) + 1;
                $blockers[] = $this->blocker('legacy_mapping_requires_audit', $identity, ['entity_type' => $entity]);
            }

            if (!$this->validFingerprint($row['source_fingerprint'] ?? null) || !$this->validFingerprint($row['target_fingerprint'] ?? null)) {
                $unfingerprinted++;
                $blockers[] = $this->blocker('mapping_fingerprint_missing', $identity, ['entity_type' => $entity]);
            }

            if (($row['target_exists'] ?? null) === false) {
                $missingCounts[$entity] = ($missingCounts[$entity] ?? 0) + 1;
                $blockers[] = $this->blocker('mapped_target_missing', $identity, [
                    'entity_type' => $entity,
                    'target_id' => (int) ($row['fc_id'] ?? 0),
                ]);
            }

            if ($entity === 'order' && $this->hasExclusiveClaim($row, (array) ($snapshot['claims'] ?? []))) {
                $ownedOrders++;
            }
        }

        foreach ($this->duplicateGroups($mappings) as $group) {
            if (!array_filter($group, static fn (array $row): bool => ($row['source_key'] ?? null) === $sourceKey)) {
                continue;
            }

            $entity = (string) $group[0]['entity_type'];

            if ($this->sharedGroupIsApproved($group, (array) ($snapshot['shared_links'] ?? []))) {
                continue;
            }

            $duplicateCounts[$entity] = ($duplicateCounts[$entity] ?? 0) + 1;

            foreach ($group as $row) {
                if (($row['source_key'] ?? null) !== $sourceKey) {
                    continue;
                }

                $blockers[] = $this->blocker('duplicate_target_ownership', $this->identity($row), [
                    'entity_type' => $entity,
                    'target_id' => (int) $row['fc_id'],
                ]);
            }
        }

        foreach ($sourceMappings as $row) {
            $entity = (string) ($row['entity_type'] ?? '');

            if (!in_array($entity, ['order', 'subscription'], true) || $this->hasExclusiveClaim($row, (array) ($snapshot['claims'] ?? []))) {
                continue;
            }

            $blockers[] = $this->blocker('exclusive_target_claim_missing', $this->identity($row), [
                'entity_type' => $entity,
                'target_id' => (int) ($row['fc_id'] ?? 0),
            ]);
        }

        $invoiceCollisions = 0;
        $sourceOrderIds = array_map('strval', (array) ($snapshot['source_order_ids'] ?? []));

        foreach ((array) ($snapshot['invoice_orders'] ?? []) as $invoice) {
            $sourceId = (string) ($invoice['source_id'] ?? '');

            if (!in_array($sourceId, $sourceOrderIds, true)) {
                continue;
            }

            $owned = array_filter($sourceMappings, static fn (array $row): bool =>
                ($row['entity_type'] ?? null) === 'order'
                && (string) ($row['wc_id'] ?? '') === $sourceId
                && (int) ($row['fc_id'] ?? 0) === (int) ($invoice['target_id'] ?? 0)
            );

            if ($owned !== []) {
                continue;
            }

            $invoiceCollisions++;
            $blockers[] = $this->blocker('source_identity_conflict', $sourceKey . ':order:' . $sourceId, [
                'entity_type' => 'order',
                'target_id' => (int) ($invoice['target_id'] ?? 0),
                'match_reason' => 'invoice_only',
            ]);
        }

        $receiptCoverage = $this->receiptCoverage($sourceMappings, (array) ($snapshot['receipts'] ?? []), $blockers);
        ksort($mappingCounts);
        ksort($legacyCounts);
        ksort($missingCounts);
        ksort($duplicateCounts);
        usort($blockers, static fn (array $left, array $right): int =>
            [$left['identity'], $left['code']] <=> [$right['identity'], $right['code']]
        );
        $document = [
            'source_key' => $sourceKey,
            'mapping_counts_by_entity' => $mappingCounts,
            'legacy_mapping_counts' => $legacyCounts,
            'missing_target_counts' => $missingCounts,
            'duplicate_target_ownership_counts' => $duplicateCounts,
            'invoice_collision_count' => $invoiceCollisions,
            'unfingerprinted_mapping_count' => $unfingerprinted,
            'receipt_coverage_count' => $receiptCoverage,
            'blockers' => $blockers,
        ];

        return new TargetOwnershipReport(
            $sourceKey,
            $mappingCounts,
            $legacyCounts,
            $missingCounts,
            $duplicateCounts,
            $invoiceCollisions,
            $unfingerprinted,
            $receiptCoverage,
            $ownedOrders,
            $blockers,
            TargetOwnershipReport::fingerprint($document),
        );
    }

    /** @return array<string, mixed> */
    private function loadedSnapshot(): array
    {
        global $wpdb;

        $mapTable = $wpdb->prefix . 'cartshift_id_map';
        $mappingColumns = [
            'source_key',
            'entity_type',
            'wc_id',
            'fc_id',
            'migration_id',
            'created_by_migration',
            'is_simulated',
        ];

        foreach (['source_fingerprint', 'target_fingerprint', 'record_state'] as $v8Column) {
            if ($this->columnExists($mapTable, $v8Column)) {
                $mappingColumns[] = $v8Column;
            }
        }

        $mappings = $this->checkedResults(
            'SELECT ' . implode(', ', $mappingColumns)
                . " FROM {$mapTable} ORDER BY source_key, entity_type, wc_id",
        );
        $sharedTable = $wpdb->prefix . 'cartshift_shared_links';
        $shared = $this->tableExists($sharedTable)
            ? $this->checkedResults(
                "SELECT source_key, entity_type, source_id, target_id, target_fingerprint, decision_fingerprint
                 FROM {$sharedTable} ORDER BY source_key, entity_type, source_id",
            )
            : [];
        $claimsTable = $wpdb->prefix . 'cartshift_target_claims';
        $claims = $this->tableExists($claimsTable)
            ? $this->checkedResults(
                "SELECT source_key, entity_type, source_id, target_id, source_fingerprint, target_fingerprint, claim_state
                 FROM {$claimsTable} ORDER BY source_key, entity_type, source_id",
            )
            : [];
        $sourceOrders = WooStorage::isHposEnabled()
            ? $this->checkedColumn(
                "SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' ORDER BY id",
            )
            : $this->checkedColumn(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' ORDER BY ID",
            );
        $invoiceRows = $this->checkedResults(
            "SELECT id AS target_id, invoice_no FROM {$wpdb->prefix}fct_orders
             WHERE invoice_no REGEXP '^WC-[1-9][0-9]*$' ORDER BY id",
        );

        $mappings = array_map(fn (array $row): array => $row + [
            'source_fingerprint' => null,
            'target_fingerprint' => null,
            'record_state' => MapState::Legacy->value,
            'target_exists' => $this->targetExists((string) $row['entity_type'], (int) $row['fc_id']),
        ], is_array($mappings) ? $mappings : []);

        return [
            'mappings' => $mappings,
            'shared_links' => is_array($shared) ? $shared : [],
            'claims' => is_array($claims) ? $claims : [],
            'source_order_ids' => is_array($sourceOrders) ? $sourceOrders : [],
            'invoice_orders' => array_map(static fn (array $row): array => [
                'source_id' => substr((string) $row['invoice_no'], 3),
                'target_id' => (int) $row['target_id'],
            ], is_array($invoiceRows) ? $invoiceRows : []),
            'receipts' => [],
        ];
    }

    private function targetExists(string $entity, int $targetId): ?bool
    {
        global $wpdb;
        $targets = [
            'product' => [$wpdb->posts, 'ID', "post_type = 'fluent-products'"],
            'customer' => [$wpdb->prefix . 'fct_customers', 'id', '1 = 1'],
            'order' => [$wpdb->prefix . 'fct_orders', 'id', '1 = 1'],
            'subscription' => [$wpdb->prefix . 'fct_subscriptions', 'id', '1 = 1'],
        ];

        if (!isset($targets[$entity])) {
            return null;
        }

        [$table, $idColumn, $predicate] = $targets[$entity];
        $wpdb->last_error = '';
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT {$idColumn} FROM {$table} WHERE {$idColumn} = %d AND {$predicate} LIMIT 1",
            $targetId,
        ));

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('Target ownership inspection failed.');
        }

        return (int) $result === $targetId;
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;
        $wpdb->last_error = '';
        $escaped = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($table) : addcslashes($table, '_%\\');
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $escaped));

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('Target ownership inspection failed.');
        }

        return (string) $found === $table;
    }

    private function columnExists(string $table, string $column): bool
    {
        global $wpdb;
        $wpdb->last_error = '';
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('Target ownership inspection failed.');
        }

        return $found !== null && $found !== '' && $found !== 0 && $found !== '0';
    }

    /** @return list<array<string, mixed>> */
    private function checkedResults(string $query): array
    {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($query, ARRAY_A);

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('Target ownership inspection failed.');
        }

        return is_array($rows) ? array_map(static fn (mixed $row): array => (array) $row, $rows) : [];
    }

    /** @return list<string|int> */
    private function checkedColumn(string $query): array
    {
        global $wpdb;
        $wpdb->last_error = '';
        $values = $wpdb->get_col($query);

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('Target ownership inspection failed.');
        }

        return is_array($values) ? array_values($values) : [];
    }

    /** @return list<list<array<string, mixed>>> */
    private function duplicateGroups(array $mappings): array
    {
        $groups = [];

        foreach ($mappings as $row) {
            if ((int) ($row['is_simulated'] ?? 0) !== 0 || ($row['record_state'] ?? 'legacy') === MapState::RolledBack->value) {
                continue;
            }

            $key = (string) ($row['entity_type'] ?? '') . ':' . (int) ($row['fc_id'] ?? 0);
            $groups[$key][] = $row;
        }

        return array_values(array_filter($groups, static fn (array $group): bool => count($group) > 1));
    }

    private function sharedGroupIsApproved(array $group, array $links): bool
    {
        $entity = (string) ($group[0]['entity_type'] ?? '');

        if (!in_array($entity, ['product', 'customer'], true)) {
            return false;
        }

        foreach ($group as $row) {
            $matched = array_filter($links, fn (array $link): bool =>
                ($link['source_key'] ?? null) === ($row['source_key'] ?? null)
                && ($link['entity_type'] ?? null) === $entity
                && (string) ($link['source_id'] ?? '') === (string) ($row['wc_id'] ?? '')
                && (int) ($link['target_id'] ?? 0) === (int) ($row['fc_id'] ?? 0)
                && ($link['target_fingerprint'] ?? null) === ($row['target_fingerprint'] ?? null)
                && $this->validFingerprint($link['decision_fingerprint'] ?? null)
            );

            if ($matched === []) {
                return false;
            }
        }

        return true;
    }

    private function hasExclusiveClaim(array $mapping, array $claims): bool
    {
        foreach ($claims as $claim) {
            if (
                ($claim['source_key'] ?? null) === ($mapping['source_key'] ?? null)
                && ($claim['entity_type'] ?? null) === ($mapping['entity_type'] ?? null)
                && (string) ($claim['source_id'] ?? '') === (string) ($mapping['wc_id'] ?? '')
                && (int) ($claim['target_id'] ?? 0) === (int) ($mapping['fc_id'] ?? 0)
                && ($claim['source_fingerprint'] ?? null) === ($mapping['source_fingerprint'] ?? null)
                && ($claim['target_fingerprint'] ?? null) === ($mapping['target_fingerprint'] ?? null)
                && ($claim['claim_state'] ?? null) === ($mapping['record_state'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    private function receiptCoverage(array $mappings, array $receipts, array &$blockers): int
    {
        $coverage = 0;

        foreach ($mappings as $mapping) {
            if (($mapping['entity_type'] ?? null) !== 'subscription') {
                continue;
            }

            $covered = array_filter($receipts, static fn (array $receipt): bool =>
                ($receipt['source_key'] ?? null) === ($mapping['source_key'] ?? null)
                && (string) ($receipt['source_id'] ?? '') === (string) ($mapping['wc_id'] ?? '')
                && (int) ($receipt['target_id'] ?? 0) === (int) ($mapping['fc_id'] ?? 0)
                && ($receipt['state'] ?? null) === ($mapping['record_state'] ?? null)
                && ($receipt['source_fingerprint'] ?? null) === ($mapping['source_fingerprint'] ?? null)
                && ($receipt['target_fingerprint'] ?? null) === ($mapping['target_fingerprint'] ?? null)
            );

            if ($covered !== []) {
                $coverage++;
                continue;
            }

            $blockers[] = $this->blocker('subscription_receipt_coverage_missing', $this->identity($mapping), [
                'entity_type' => 'subscription',
                'target_id' => (int) ($mapping['fc_id'] ?? 0),
            ]);
        }

        return $coverage;
    }

    private function identity(array $row): string
    {
        return (string) ($row['source_key'] ?? '') . ':'
            . (string) ($row['entity_type'] ?? '') . ':'
            . (string) ($row['wc_id'] ?? '');
    }

    /** @return array{code: string, identity: string, context: array<string, scalar|null>} */
    private function blocker(string $code, string $identity, array $context): array
    {
        return ['code' => $code, 'identity' => $identity, 'context' => $context];
    }

    private function validFingerprint(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
