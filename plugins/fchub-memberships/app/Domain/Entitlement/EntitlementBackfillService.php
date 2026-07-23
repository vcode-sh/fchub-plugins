<?php

namespace FChubMemberships\Domain\Entitlement;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;

defined('ABSPATH') || exit;

final class EntitlementBackfillService
{
    private \Closure $feedResolver;
    private \Closure $audit;

    public function __construct(
        private GrantRepository $grants,
        private GrantSourceRepository $sources,
        private EntitlementEdgeRepository $edges,
        private EntitlementService $entitlements,
        ?callable $feedResolver = null,
        ?callable $audit = null
    ) {
        $this->feedResolver = $feedResolver !== null
            ? \Closure::fromCallable($feedResolver)
            : $this->resolveCurrentFeed(...);
        $this->audit = $audit !== null
            ? \Closure::fromCallable($audit)
            : static function (string $action, array $data): void {
                AuditLogger::log('entitlement_backfill', 0, $action, [], $data, 'cli');
            };
    }

    public function previewBatch(int $after = 0, int $limit = 100, ?int $through = null): array
    {
        return $this->processBatch(false, $after, $limit, $through);
    }

    public function applyBatch(int $after = 0, int $limit = 100, ?int $through = null): array
    {
        return $this->processBatch(true, $after, $limit, $through);
    }

    private function processBatch(bool $apply, int $after, int $limit, ?int $through): array
    {
        $watermark = $through;
        $cursor = max(0, $after);
        $failureReason = 'invalid_arguments';
        if ($apply) {
            ($this->audit)('entitlement_backfill_apply_intent', [
                'after' => $after,
                'limit' => $limit,
                'through_grant_id' => $through,
            ]);
        }

        try {
            if ($after < 0) {
                throw new \InvalidArgumentException('Entitlement backfill cursor cannot be negative.');
            }
            if ($limit < 1 || $limit > 500) {
                throw new \InvalidArgumentException('Entitlement backfill limit must be between 1 and 500.');
            }

            if ($watermark === null) {
                $failureReason = 'watermark_read_failed';
                $watermark = $this->grants->getEntitlementBackfillWatermark();
            }
            $failureReason = 'invalid_arguments';
            if ($watermark < 0) {
                throw new \InvalidArgumentException('Entitlement backfill watermark cannot be negative.');
            }
            $cursor = min($after, $watermark);

            $failureReason = 'grant_batch_read_failed';
            $rows = $this->grants->getEntitlementBackfillBatch($cursor, $watermark, $limit);
            $items = [];
            $failed = false;

            foreach ($rows as $grant) {
                $failureReason = 'grant_evaluation_failed';
                $classified = $this->classify($grant);
                $item = $classified['report'];
                $grantId = (int) $item['grant_id'];

                if ($apply && $item['classification'] === 'refused') {
                    $items[] = $item;
                    $failed = true;
                    break;
                }

                if ($apply && $item['classification'] !== 'already_migrated') {
                    $actions = [];
                    try {
                        foreach ($classified['records'] as $record) {
                            $result = $this->entitlements->recordHistoricalEdge(
                                $record['identity'],
                                $record['attributes']
                            );
                            if (!in_array($result['action'], ['created', 'replayed'], true)) {
                                throw new \RuntimeException('The historical entitlement conflicts with an existing edge.');
                            }
                            $actions[] = $result['action'];
                        }
                    } catch (\Throwable) {
                        $item['classification'] = 'refused';
                        $item['reason_codes'] = ['edge_persistence_failed'];
                        $items[] = $item;
                        $failed = true;
                        break;
                    }

                    if ($actions !== [] && count(array_unique($actions)) === 1 && $actions[0] === 'replayed') {
                        $item['classification'] = 'already_migrated';
                        $item['reason_codes'] = ['edge_already_migrated'];
                    }
                }

                $items[] = $item;
                $cursor = $grantId;
            }

            $failureReason = 'unexpected_failure';
            $counts = $this->counts($items);
            $complete = !$failed && ($cursor >= $watermark || count($rows) < $limit);
            $report = [
                'items' => $items,
                'counts' => $counts,
                'next_cursor' => $cursor,
                'through_grant_id' => $watermark,
                'complete' => $complete,
            ];
        } catch (\Throwable $exception) {
            if ($apply) {
                ($this->audit)('entitlement_backfill_apply_outcome', [
                    'status' => 'failed',
                    'reason_code' => $failureReason,
                    'next_cursor' => $cursor,
                    'through_grant_id' => $watermark,
                    'complete' => false,
                ]);
            }

            throw $exception;
        }

        if ($apply) {
            ($this->audit)('entitlement_backfill_apply_outcome', [
                'status' => $failed ? 'stopped' : 'processed',
                'reason_code' => $failed ? 'grant_refused' : 'batch_processed',
                'counts' => $counts,
                'next_cursor' => $cursor,
                'through_grant_id' => $watermark,
                'complete' => $complete,
            ]);
        }

        return $report;
    }

