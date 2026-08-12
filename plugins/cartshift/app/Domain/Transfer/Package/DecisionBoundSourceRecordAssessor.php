<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\AssessmentContext;
use CartShift\Domain\Transfer\AssessmentOutcome;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordAssessment;
use CartShift\Domain\Transfer\RecordAssessor;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

/** Final source-side guard after strict record construction and before any package byte exists. */
final class DecisionBoundSourceRecordAssessor implements RecordAssessor
{
    public function assess(RecordEnvelope $record, AssessmentContext $context): RecordAssessment
    {
        $decisions = $context->values['decisions'] ?? null;
        if (!$decisions instanceof TransferDecisionSet) {
            return $this->blocked('source_assessment_context_missing');
        }

        if (!$this->payloadOwnsIdentity($record) || !$this->dependenciesAreCanonical($record)) {
            return $this->blocked('source_record_identity_mismatch');
        }

        $decision = $decisions->for($record->identity);
        if ($decision !== null
            && (!hash_equals($record->sourceContentDigest, (string) ($decision['source_fingerprint'] ?? ''))
                || ($decision['action'] ?? null) === 'excluded_by_policy')) {
            return $this->blocked('source_record_decision_stale');
        }

        if ($record->identity->kind() !== RecordKind::Customer) {
            return new RecordAssessment(AssessmentOutcome::Ready, 'source_record_ready');
        }

        if ($decision === null || !in_array($decision['action'] ?? null, [
            'reuse_explicit_target_customer',
            'attach_exact_same_site_user',
            'allow_unlinked_downloads',
        ], true)) {
            return $this->blocked('customer_ownership_decision_missing');
        }

        return new RecordAssessment(AssessmentOutcome::Linked, 'customer_ownership_decision_ready');
    }

    private function blocked(string $reason): RecordAssessment
    {
        return new RecordAssessment(AssessmentOutcome::Blocked, $reason);
    }

    private function payloadOwnsIdentity(RecordEnvelope $record): bool
    {
        $field = $record->identity->kind() === RecordKind::TaxonomyTerm
            ? 'term_identity'
            : 'identity';

        return ($record->payload[$field] ?? null) === $record->identity->canonical();
    }

    private function dependenciesAreCanonical(RecordEnvelope $record): bool
    {
        $dependencies = $record->payload['dependencies'] ?? [];
        if (!is_array($dependencies) || !array_is_list($dependencies)) {
            return false;
        }

        foreach ($dependencies as $dependency) {
            if (!is_string($dependency)) {
                return false;
            }
            try {
                $identity = SourceIdentity::fromCanonical($dependency);
            } catch (\Throwable) {
                return false;
            }
            if ($identity->sourceKey !== $record->identity->sourceKey) {
                return false;
            }
        }

        return true;
    }
}
