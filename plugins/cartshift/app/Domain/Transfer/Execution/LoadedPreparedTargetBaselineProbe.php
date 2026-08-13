<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Customer\LoadedFluentCartCustomerGateway;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Identity\LegacyMapAuditor;
use CartShift\Domain\Transfer\Identity\TargetOwnershipReport;
use CartShift\Domain\Transfer\Order\LoadedFluentCartOrderGateway;
use CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\Subscription\LoadedFluentCartSubscriptionGateway;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Captures only pre-run target state; rows owned by the active run are excluded by construction. */
final class LoadedPreparedTargetBaselineProbe implements PreparedTargetBaselineProbe
{
    /** @var \Closure(string):TargetOwnershipReport */
    private readonly \Closure $ownershipReader;

    /** @var \Closure(string,string):array<string,mixed> */
    private readonly \Closure $preexistingReader;

    /** @var \Closure(string,int):array<string,mixed> */
    private readonly \Closure $targetReader;

    /**
     * @param (callable(string):TargetOwnershipReport)|null $ownershipReader
     * @param (callable(string,string):array<string,mixed>)|null $preexistingReader
     * @param (callable(string,int):array<string,mixed>)|null $targetReader
     */
    public function __construct(
        ?callable $ownershipReader = null,
        ?callable $preexistingReader = null,
        ?callable $targetReader = null,
    ) {
        $this->ownershipReader = $ownershipReader === null
            ? static fn (string $sourceKey): TargetOwnershipReport => (new LegacyMapAuditor())->inspect($sourceKey)
            : $ownershipReader(...);
        $this->preexistingReader = $preexistingReader === null
            ? $this->loadedPreexistingRows(...)
            : $preexistingReader(...);
        $this->targetReader = $targetReader === null
            ? $this->loadedTargetSnapshot(...)
            : $targetReader(...);
    }

