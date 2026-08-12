<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Support\Enums\FcSubscriptionStatus;

defined('ABSPATH') || exit;

final readonly class SubscriptionCutoverPreparer
{
    /** @param list<RecordEnvelope> $records @param list<TransferReceipt> $receipts */
    public function prepare(
        PreparedTransfer $prepared,
        array $records,
        TransferDecisionSet $decisions,
        array $receipts,
        string $sourceInstanceFingerprint,
        string $sourceRuntimeFingerprint,
        string $nowUtc,
    ): ?SubscriptionCutoverEvidence {
        $source = [];
        foreach ($records as $record) if ($record->identity->entityType === 'subscription') $source[$record->identity->canonical()] = $record;
        $staged = array_values(array_filter($receipts, static fn (TransferReceipt $r): bool => $r->recordKind === 'subscription'));
        if ($staged === [] && $source === []) return null;
        if (count($source) !== count($staged)) throw new \RuntimeException('subscription_cutover_source_receipt_coverage_changed');
        $entries = [];
        foreach ($staged as $receipt) {
            $record = $source[$receipt->sourceIdentity] ?? null;
            if (!$record instanceof RecordEnvelope || !hash_equals($record->privateContentDigest, $receipt->sourceFingerprint)) {
                throw new \RuntimeException('subscription_cutover_source_receipt_changed');
            }
            $decision = $decisions->for($record->identity);
            $payment = $record->payload['payment_ownership'] ?? null;
            $terminal = in_array(strtolower((string) ($record->payload['status'] ?? '')), ['cancelled', 'canceled', 'expired', 'switched'], true);
            $releaseRequired = !$terminal && is_array($payment) && ($payment['source_requires_manual_renewal'] ?? null) === false;
            if (($decision['action'] ?? null) !== 'approve_subscription_manual'
                || !hash_equals($receipt->sourceFingerprint, (string) ($decision['source_fingerprint'] ?? ''))
                || ($decision['source_auto_renewal_release_required'] ?? null) !== $releaseRequired) {
                throw new \RuntimeException('subscription_cutover_decision_changed');
            }
            $entries[] = [
                'source_identity' => $receipt->sourceIdentity,
                'source_fingerprint' => $receipt->sourceFingerprint,
                'target_id' => $receipt->targetIds['primary'],
                'staged_target_fingerprint' => $receipt->afterFingerprint,
                'source_release_required' => $releaseRequired,
                'intended_status' => FcSubscriptionStatus::fromWooCommerce((string) $record->payload['status'])->value,
                'release_state' => $releaseRequired ? 'pending' : 'not_required',
                'activation_state' => $terminal ? 'activated' : 'paused',
            ];
        }
        usort($entries, static fn (array $a, array $b): int => strnatcmp($a['source_identity'], $b['source_identity']));
        return new SubscriptionCutoverEvidence(
            $prepared->runId, $prepared->sourceKey, $prepared->packageHash,
            $prepared->targetState->decisionHash, $prepared->targetState->selectionHash,
            $sourceInstanceFingerprint, $sourceRuntimeFingerprint,
            $prepared->executionContext, SubscriptionCutoverEvidence::PREPARED, $entries, $nowUtc,
        );
    }
}
