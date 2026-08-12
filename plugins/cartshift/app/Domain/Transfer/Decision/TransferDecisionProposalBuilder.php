<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Decision;

use CartShift\Domain\Transfer\Audit\TransferAuditReport;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Produces fingerprint-bound proposals for owner review; it grants no approval by itself. */
final readonly class TransferDecisionProposalBuilder
{
    /**
     * @return array{rows:list<array<string,mixed>>,blockers:list<array{code:string,identity:string}>}
     */
    public function auditFindings(TransferAuditReport $report, string $operator, string $decidedAt): array
    {
        $rows = [];
        $blockers = [];
        foreach ($report->blockers as $finding) {
            $context = $finding['context'];
            $identity = (string) $finding['identity'];
            $code = (string) $finding['code'];
            $evidence = $context['evidence_fingerprint'] ?? null;
            if (!is_string($evidence) || preg_match('/\A[a-f0-9]{64}\z/D', $evidence) !== 1) {
                $blockers[] = ['code' => 'decision_proposal_evidence_missing', 'identity' => $identity];
                continue;
            }
            $resolution = match ($code) {
                'historical_product_missing' => $this->historicalProduct($context),
                'subscription_schedule_absence' => ['action' => 'approve_mapping', 'schedule_policy' => 'preserve_absence'],
                'subscription_payment_ownership_unassessed' => $this->subscriptionOwnership($context),
                'product_relation_loss_decision_required' => $this->productRelations($context),
                'product_password_protection_unsupported' => [
                    'action' => 'approve_mapping',
                    'password_protection_policy' => 'excluded_by_policy',
                ],
                'order_note_visibility_decision_required' => $this->orderNoteFinding($context),
                default => null,
            };
            if ($resolution === null) {
                $blockers[] = ['code' => 'decision_proposal_requires_manual_resolution:' . $code, 'identity' => $identity];
                continue;
            }
            $rows[] = array_merge([
                'identity' => $identity,
                'scope' => 'audit_finding',
                'finding_code' => $code,
            ], $resolution, [
                'source_fingerprint' => $evidence,
                'operator' => $operator,
                'reason' => 'Proposed from exact read-only source evidence; owner review required.',
                'decided_at' => $decidedAt,
            ]);
        }
        return ['rows' => $rows, 'blockers' => $blockers];
    }

    /**
     * @param iterable<RecordEnvelope> $records
     * @return array{rows:list<array<string,mixed>>,blockers:list<array{code:string,identity:string}>}
     */
    public function records(
        iterable $records,
        TransferDecisionSet $existing,
        string $operator,
        string $decidedAt,
    ): array {
        $rows = [];
        $blockers = [];
        foreach ($records as $record) {
            if (!$record instanceof RecordEnvelope) {
                throw new \InvalidArgumentException('Decision proposal record is invalid.');
            }
            $current = $existing->for($record->identity);
            if ($current !== null) {
                if (!hash_equals($record->sourceContentDigest, (string) ($current['source_fingerprint'] ?? ''))) {
                    $blockers[] = ['code' => 'existing_record_decision_stale', 'identity' => $record->identity->canonical()];
                }
                continue;
            }
            $base = [
                'identity' => $record->identity->canonical(),
                'scope' => 'record',
                'source_fingerprint' => $record->sourceContentDigest,
                'operator' => $operator,
                'reason' => 'Proposed from the exact materialised source record; owner review required.',
                'decided_at' => $decidedAt,
            ];
            $resolution = match ($record->identity->kind()) {
                RecordKind::Product => $this->productRecord($record),
                RecordKind::Order => $this->orderRecord($record),
                RecordKind::Subscription => $this->subscriptionRecord($record),
                RecordKind::Customer => null,
                default => [],
            };
            if ($record->identity->kind() === RecordKind::Customer) {
                $blockers[] = ['code' => 'customer_ownership_decision_requires_owner', 'identity' => $record->identity->canonical()];
                continue;
            }
            if ($resolution === null) {
                $blockers[] = ['code' => 'record_decision_requires_manual_resolution', 'identity' => $record->identity->canonical()];
                continue;
            }
            if ($resolution !== []) {
                $rows[] = array_merge($base, $resolution);
            }
        }
        return ['rows' => $rows, 'blockers' => $blockers];
    }

    /** @param list<array<string,mixed>> ...$proposals */
    public function merge(TransferDecisionSet $existing, array ...$proposals): TransferDecisionSet
    {
        $rows = $existing->rows();
        $keys = [];
        foreach ($rows as $row) $keys[$this->key($row)] = true;
        foreach ($proposals as $proposal) {
            foreach ($proposal as $row) {
                $key = $this->key($row);
                if (isset($keys[$key])) {
                    throw new \RuntimeException('decision_proposal_would_overwrite_existing_decision:' . $key);
                }
                $keys[$key] = true;
                $rows[] = $row;
            }
        }
        return TransferDecisionSet::fromArray($rows);
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    private function historicalProduct(array $context): ?array
    {
        return ($context['placeholder_ready'] ?? false) === true
            && is_string($context['placeholder_identity'] ?? null)
            && is_string($context['placeholder_fingerprint'] ?? null)
            ? [
                'action' => 'approve_mapping',
                'placeholder_identity' => $context['placeholder_identity'],
                'placeholder_fingerprint' => $context['placeholder_fingerprint'],
            ]
            : null;
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    private function subscriptionOwnership(array $context): ?array
    {
        return ($context['target_collection_method'] ?? null) === 'manual'
            && ($context['next_action_owner'] ?? null) === 'target_manual'
            && is_bool($context['source_auto_renewal_release_required'] ?? null)
            && is_string($context['source_gateway'] ?? null)
            ? [
                'action' => 'approve_mapping',
                'target_collection_method' => 'manual',
                'next_action_owner' => 'target_manual',
                'source_auto_renewal_release_required' => $context['source_auto_renewal_release_required'],
                'source_gateway' => $context['source_gateway'],
            ]
            : null;
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    private function productRelations(array $context): ?array
    {
        return ($context['relation_policy'] ?? null) === 'preserve_provenance'
            && is_int($context['upsell_count'] ?? null)
            && is_int($context['cross_sell_count'] ?? null)
            ? [
                'action' => 'approve_mapping',
                'relation_policy' => 'preserve_provenance',
                'upsell_count' => $context['upsell_count'],
                'cross_sell_count' => $context['cross_sell_count'],
            ]
            : null;
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    private function orderNoteFinding(array $context): ?array
    {
        return ($context['note_policy'] ?? null) === 'preserve_history_select_canonical'
            && is_int($context['note_count'] ?? null)
            && is_int($context['customer_visible_note_count'] ?? null)
            ? [
                'action' => 'approve_mapping',
                'note_policy' => 'preserve_history_select_canonical',
                'note_count' => $context['note_count'],
                'customer_visible_note_count' => $context['customer_visible_note_count'],
            ]
            : null;
    }

    /** @return array<string,mixed> */
    private function productRecord(RecordEnvelope $record): array
    {
        if (($record->payload['status'] ?? null) === 'trash') {
            return ['action' => 'excluded_by_policy'];
        }

        $relations = [
            'upsell_products' => array_values((array) ($record->payload['upsell_products'] ?? [])),
            'cross_sell_products' => array_values((array) ($record->payload['cross_sell_products'] ?? [])),
        ];
        $resolution = ($record->payload['status'] ?? null) === 'publish'
            ? ['action' => 'activate_catalogue', 'target_status' => 'publish']
            : ['action' => 'leave_catalogue_draft', 'target_status' => 'draft'];
        if ($relations['upsell_products'] !== [] || $relations['cross_sell_products'] !== []) {
            $resolution['relation_policy'] = 'preserve_provenance';
            $resolution['relation_fingerprint'] = CanonicalJson::fingerprint($relations);
        }
        if (($record->payload['password_protected'] ?? false) === true) {
            $resolution['password_protection_policy'] = 'excluded_by_policy';
        }
        return $resolution;
    }

    /** @return array<string,mixed>|null */
    private function orderRecord(RecordEnvelope $record): ?array
    {
        $notes = $record->payload['notes'] ?? [];
        if (!is_array($notes) || !array_is_list($notes)) return null;
        $visible = array_values(array_filter($notes, static fn ($note): bool => is_array($note) && ($note['customer_visible'] ?? false) === true));
        if (count($visible) > 1) return null;
        $canonical = $visible === [] ? null : ($visible[0]['identity'] ?? null);
        if ($canonical !== null && !is_string($canonical)) return null;
        $visibility = [];
        foreach ($notes as $note) {
            if (!is_array($note)
                || !is_string($note['identity'] ?? null)
                || !is_bool($note['customer_visible'] ?? null)
                || !is_string($note['public_identifier'] ?? null)) return null;
            $visibility[] = [
                'identity' => $note['identity'],
                'customer_visible' => $note['customer_visible'],
                'public_identifier' => $note['public_identifier'],
            ];
        }
        $resolution = ['action' => 'approve_mapping'];
        if ($notes !== []) {
            $resolution['canonical_customer_note'] = $canonical;
            $resolution['note_decision_fingerprint'] = CanonicalJson::fingerprint([
                'source_record_digest' => $record->sourceContentDigest,
                'canonical_customer_note' => $canonical,
                'note_visibility' => $visibility,
            ]);
        }
        return $resolution;
    }

    /** @return array<string,mixed>|null */
    private function subscriptionRecord(RecordEnvelope $record): ?array
    {
        $payment = $record->payload['payment_ownership'] ?? null;
        $status = strtolower((string) ($record->payload['status'] ?? ''));
        if (!is_array($payment)
            || !is_string($payment['payment_reference_digest'] ?? null)
            || !is_string($payment['source_gateway'] ?? null)
            || !is_bool($payment['source_requires_manual_renewal'] ?? null)) return null;
        $terminal = in_array($status, ['cancelled', 'canceled', 'expired', 'switched'], true);
        return [
            'action' => 'approve_subscription_manual',
            'target_collection_method' => 'manual',
            'next_action_owner' => 'target_manual',
            'payment_reference_digest' => $payment['payment_reference_digest'],
            'source_gateway' => $payment['source_gateway'],
            'source_auto_renewal_release_required' => !$terminal && !$payment['source_requires_manual_renewal'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function key(array $row): string
    {
        $identity = (string) ($row['identity'] ?? '');
        return match ($row['scope'] ?? 'record') {
            'audit_finding' => $identity . '|audit|' . (string) ($row['finding_code'] ?? ''),
            'target_finding' => $identity . '|target|' . (string) ($row['finding_code'] ?? ''),
            default => $identity . '|record',
        };
    }
}