    public function capture(
        string $sourceKey,
        array $records,
        TransferDecisionSet $decisions,
        string $runId,
    ): PreparedTargetBaseline {
        $report = ($this->ownershipReader)($sourceKey);
        if ($report->sourceKey !== $sourceKey) {
            throw new \RuntimeException('target_ownership_source_changed');
        }
        $recordsByIdentity = [];
        foreach ($records as $record) {
            if (!$record instanceof RecordEnvelope || $record->identity->sourceKey !== $sourceKey) {
                throw new \InvalidArgumentException('target_baseline_record_invalid');
            }
            $recordsByIdentity[$record->identity->canonical()] = $record;
        }

        $protected = [];
        $blockers = [];
        foreach ($report->blockers as $finding) {
            $code = (string) ($finding['code'] ?? 'target_finding_invalid');
            $identity = (string) ($finding['identity'] ?? '');
            if (!isset($recordsByIdentity[$identity])) {
                continue;
            }
            $key = $identity . '|' . $code;
            $decision = $decisions->targetFindings()[$key] ?? null;
            if ($code !== 'source_identity_conflict' || !is_array($decision)) {
                $blockers[] = $code . ':' . $identity;
                continue;
            }
            $expectedSource = CanonicalJson::fingerprint([
                'target_report_fingerprint' => $report->fingerprint,
                'finding' => $finding,
            ]);
            $targetId = (int) ($finding['context']['target_id'] ?? 0);
            if (!isset($recordsByIdentity[$identity])
                || $decision['source_fingerprint'] !== $expectedSource
                || ($decision['candidate_target_id'] ?? null) !== $targetId
                || $targetId <= 0) {
                $blockers[] = 'target_finding_decision_stale:' . $identity;
                continue;
            }
            $snapshot = ($this->targetReader)('order', $targetId);
            $actual = CanonicalJson::fingerprint($snapshot);
            if (!hash_equals((string) $decision['target_fingerprint'], $actual)) {
                $blockers[] = 'target_finding_target_changed:' . $identity;
                continue;
            }
            $protected[$key] = ['kind' => 'order', 'target_id' => $targetId, 'fingerprint' => $actual];
        }

        foreach ($records as $record) {
            $decision = $decisions->for($record->identity);
            if (($decision['action'] ?? null) !== 'reuse_explicit_target_customer') {
                continue;
            }
            $key = $record->identity->canonical() . '|reuse_explicit_target_customer';
            $targetId = (int) ($decision['target_id'] ?? 0);
            $snapshot = $targetId > 0 ? ($this->targetReader)('customer', $targetId) : [];
            $actual = CanonicalJson::fingerprint($snapshot);
            if (!hash_equals((string) ($decision['target_fingerprint'] ?? ''), $actual)) {
                $blockers[] = 'explicit_customer_target_changed:' . $record->identity->canonical();
                continue;
            }
            $protected[$key] = ['kind' => 'customer', 'target_id' => $targetId, 'fingerprint' => $actual];
        }

        foreach ($records as $record) {
            $decision = $decisions->for($record->identity);
            if (($decision['action'] ?? null) !== 'link_existing_product') {
                continue;
            }
            $key = $record->identity->canonical() . '|link_existing_product';
            $targetId = (int) ($decision['target_product_id'] ?? 0);
            $snapshot = $targetId > 0 ? ($this->targetReader)('product', $targetId) : [];
            $sourceMap = [$record->identity->canonical() => $targetId];
            foreach ((array) ($decision['variation_links'] ?? []) as $link) {
                if (is_array($link)) {
                    $sourceMap[(string) ($link['source_variation'] ?? '')] = (int) ($link['target_variation_id'] ?? 0);
                }
            }
            $reviewed = (new ProductTargetFingerprint())->fingerprint($snapshot, $sourceMap);
            if ($targetId <= 0
                || !hash_equals($record->sourceContentDigest, (string) ($decision['source_fingerprint'] ?? ''))
                || !hash_equals((string) ($decision['target_fingerprint'] ?? ''), $reviewed)) {
                $blockers[] = 'linked_product_target_changed:' . $record->identity->canonical();
                continue;
            }
            $protected[$key] = [
                'kind' => 'product',
                'target_id' => $targetId,
                'fingerprint' => CanonicalJson::fingerprint($snapshot),
            ];
        }

        foreach ($decisions->decisions as $identity => $decision) {
            $target = is_array($decision['protected_collision_target'] ?? null)
                ? $decision['protected_collision_target']
                : null;
            if (($decision['action'] ?? null) !== 'excluded_by_policy' || $target === null) {
                continue;
            }
            $source = SourceIdentity::fromCanonical($identity);
            $record = $recordsByIdentity[$identity] ?? null;
            $kind = $target['kind'] ?? null;
            $targetId = $target['target_id'] ?? null;
            $reviewed = $target['target_fingerprint'] ?? null;
            if (!in_array($kind, ['order', 'subscription'], true)
                || $kind !== $source->entityType
                || !is_int($targetId)
                || $targetId <= 0
                || !is_string($reviewed)
                || preg_match('/\A[a-f0-9]{64}\z/D', $reviewed) !== 1
                || ($record instanceof RecordEnvelope
                    && !hash_equals($record->sourceContentDigest, (string) ($decision['source_fingerprint'] ?? '')))) {
                $blockers[] = 'skipped_collision_target_invalid:' . $identity;
                continue;
            }
            $snapshot = ($this->targetReader)($kind, $targetId);
            $actual = CanonicalJson::fingerprint($snapshot);
            if (!hash_equals($reviewed, $actual)) {
                $blockers[] = 'skipped_collision_target_changed:' . $identity;
                continue;
            }
            $protected[$identity . '|protected_collision_target'] = [
                'kind' => $kind,
                'target_id' => $targetId,
                'fingerprint' => $actual,
            ];
        }

        $preexisting = $this->baselineRows(($this->preexistingReader)($sourceKey, $runId));
        foreach ((array) ($preexisting['maps'] ?? []) as $row) {
            $sourceId = (string) ($row['source_id'] ?? $row['wc_id'] ?? '');
            $kind = (string) ($row['entity_type'] ?? '');
            $targetId = (int) ($row['target_id'] ?? $row['fc_id'] ?? 0);
            if ($sourceId === '' || str_contains($sourceId, ':') || $targetId <= 0
                || !in_array($kind, ['product', 'customer', 'order', 'subscription'], true)) {
                continue;
            }
            $identity = $sourceKey . ':' . $kind . ':' . $sourceId;
            $snapshot = ($this->targetReader)($kind, $targetId);
            $protected[$identity . '|preexisting_map'] = [
                'kind' => $kind,
                'target_id' => $targetId,
                'fingerprint' => CanonicalJson::fingerprint($snapshot),
            ];
        }
        ksort($protected, SORT_STRING);
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return new PreparedTargetBaseline($sourceKey, [
            'preexisting_rows' => CanonicalJson::canonicalise($preexisting),
            'protected_targets' => $protected,
        ], $blockers);
    }

