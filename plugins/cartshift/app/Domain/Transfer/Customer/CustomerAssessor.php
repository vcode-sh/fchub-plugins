<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

defined('ABSPATH') || exit;

final class CustomerAssessor
{
    private readonly \Closure $targetCandidatesByEmail;
    private readonly ?\Closure $mappingLookup;
    private readonly ?\Closure $sameSiteUserDecision;

    /**
     * @param callable(CustomerRecord): list<array<string, mixed>> $targetCandidatesByEmail
     * @param null|callable(CustomerRecord): ?array<string, mixed> $mappingLookup
     * @param array<string, array<string, mixed>> $decisions
     * @param null|callable(CustomerRecord): ?int $sameSiteUserDecision
     */
    public function __construct(callable $targetCandidatesByEmail, ?callable $mappingLookup = null, private readonly array $decisions = [], ?callable $sameSiteUserDecision = null)
    {
        $this->targetCandidatesByEmail = $targetCandidatesByEmail(...);
        $this->mappingLookup = $mappingLookup === null ? null : $mappingLookup(...);
        $this->sameSiteUserDecision = $sameSiteUserDecision === null ? null : $sameSiteUserDecision(...);
    }

    public function assess(CustomerRecord $record, int $downloadableOrderCount = 0, bool $allowUnlinkedDownloads = false): CustomerAssessment
    {
        if ($downloadableOrderCount < 0) return new CustomerAssessment('blocked_invalid_customer');
        $decision = $this->decisions[$record->identity->canonical()] ?? null;
        if ($this->mappingLookup !== null) {
            $map = ($this->mappingLookup)($record);
            if (is_array($map) && is_int($map['target_id'] ?? null) && $map['target_id'] > 0 && $this->hash($map['target_fingerprint'] ?? null)) {
                $evidence = ['target_id' => $map['target_id']];
                if (is_array($decision)
                    && ($decision['action'] ?? null) === 'attach_exact_same_site_user'
                    && hash_equals($record->envelope()->privateContentDigest, (string) ($decision['source_fingerprint'] ?? ''))
                    && $record->sourceUserId !== null
                    && ($decision['user_id'] ?? null) === $record->sourceUserId) {
                    $evidence['user_id'] = $record->sourceUserId;
                }
                return new CustomerAssessment('reuse_exact_customer_map', $evidence);
            }
        }
        if (is_array($decision)) {
            $common = hash_equals($record->envelope()->privateContentDigest, (string) ($decision['source_fingerprint'] ?? ''))
                && is_string($decision['operator'] ?? null) && $decision['operator'] !== ''
                && is_string($decision['reason'] ?? null) && $decision['reason'] !== ''
                && is_string($decision['decided_at'] ?? null) && $decision['decided_at'] !== '';
            $valid = $common && ($decision['action'] ?? null) === 'reuse_explicit_target_customer'
                && is_int($decision['target_id'] ?? null) && $decision['target_id'] > 0
                && $this->hash($decision['target_fingerprint'] ?? null);
            if ($valid) {
                return new CustomerAssessment('reuse_explicit_target_customer', [
                    'target_id' => $decision['target_id'],
                    'target_fingerprint' => $decision['target_fingerprint'],
                ]);
            }
            if ($common
                && ($decision['action'] ?? null) === 'attach_exact_same_site_user'
                && $record->sourceUserId !== null
                && ($decision['user_id'] ?? null) === $record->sourceUserId) {
                return new CustomerAssessment('attach_exact_same_site_user', ['user_id' => $record->sourceUserId]);
            }
            if ($common
                && ($decision['action'] ?? null) === 'allow_unlinked_downloads'
                && is_int($decision['downloadable_order_count'] ?? null)
                && $decision['downloadable_order_count'] === $downloadableOrderCount) {
                return new CustomerAssessment('create_target_customer_unlinked', [
                    'downloadable_order_count' => $downloadableOrderCount,
                    'allowed_loss_decision' => true,
                ]);
            }
            return new CustomerAssessment('blocked_invalid_customer');
        }
        if ($this->sameSiteUserDecision !== null && $record->sourceUserId !== null) {
            $userId = ($this->sameSiteUserDecision)($record);
            if ($userId === $record->sourceUserId) return new CustomerAssessment('attach_exact_same_site_user', ['user_id' => $userId]);
            if ($userId !== null) return new CustomerAssessment('blocked_invalid_customer');
        }
        $candidates = ($this->targetCandidatesByEmail)($record);
        if (!is_array($candidates) || !array_is_list($candidates)) return new CustomerAssessment('blocked_invalid_customer');
        if (count($candidates) > 1) return new CustomerAssessment('blocked_ambiguous_identity', ['candidate_count' => count($candidates)]);
        if (count($candidates) === 1) return new CustomerAssessment('requires_mapping_decision', ['candidate_count' => 1]);
        if ($downloadableOrderCount > 0 && !$allowUnlinkedDownloads) return new CustomerAssessment('requires_mapping_decision', ['downloadable_order_count' => $downloadableOrderCount]);
        return new CustomerAssessment('create_target_customer_unlinked', ['downloadable_order_count' => $downloadableOrderCount]);
    }

    private function hash(mixed $value): bool { return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1; }
}
