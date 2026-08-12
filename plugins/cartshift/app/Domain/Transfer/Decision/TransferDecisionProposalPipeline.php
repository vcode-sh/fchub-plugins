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
        $auditProposal = $this->builder->auditFindings($initial, $operator, $decidedAt);
        $candidate = $this->builder->merge($existing, $auditProposal['rows']);
        $afterAudit = ($this->audit)($selection, $candidate);
        $blockers = $auditProposal['blockers'];
        $recordProposal = ['rows' => [], 'blockers' => []];

        if ($afterAudit->ready && $blockers === []) {
            $recordProposal = $this->builder->records(
                ($this->records)($selection, $candidate),
                $candidate,
                $operator,
                $decidedAt,
            );
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
            'source_key' => $selection->sourceKey,
            'selection_fingerprint' => $selection->fingerprint(),
            'initial_audit_fingerprint' => $initial->auditFingerprint,
            'resolved_audit_fingerprint' => $final->auditFingerprint,
            'decision_set_fingerprint' => $candidate->fingerprint(),
            'proposal_counts' => [
                'audit_findings' => count($auditProposal['rows']),
                'records' => count($recordProposal['rows']),
                'retained' => count($existing->rows()),
                'total' => count($candidate->rows()),
            ],
            'blockers' => $blockers,
            'decision_set' => ['decisions' => $candidate->rows()],
        ];
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