    /** @return array{report:array<string, mixed>, records:list<array<string, array<string, mixed>>>} */
    private function classify(array $grant): array
    {
        $grantId = (int) ($grant['id'] ?? 0);
        $refusal = $this->refusalReason($grant);
        if ($refusal !== null) {
            return [
                'report' => $this->reportItem($grantId, 'refused', [], [$refusal]),
                'records' => [],
            ];
        }

        $reasonCodes = [];
        $classification = 'deterministic';
        $typedSources = $this->typedSources($grantId);
        if ($typedSources === null) {
            return [
                'report' => $this->reportItem($grantId, 'refused', [], ['typed_source_malformed']),
                'records' => [],
            ];
        }
        if ($typedSources === []) {
            $typedSources = [['source_type' => 'external_unknown', 'source_id' => 0]];
            $classification = 'external_unknown';
            $reasonCodes[] = 'typed_sources_missing';
        } else {
            $reasonCodes[] = 'typed_sources_authoritative';
        }

        $feedId = max(0, (int) ($grant['feed_id'] ?? 0));
        $planId = max(0, (int) ($grant['plan_id'] ?? 0));
        if ($planId === 0) {
            $classification = 'external_unknown';
            $reasonCodes[] = 'plan_id_missing';
        }
        $feedScope = 'external_unknown';
        if ($feedId === 0) {
            $classification = 'external_unknown';
            $reasonCodes[] = 'feed_id_missing';
        } elseif ($planId === 0) {
            $reasonCodes[] = 'feed_relation_not_authoritative_without_plan';
        } else {
            $relation = ($this->feedResolver)($feedId, $planId);
            $relationScope = is_array($relation) ? ($relation['scope'] ?? null) : null;
            $relationPlanId = is_array($relation) ? (int) ($relation['plan_id'] ?? -1) : -1;
            if (in_array($relationScope, ['product', 'global'], true) && $relationPlanId === $planId) {
                $feedScope = $relationScope;
                $reasonCodes[] = 'feed_relation_exact';
            } else {
                $classification = 'external_unknown';
                $reasonCodes[] = is_array($relation) && isset($relation['reason_code'])
                    ? (string) $relation['reason_code']
                    : ($relation === null ? 'feed_relation_missing' : 'feed_relation_stale');
            }
        }

        $ownership = (string) (($grant['meta']['provider_access_owner'] ?? ''));
        [$owner, $provenance] = match ($ownership) {
            'fchub' => ['fchub', 'fchub_created'],
            'preexisting' => ['fchub', 'preexisting'],
            default => ['external_unknown', 'unknown'],
        };
        if ($ownership === 'fchub') {
            $reasonCodes[] = 'ownership_marker_fchub';
        } elseif ($ownership === 'preexisting') {
            $reasonCodes[] = 'ownership_marker_preexisting';
        } else {
            $classification = 'external_unknown';
            $reasonCodes[] = $ownership === ''
                ? 'ownership_marker_missing'
                : 'ownership_marker_unknown';
        }

        $lifecycle = in_array($grant['status'], ['active', 'paused'], true) ? 'active' : 'ended';
        $accessStatus = $grant['status'] === 'paused' ? 'paused' : 'active';
        $endedAt = $lifecycle === 'ended' ? (string) $grant['updated_at'] : null;
        $endReason = $lifecycle === 'ended' ? 'legacy_' . $grant['status'] : null;
        $records = [];
        $proposed = [];
        foreach ($typedSources as $source) {
            $identity = [
                'user_id' => (int) $grant['user_id'],
                'provider' => trim((string) $grant['provider']),
                'resource_type' => trim((string) $grant['resource_type']),
                'resource_id' => trim((string) $grant['resource_id']),
                'plan_id' => $planId,
                'feed_id' => $feedId,
                'feed_scope' => $feedScope,
                'source_type' => $source['source_type'],
                'source_id' => $source['source_id'],
            ];
            $attributes = [
                'owner' => $owner,
                'assignment_provenance' => $provenance,
                'lifecycle' => $lifecycle,
                'access_status' => $accessStatus,
                'starts_at' => $grant['starts_at'] ?? null,
                'expires_at' => $grant['expires_at'] ?? null,
                'drip_available_at' => $grant['drip_available_at'] ?? null,
                'ended_at' => $endedAt,
                'end_reason' => $endReason,
                'policy' => [],
                'created_at' => $grant['created_at'],
                'updated_at' => $grant['updated_at'],
            ];
            $records[] = ['identity' => $identity, 'attributes' => $attributes];
            $proposed[] = array_merge(
                array_diff_key($identity, ['user_id' => true]),
                array_intersect_key($attributes, array_flip([
                    'owner',
                    'assignment_provenance',
                    'lifecycle',
                    'access_status',
                    'starts_at',
                    'expires_at',
                    'drip_available_at',
                    'ended_at',
                    'end_reason',
                ]))
            );
        }

        $existingCount = 0;
        $conflict = false;
        foreach ($records as $record) {
            $existing = $this->edges->findByIdentity($record['identity']);
            if (!$existing) {
                continue;
            }
            if (!$this->matches($existing, $record['attributes'])) {
                $conflict = true;
                break;
            }
            $existingCount++;
        }
        if ($conflict) {
            $classification = 'refused';
            $reasonCodes = ['existing_edge_conflict'];
        } elseif ($existingCount === count($records)) {
            $classification = 'already_migrated';
            $reasonCodes = ['edge_already_migrated'];
        } elseif ($existingCount > 0) {
            $reasonCodes[] = 'partial_migration';
        }

        return [
            'report' => $this->reportItem(
                $grantId,
                $classification,
                $proposed,
                array_values(array_unique($reasonCodes))
            ),
            'records' => $records,
        ];
    }