    public function verify(PreparedTargetBaseline $baseline, string $runId): void
    {
        $current = CanonicalJson::canonicalise($this->baselineRows(
            ($this->preexistingReader)($baseline->sourceKey, $runId),
        ));
        $expected = CanonicalJson::canonicalise($this->baselineRows(
            (array) ($baseline->snapshot['preexisting_rows'] ?? []),
        ));
        if (CanonicalJson::encode($current) !== CanonicalJson::encode($expected)) {
            throw new \RuntimeException('target_baseline_preexisting_rows_changed');
        }
        foreach ((array) ($baseline->snapshot['protected_targets'] ?? []) as $key => $protected) {
            if (!is_array($protected)) {
                throw new \RuntimeException('target_baseline_protected_target_invalid');
            }
            $actual = CanonicalJson::fingerprint(($this->targetReader)(
                (string) ($protected['kind'] ?? ''),
                (int) ($protected['target_id'] ?? 0),
            ));
            if (!hash_equals((string) ($protected['fingerprint'] ?? ''), $actual)) {
                throw new \RuntimeException('target_baseline_protected_target_changed:' . $key);
            }
        }
    }

    /** @return array<string,mixed> */
    private function loadedPreexistingRows(string $sourceKey, string $runId): array
    {
        global $wpdb;
        $mapTable = $wpdb->prefix . 'cartshift_id_map';
        $claimsTable = $wpdb->prefix . 'cartshift_target_claims';
        $sharedTable = $wpdb->prefix . 'cartshift_shared_links';
        $maps = $this->rows($wpdb->prepare(
            "SELECT source_key, entity_type, wc_id AS source_id, fc_id AS target_id, migration_id,
                    created_by_migration, source_fingerprint, target_fingerprint, record_state
             FROM {$mapTable}
             WHERE source_key = %s AND is_simulated = 0 AND record_state <> 'rolled_back'
               AND COALESCE(migration_id, '') <> %s
             ORDER BY entity_type, wc_id",
            $sourceKey,
            $runId,
        ));
        $claims = $this->rows($wpdb->prepare(
            "SELECT source_key, entity_type, source_id, target_id, run_id, source_fingerprint,
                    target_fingerprint, claim_state
             FROM {$claimsTable}
             WHERE source_key = %s AND run_id <> %s
             ORDER BY entity_type, source_id, target_id",
            $sourceKey,
            $runId,
        ));
        $shared = $this->rows($wpdb->prepare(
            "SELECT source_key, entity_type, source_id, target_id, target_fingerprint, decision_fingerprint
             FROM {$sharedTable}
             WHERE source_key = %s
             ORDER BY entity_type, source_id, target_id",
            $sourceKey,
        ));
        $maps = $this->normaliseDatabaseRows($maps);
        $claims = $this->normaliseDatabaseRows($claims);
        $shared = $this->normaliseDatabaseRows($shared);
        return ['maps' => $maps, 'claims' => $claims, 'shared_links' => $shared];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normaliseDatabaseRows(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (['target_id', 'created_by_migration'] as $field) {
                if (array_key_exists($field, $row)) {
                    $row[$field] = (int) $row[$field];
                }
            }
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $rows @return array<string,mixed> */
    private function baselineRows(array $rows): array
    {
        $maps = array_values(array_filter(
            (array) ($rows['maps'] ?? []),
            static fn (mixed $row): bool => is_array($row)
                && ($row['record_state'] ?? null) !== 'rolled_back',
        ));

        return [
            'maps' => $maps,
            'claims' => array_values((array) ($rows['claims'] ?? [])),
            'shared_links' => array_values((array) ($rows['shared_links'] ?? [])),
        ];
    }

    /** @return array<string,mixed> */
    private function loadedTargetSnapshot(string $kind, int $targetId): array
    {
        if ($targetId <= 0) return [];
        return match ($kind) {
            'product' => (new LoadedFluentCartProductGateway())->snapshot($targetId),
            'customer' => (new LoadedFluentCartCustomerGateway())->snapshot($targetId),
            'order' => (new LoadedFluentCartOrderGateway())->snapshot($targetId),
            'subscription' => (new LoadedFluentCartSubscriptionGateway())->snapshot($targetId),
            default => [],
        };
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('target_baseline_read_failed');
        }
        return is_array($rows) ? array_values(array_map(static fn ($row): array => (array) $row, $rows)) : [];
    }
}
