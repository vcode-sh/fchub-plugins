<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Read-only composition root. No migration state, maps, logs or preflight code. */
final readonly class TransferAuditor
{
    public function __construct(
        private TransferRuntimeInspector $runtime,
        private SourceInventoryInspector $inventory,
        private ?SourceRecordContractInspector $recordContracts = null,
    ) {}

    public function audit(TransferSelection $selection, ?TransferDecisionSet $decisions = null): TransferAuditReport
    {
        $decisions ??= TransferDecisionSet::empty();
        $decisions->assertSourceKey($selection->sourceKey);
        $runtime = $this->runtime->inspect(TransferRuntimeProbe::ROLE_SOURCE);

        if (!$runtime->isReady()) {
            return TransferAuditReport::create(
                $selection->sourceKey,
                $selection->fingerprint(),
                $runtime->fingerprint,
                false,
                [],
                [],
                [[
                    'code' => 'runtime_contract_mismatch',
                    'identity' => $selection->sourceKey . ':runtime:source',
                    'context' => ['error_count' => count($runtime->errors)],
                ]],
                $decisions->fingerprint(),
            );
        }

        $inventory = $this->inventory->inspect($selection);
        $blockers = $inventory->blockers;
        $counts = $inventory->counts;
        if ($this->recordContracts !== null) {
            $contractReport = $this->recordContracts->inspect($selection);
            foreach ($contractReport->counts as $kind => $contractCounts) {
                $counts[$kind . '_contract_considered'] = $contractCounts['considered'];
                $counts[$kind . '_contract_ready'] = $contractCounts['ready'];
                $counts[$kind . '_contract_blocked'] = $contractCounts['blocked'];
                if ($kind === 'customer') {
                    $counts['customer_census_ids'] = $contractCounts['considered'];
                    $counts['customers_considered'] = $contractCounts['considered'];
                    $counts['customers_exported'] = $contractCounts['considered'];
                    $counts['customers_excluded'] = 0;
                    $counts['customers_blocked'] = 0;
                }
            }
            [$contractFindings, $counts] = $this->mergeRecordContractFindings(
                $contractReport->findings,
                $blockers,
                $counts,
            );
            array_push($blockers, ...$contractFindings);
        }

        if (
            $inventory->sourceKey !== $selection->sourceKey
            || $inventory->selectionFingerprint !== $selection->fingerprint()
        ) {
            $blockers[] = [
                'code' => 'selection_drift',
                'identity' => $selection->sourceKey . ':selection',
                'context' => [],
            ];
        }

        if ($inventory->runtimeFingerprint !== $runtime->fingerprint) {
            $blockers[] = [
                'code' => 'runtime_contract_mismatch',
                'identity' => $selection->sourceKey . ':runtime:source',
                'context' => [],
            ];
        }

        $allFindings = $blockers;
        [$blockers, $decisionsReady, $resolvedFindings] = $this->applyAuditFindingDecisions(
            $blockers,
            $decisions,
            $selection,
            $runtime->fingerprint,
        );
        $counts = $this->reconcileDecisionCounts(
            $counts,
            $allFindings,
            $resolvedFindings,
        );

        return TransferAuditReport::create(
            $selection->sourceKey,
            $selection->fingerprint(),
            $runtime->fingerprint,
            $runtime->isReady()
                && $decisionsReady
                && ($inventory->counts['product_duplicates'] ?? 0) === 0
                && ($inventory->counts['products_unaccounted'] ?? 0) === 0,
            $counts,
            $inventory->capabilities,
            $blockers,
            $decisions->fingerprint(),
        );
    }

    /**
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $findings
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $inventoryFindings
     * @param array<string, int> $counts
     * @return array{0: list<array{code: string, identity: string, context: array<string, scalar|null>}>, 1: array<string, int>}
     */
    private function mergeRecordContractFindings(array $findings, array $inventoryFindings, array $counts): array
    {
        $existingKeys = [];
        $blockedRoots = [];
        foreach ($inventoryFindings as $finding) {
            $existingKeys[$finding['identity'] . '|' . $finding['code']] = true;
            $root = $this->recordRoot($finding);
            if ($root !== null && $this->inventoryFindingBlocksRecord($finding['code'])) {
                $blockedRoots[$root] = true;
            }
        }

        $merged = [];
        foreach ($findings as $finding) {
            $finding['context']['record_contract'] = true;
            $key = $finding['identity'] . '|' . $finding['code'];
            if (isset($existingKeys[$key])) {
                continue;
            }
            $existingKeys[$key] = true;
            $merged[] = $finding;
            $root = $this->recordRoot($finding);
            if ($root === null || isset($blockedRoots[$root])) {
                continue;
            }
            $blockedRoots[$root] = true;
            [$kind] = explode(':', $root, 2);
            $exported = $kind . 's_exported';
            $blocked = $kind . 's_blocked';
            if (($counts[$exported] ?? 0) > 0) {
                --$counts[$exported];
                ++$counts[$blocked];
            }
        }

        usort($merged, static fn (array $left, array $right): int => [$left['code'], $left['identity']]
            <=> [$right['code'], $right['identity']]);

        return [$merged, $counts];
    }

    /** @param array{identity: string, context: array<string, scalar|null>} $finding */
    private function recordRoot(array $finding): ?string
    {
        try {
            $identity = \CartShift\Domain\Transfer\SourceIdentity::fromCanonical($finding['identity']);
        } catch (\Throwable) {
            return null;
        }
        if (!in_array($identity->entityType, ['product', 'customer', 'order', 'subscription'], true)) {
            return null;
        }
        $sourceId = explode(':', $identity->sourceId, 2)[0];
        if (preg_match('/\A[1-9][0-9]*\z/D', $sourceId) !== 1) {
            return null;
        }
        return $identity->entityType . ':' . $sourceId;
    }

    private function inventoryFindingBlocksRecord(string $code): bool
    {
        return in_array($code, [
            'unsupported_product_type',
            'target_schema_unrepresentable',
            'product_hydration_failed',
            'unsupported_product_dependency',
            'historical_product_missing',
            'order_hydration_failed',
            'subscription_hydration_failed',
            'subscription_schedule_absence',
            'subscription_payment_ownership_unassessed',
            'product_relation_loss_decision_required',
            'product_password_protection_unsupported',
            'order_note_visibility_decision_required',
        ], true);
    }

    /**
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $blockers
     * @return array{0: list<array{code: string, identity: string, context: array<string, scalar|null>}>, 1: bool, 2: array<string, array<string, mixed>>}
     */
    private function applyAuditFindingDecisions(
        array $blockers,
        TransferDecisionSet $decisions,
        TransferSelection $selection,
        string $runtimeFingerprint,
    ): array {
        $remaining = [];
        $matched = [];
        $resolved = [];

        foreach ($blockers as $blocker) {
            $fingerprint = CanonicalJson::fingerprint([
                'source_key' => $selection->sourceKey,
                'selection_fingerprint' => $selection->fingerprint(),
                'runtime_fingerprint' => $runtimeFingerprint,
                'code' => $blocker['code'],
                'identity' => $blocker['identity'],
                'context' => $blocker['context'],
            ]);
            $withEvidence = $blocker;
            $withEvidence['context']['evidence_fingerprint'] = $fingerprint;
            $decision = $decisions->forAuditFinding($blocker['identity'], $blocker['code']);

            if ($decision === null) {
                $remaining[] = $withEvidence;
                continue;
            }

            $key = $blocker['identity'] . '|' . $blocker['code'];
            $matched[$key] = true;
            if (!hash_equals($fingerprint, (string) ($decision['source_fingerprint'] ?? ''))) {
                $remaining[] = [
                    'code' => 'audit_decision_stale',
                    'identity' => $blocker['identity'],
                    'context' => [
                        'evidence_fingerprint' => $fingerprint,
                        'finding_code' => $blocker['code'],
                    ],
                ];
            } elseif (!$this->resolutionMatchesFinding($decision, $blocker)) {
                $remaining[] = [
                    'code' => 'audit_decision_invalid_resolution',
                    'identity' => $blocker['identity'],
                    'context' => [
                        'evidence_fingerprint' => $fingerprint,
                        'finding_code' => $blocker['code'],
                    ],
                ];
            } else {
                $resolved[$key] = $decision;
            }
        }

        foreach ($decisions->auditFindings() as $key => $decision) {
            if (isset($matched[$key])) {
                continue;
            }
            $remaining[] = [
                'code' => 'audit_decision_unknown_finding',
                'identity' => (string) $decision['identity'],
                'context' => ['finding_code' => (string) $decision['finding_code']],
            ];
        }

        return [$remaining, $remaining === [], $resolved];
    }

    /** @param array<string, mixed> $decision @param array{code: string, identity: string, context: array<string, scalar|null>} $finding */
    private function resolutionMatchesFinding(array $decision, array $finding): bool
    {
        if (($decision['action'] ?? null) === 'excluded_by_policy') {
            return true;
        }

        if ($finding['code'] === 'historical_product_missing') {
            return ($decision['placeholder_identity'] ?? null) === ($finding['context']['placeholder_identity'] ?? null)
                && ($decision['placeholder_fingerprint'] ?? null) === ($finding['context']['placeholder_fingerprint'] ?? null)
                && ($finding['context']['placeholder_ready'] ?? false) === true;
        }

        if ($finding['code'] === 'subscription_schedule_absence') {
            return ($decision['schedule_policy'] ?? null) === ($finding['context']['policy'] ?? null);
        }

        if ($finding['code'] === 'subscription_payment_ownership_unassessed') {
            return ($decision['target_collection_method'] ?? null) === ($finding['context']['target_collection_method'] ?? null)
                && ($decision['next_action_owner'] ?? null) === ($finding['context']['next_action_owner'] ?? null)
                && ($decision['source_auto_renewal_release_required'] ?? null) === ($finding['context']['source_auto_renewal_release_required'] ?? null)
                && ($decision['source_gateway'] ?? null) === ($finding['context']['source_gateway'] ?? null);
        }

        if ($finding['code'] === 'target_schema_unrepresentable') {
            return ($decision['field'] ?? null) === ($finding['context']['field'] ?? null);
        }

        if ($finding['code'] === 'product_relation_loss_decision_required') {
            return ($decision['relation_policy'] ?? null) === ($finding['context']['relation_policy'] ?? null)
                && ($decision['upsell_count'] ?? null) === ($finding['context']['upsell_count'] ?? null)
                && ($decision['cross_sell_count'] ?? null) === ($finding['context']['cross_sell_count'] ?? null);
        }
        if ($finding['code'] === 'product_password_protection_unsupported') {
            return ($decision['password_protection_policy'] ?? null) === ($finding['context']['password_protection_policy'] ?? null);
        }
        if ($finding['code'] === 'order_note_visibility_decision_required') {
            return ($decision['note_policy'] ?? null) === ($finding['context']['note_policy'] ?? null)
                && ($decision['note_count'] ?? null) === ($finding['context']['note_count'] ?? null)
                && ($decision['customer_visible_note_count'] ?? null) === ($finding['context']['customer_visible_note_count'] ?? null);
        }

        return true;
    }

    /**
     * @param array<string, int> $counts
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $findings
     * @param array<string, array<string, mixed>> $resolved
     * @return array<string, int>
     */
    private function reconcileDecisionCounts(array $counts, array $findings, array $resolved): array
    {
        $groups = [];
        $recordCodes = [
            'product' => ['unsupported_product_type', 'target_schema_unrepresentable', 'product_hydration_failed', 'product_relation_loss_decision_required', 'product_password_protection_unsupported'],
            'order' => ['unsupported_product_dependency', 'historical_product_missing', 'order_hydration_failed', 'order_note_visibility_decision_required'],
            'subscription' => ['subscription_hydration_failed', 'subscription_schedule_absence', 'subscription_payment_ownership_unassessed'],
            'customer' => ['customer_hydration_failed'],
        ];

        foreach ($findings as $finding) {
            try {
                $identity = \CartShift\Domain\Transfer\SourceIdentity::fromCanonical($finding['identity']);
            } catch (\Throwable) {
                continue;
            }
            if (!isset($recordCodes[$identity->entityType])
                || (!in_array($finding['code'], $recordCodes[$identity->entityType], true)
                    && ($finding['context']['record_contract'] ?? false) !== true)) {
                continue;
            }
            $rootId = explode(':', $identity->sourceId, 2)[0];
            $group = $identity->entityType . ':' . $rootId;
            $groups[$group]['kind'] = $identity->entityType;
            $groups[$group]['keys'][] = $finding['identity'] . '|' . $finding['code'];
        }

        foreach ($groups as $group) {
            $decisions = [];
            foreach (array_values(array_unique($group['keys'])) as $key) {
                if (!isset($resolved[$key])) {
                    continue 2;
                }
                $decisions[] = $resolved[$key];
            }
            $kind = $group['kind'];
            $blockedKey = $kind . 's_blocked';
            $exportedKey = $kind . 's_exported';
            $excludedKey = $kind . 's_excluded';
            if (($counts[$blockedKey] ?? 0) <= 0) {
                continue;
            }
            --$counts[$blockedKey];
            $excluded = array_filter(
                $decisions,
                static fn (array $decision): bool => ($decision['action'] ?? null) === 'excluded_by_policy',
            ) !== [];
            ++$counts[$excluded ? $excludedKey : $exportedKey];
        }

        return $counts;
    }
}