    private function refusalReason(array $grant): ?string
    {
        if ((int) ($grant['id'] ?? 0) <= 0 || (int) ($grant['user_id'] ?? 0) <= 0) {
            return 'malformed_grant_identity';
        }
        foreach (['provider', 'resource_type', 'resource_id'] as $field) {
            if (trim((string) ($grant[$field] ?? '')) === '') {
                return 'malformed_grant_identity';
            }
        }
        if (!in_array($grant['status'] ?? null, ['active', 'paused', 'expired', 'revoked'], true)) {
            return 'unsupported_grant_status';
        }
        if ((int) ($grant['plan_id'] ?? 0) < 0 || (int) ($grant['feed_id'] ?? 0) < 0) {
            return 'malformed_grant_identity';
        }
        foreach (['created_at', 'updated_at'] as $field) {
            if (!$this->isStorageTimestamp($grant[$field] ?? null)) {
                return 'malformed_grant_timestamps';
            }
        }
        foreach (['starts_at', 'expires_at', 'drip_available_at'] as $field) {
            if (($grant[$field] ?? null) !== null && !$this->isStorageTimestamp($grant[$field])) {
                return 'malformed_grant_timestamps';
            }
        }

        return null;
    }

    private function isStorageTimestamp(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $timestamp = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $timestamp !== false && $timestamp->format('Y-m-d H:i:s') === $value;
    }

