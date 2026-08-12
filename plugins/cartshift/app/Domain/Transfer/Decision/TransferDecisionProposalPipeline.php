<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Decision;

use CartShift\Domain\Transfer\Audit\TransferAuditReport;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Runs audit -> proposal -> re-audit -> exact-record proposal without writing WordPress state. */
final class TransferDecisionProposalPipeline
{
    /** @var \Closure(TransferSelection, TransferDecisionSet): TransferAuditReport */
    private readonly \Closure $audit;

    /** @var \Closure(TransferSelection, TransferDecisionSet): iterable<RecordEnvelope> */
    private readonly \Closure $records;

    /**
     * @param callable(TransferSelection, TransferDecisionSet): TransferAuditReport $audit
     * @param callable(TransferSelection, TransferDecisionSet): iterable<RecordEnvelope> $records
     */
    public function __construct(
        callable $audit,
        callable $records,
        private readonly TransferDecisionProposalBuilder $builder = new TransferDecisionProposalBuilder(),
    ) {
        $this->audit = $audit(...);
        $this->records = $records(...);
    }

    /** @return array<string,mixed> */
    public function propose(
        TransferSelection $selection,
        TransferDecisionSet $existing,
        string $operator,
        string $decidedAt,
    ): array {
        $existing->assertSourceKey($selection->sourceKey);
        if (trim($operator) === '' || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $decidedAt) !== 1) {
            throw new \InvalidArgumentException('decision_proposal_operator_or_time_invalid');
        }
        $initial = ($this->audit)($selection, $existing);
        $staleAuditFindings = $this->staleAuditFindingKeys($initial, $existing);
        $retained = $staleAuditFindings === []
            ? $existing
            : $existing->withoutAuditFindings($staleAuditFindings);
        $current = $staleAuditFindings === [] ? $initial : ($this->audit)($selection, $retained);
        $auditProposal = $this->builder->auditFindings($current, $operator, $decidedAt);
        $candidate = $this->builder->merge($retained, $auditProposal['rows']);
        $afterAudit = ($this->audit)($selection, $candidate);
        $blockers = $auditProposal['blockers'];
        $recordProposal = ['rows' => [], 'blockers' => []];
        $staleRecordDecisions = [];

        if ($afterAudit->ready && $blockers === []) {
            $records = $this->materialiseRecords(($this->records)($selection, $candidate));
            $recordProposal = $this->builder->records(
                $records,
                $candidate,
                $operator,
                $decidedAt,
            );
            $staleRecordDecisions = $this->staleRecordDecisionIdentities($recordProposal, $candidate);
            if ($staleRecordDecisions !== []) {
                $candidate = $candidate->withoutRecords($staleRecordDecisions);
                $afterRemoval = ($this->audit)($selection, $candidate);
                if (!$afterRemoval->ready) {
                    $recordProposal = ['rows' => [], 'blockers' => []];
                    foreach ($afterRemoval->blockers as $finding) {
                        $blockers[] = [
                            'code' => 'record_renewal_audit_blocked:' . (string) $finding['code'],
                            'identity' => (string) $finding['identity'],
                        ];
                    }
                } else {
                    $recordProposal = $this->builder->records(
                        $records,
                        $candidate,
                        $operator,
                        $decidedAt,
                    );
                }
            }
            $candidate = $this->builder->merge($candidate, $recordProposal['rows']);
            array_push($blockers, ...$recordProposal['blockers']);
        } else {
            foreach ($afterAudit->blockers as $finding) {
                $blockers[] = [
                    'code' => 'audit_remains_blocked:' . (string) $finding['code'],
                    'identity' => (string) $finding['identity'],
                ];
            }
        }

        $final = ($this->audit)($selection, $candidate);
        if (!$final->ready) {
            foreach ($final->blockers as $finding) {
                $blockers[] = [
                    'code' => 'final_audit_blocked:' . (string) $finding['code'],
                    'identity' => (string) $finding['identity'],
                ];
            }
        }
        $blockers = $this->uniqueBlockers($blockers);

        return [
            'status' => $blockers === [] ? 'owner_review_required' : 'blocked',
            'writes' => ['wordpress' => false, 'filesystem' => false],
            'base_decision_fingerprint' => $existing->fingerprint(),
            'source_key' => $selection->sourceKey,
            'selection_fingerprint' => $selection->fingerprint(),
            'initial_audit_fingerprint' => $initial->auditFingerprint,
            'resolved_audit_fingerprint' => $final->auditFingerprint,
            'decision_set_fingerprint' => $candidate->fingerprint(),
            'proposal_counts' => [
                'audit_findings' => count($auditProposal['rows']),
                'records' => count($recordProposal['rows']),
                'retained' => count($existing->rows()) - count($staleAuditFindings) - count($staleRecordDecisions),
                'renewed_audit_findings' => count($staleAuditFindings),
                'renewed_records' => count($staleRecordDecisions),
                'total' => count($candidate->rows()),
            ],
            'blockers' => $blockers,
            'renewed_audit_decisions' => $staleAuditFindings,
            'renewed_record_decisions' => $staleRecordDecisions,
            'proposal_decisions' => [...$auditProposal['rows'], ...$recordProposal['rows']],
            'decision_set' => ['decisions' => $candidate->rows()],
        ];
    }

    /** @param array{rows:list<array<string,mixed>>,blockers:list<array{code:string,identity:string}>} $proposal @return list<string> */
    private function staleRecordDecisionIdentities(array $proposal, TransferDecisionSet $existing): array
    {
        $identities = [];
        foreach ($proposal['blockers'] as $blocker) {
            if (($blocker['code'] ?? null) !== 'existing_record_decision_stale') {
                continue;
            }
            $canonical = $blocker['identity'] ?? null;
            if (!is_string($canonical) || $existing->for(\CartShift\Domain\Transfer\SourceIdentity::fromCanonical($canonical)) === null) {
                throw new \RuntimeException('decision_proposal_stale_record_invalid');
            }
            $identities[$canonical] = true;
        }
        ksort($identities, SORT_STRING);

        return array_keys($identities);
    }

    /** @return list<RecordEnvelope> */
    private function materialiseRecords(iterable $records): array
    {
        $materialised = [];
        foreach ($records as $record) {
            if (!$record instanceof RecordEnvelope) {
                throw new \InvalidArgumentException('Decision proposal record is invalid.');
            }
            $materialised[] = $record;
        }

        return $materialised;
    }

    /** @return list<string> */
    private function staleAuditFindingKeys(
        TransferAuditReport $report,
        TransferDecisionSet $existing,
    ): array {
        $keys = [];
        foreach ($report->blockers as $blocker) {
            if (($blocker['code'] ?? null) !== 'audit_decision_stale') {
                continue;
            }
            $identity = $blocker['identity'] ?? null;
            $finding = $blocker['context']['finding_code'] ?? null;
            if (!is_string($identity) || !is_string($finding)
                || $existing->forAuditFinding($identity, $finding) === null) {
                throw new \RuntimeException('decision_proposal_stale_audit_finding_invalid');
            }
            $keys[$identity . '|' . $finding] = true;
        }
        ksort($keys, SORT_STRING);

        return array_keys($keys);
    }

    /** @param list<array{code:string,identity:string}> $blockers @return list<array{code:string,identity:string}> */
    private function uniqueBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) $unique[$blocker['code'] . '|' . $blocker['identity']] = $blocker;
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }
}
