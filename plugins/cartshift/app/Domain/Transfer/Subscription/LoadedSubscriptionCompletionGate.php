<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\SubscriptionCompletionGate;
use CartShift\Domain\Transfer\Execution\TransferReceipt;

defined('ABSPATH') || exit;

final readonly class LoadedSubscriptionCompletionGate implements SubscriptionCompletionGate
{
    public function __construct(
        private SubscriptionCutoverEvidenceRepository $evidence,
        private SubscriptionTargetGateway $target,
    ) {}

    public function assertReady(PreparedTransfer $prepared, array $receipts): void
    {
        $subscriptions = array_values(array_filter($receipts, static fn (TransferReceipt $r): bool => $r->recordKind === 'subscription'));
        if ($subscriptions === []) return;
        $evidence = $this->evidence->get($prepared->runId);
        if ($evidence->state !== SubscriptionCutoverEvidence::RECONCILED
            || $evidence->runId !== $prepared->runId || $evidence->sourceKey !== $prepared->sourceKey
            || !hash_equals($evidence->packageHash, $prepared->packageHash)
            || !hash_equals($evidence->decisionHash, $prepared->targetState->decisionHash)
            || !hash_equals($evidence->selectionHash, $prepared->targetState->selectionHash)
            || $evidence->executionContext !== $prepared->executionContext) {
            throw new \RuntimeException('subscription_cutover_evidence_not_reconciled');
        }
        $byIdentity = array_column($evidence->entries, null, 'source_identity');
        if (count($byIdentity) !== count($subscriptions)) throw new \RuntimeException('subscription_cutover_receipt_coverage_changed');
        foreach ($subscriptions as $receipt) {
            $entry = $byIdentity[$receipt->sourceIdentity] ?? null;
            $actual = is_array($entry) ? $this->target->snapshot((int) $entry['target_id']) : [];
            $actualFingerprint = \CartShift\Support\CanonicalJson::fingerprint(SubscriptionTargetFingerprint::normalise($actual));
            if (!is_array($entry)
                || (int) $entry['target_id'] !== $receipt->targetIds['primary']
                || !hash_equals((string) $entry['source_fingerprint'], $receipt->sourceFingerprint)
                || !hash_equals((string) $entry['staged_target_fingerprint'], $receipt->afterFingerprint)
                || ($entry['activation_state'] ?? null) !== 'reconciled'
                || !hash_equals((string) ($entry['activated_target_fingerprint'] ?? ''), $actualFingerprint)
                || (($actual['subscription']['status'] ?? null) !== $entry['intended_status'])) {
                throw new \RuntimeException('subscription_cutover_target_drift:' . $receipt->sourceIdentity);
            }
        }
    }
}