    /** @return list<array{source_type:string, source_id:int}>|null */
    private function typedSources(int $grantId): ?array
    {
        $sources = [];
        foreach ($this->sources->getSourcesByGrant($grantId) as $source) {
            $sourceType = trim((string) ($source['source_type'] ?? ''));
            $rawSourceId = $source['source_id'] ?? null;
            if (
                $sourceType === ''
                || $rawSourceId === null
                || preg_match('/^\d+$/', (string) $rawSourceId) !== 1
            ) {
                return null;
            }
            $sourceId = (int) $rawSourceId;
            $sources[$sourceType . "\0" . $sourceId] = [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ];
        }
        $sources = array_values($sources);
        usort($sources, static function (array $left, array $right): int {
            $typeComparison = strcmp($left['source_type'], $right['source_type']);
            return $typeComparison !== 0
                ? $typeComparison
                : ($left['source_id'] <=> $right['source_id']);
        });

        return $sources;
    }

    private function matches(array $existing, array $attributes): bool
    {
        foreach ([
            'owner',
            'assignment_provenance',
            'lifecycle',
            'access_status',
            'starts_at',
            'expires_at',
            'drip_available_at',
            'ended_at',
            'end_reason',
            'policy',
        ] as $field) {
            if (($existing[$field] ?? null) !== ($attributes[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function reportItem(int $grantId, string $classification, array $proposed, array $reasonCodes): array
    {
        return [
            'grant_id' => $grantId,
            'classification' => $classification,
            'proposed_edges' => $proposed,
            'reason_codes' => $reasonCodes,
        ];
    }

    private function counts(array $items): array
    {
        $counts = [
            'deterministic' => 0,
            'external_unknown' => 0,
            'refused' => 0,
            'already_migrated' => 0,
        ];
        foreach ($items as $item) {
            $classification = $item['classification'];
            if (array_key_exists($classification, $counts)) {
                $counts[$classification]++;
            }
        }

        return $counts;
    }

    private function resolveCurrentFeed(int $feedId, int $planId): ?array
    {
        global $wpdb;

        $productTable = $wpdb->prefix . 'fct_product_meta';
        $globalTable = $wpdb->prefix . 'fct_meta';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT 'product' AS scope, meta_value
             FROM {$productTable}
             WHERE id = %d AND object_type = 'product_integration' AND meta_key = 'memberships'
             UNION ALL
             SELECT 'global' AS scope, meta_value
             FROM {$globalTable}
             WHERE id = %d AND object_type = 'order_integration' AND meta_key = 'memberships'",
            $feedId,
            $feedId
        ), ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) {
            throw new \RuntimeException('The current FluentCart feed relation could not be read.');
        }

        if (count($rows) === 0) {
            return null;
        }
        if (count($rows) !== 1) {
            return ['scope' => 'external_unknown', 'plan_id' => $planId, 'reason_code' => 'feed_relation_ambiguous'];
        }

        $settings = json_decode((string) ($rows[0]['meta_value'] ?? ''), true);
        if (!is_array($settings)) {
            return ['scope' => 'external_unknown', 'plan_id' => -1, 'reason_code' => 'feed_relation_malformed'];
        }

        return [
            'scope' => (string) $rows[0]['scope'],
            'plan_id' => (int) ($settings['plan_id'] ?? -1),
        ];
    }
}
