<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Mark-before-act activation followed by an independent full-cohort reconciliation. */
final readonly class SubscriptionTargetCutover
{
    public function __construct(
        private SubscriptionCutoverEvidenceRepository $repository,
        private SubscriptionCutoverTargetGateway $target,
    ) {}

    public function activateAndReconcile(string $runId, string $nowUtc): SubscriptionCutoverEvidence
    {
        $evidence = $this->repository->get($runId);
        if (!in_array($evidence->state, [SubscriptionCutoverEvidence::SOURCE_RELEASED, SubscriptionCutoverEvidence::TARGET_ACTIVATED], true)) {
            throw new \RuntimeException('subscription_target_cutover_state_invalid');
        }
        foreach ($evidence->entries as $index => $entry) {
            if ($entry['activation_state'] === 'reconciled') continue;
            $snapshot = $this->target->snapshot((int) $entry['target_id']);
            $status = (string) ($snapshot['subscription']['status'] ?? '');
            if ($entry['activation_state'] === 'paused') {
                if ($status !== 'paused' && $status !== $entry['intended_status']) {
                    throw new \RuntimeException('subscription_target_drift_before_activation:' . $entry['source_identity']);
                }
                $marked = $evidence->entries;
                $marked[$index] = ['activation_state' => 'marked'] + $entry;
                $evidence = $this->replace($evidence, $marked, $nowUtc);
            } elseif ($entry['activation_state'] === 'activated' && $status === $entry['intended_status']) {
                // Terminal records were already written in their intended state.
            } elseif ($entry['activation_state'] !== 'marked') {
                throw new \RuntimeException('subscription_target_activation_state_invalid');
            }
            if ($status !== $entry['intended_status']) {
                $this->target->activateStatus((int) $entry['target_id'], 'paused', (string) $entry['intended_status']);
            }
            $actual = $this->target->snapshot((int) $entry['target_id']);
            if (($actual['subscription']['status'] ?? null) !== $entry['intended_status']) {
                throw new \RuntimeException('subscription_target_activation_unverified:' . $entry['source_identity']);
            }
            $updated = $evidence->entries;
            $updated[$index] = ['activation_state' => 'activated',
                'activated_target_fingerprint' => CanonicalJson::fingerprint(SubscriptionTargetFingerprint::normalise($actual))] + $evidence->entries[$index];
            $evidence = $this->replace($evidence, $updated, $nowUtc);
        }
        $activated = new SubscriptionCutoverEvidence(
            $evidence->runId, $evidence->sourceKey, $evidence->packageHash, $evidence->decisionHash,
            $evidence->selectionHash, $evidence->sourceInstanceFingerprint, $evidence->sourceRuntimeFingerprint,
            $evidence->executionContext, SubscriptionCutoverEvidence::TARGET_ACTIVATED, $evidence->entries, $nowUtc,
        );
        if ($evidence->state !== SubscriptionCutoverEvidence::TARGET_ACTIVATED) {
            $this->repository->replace($evidence, $activated);
            $evidence = $activated;
        }
        $entries = $evidence->entries;
        foreach ($entries as $index => $entry) {
            $actual = $this->target->snapshot((int) $entry['target_id']);
            $fingerprint = CanonicalJson::fingerprint(SubscriptionTargetFingerprint::normalise($actual));
            if (($actual['subscription']['status'] ?? null) !== $entry['intended_status']
                || !hash_equals((string) ($entry['activated_target_fingerprint'] ?? ''), $fingerprint)) {
                throw new \RuntimeException('subscription_target_reconciliation_failed:' . $entry['source_identity']);
            }
            $entries[$index] = ['activation_state' => 'reconciled'] + $entry;
        }
        $reconciled = new SubscriptionCutoverEvidence(
            $evidence->runId, $evidence->sourceKey, $evidence->packageHash, $evidence->decisionHash,
            $evidence->selectionHash, $evidence->sourceInstanceFingerprint, $evidence->sourceRuntimeFingerprint,
            $evidence->executionContext, SubscriptionCutoverEvidence::RECONCILED, $entries, $nowUtc,
        );
        $this->repository->replace($evidence, $reconciled);
        return $reconciled;
    }

    /** @param list<array<string,mixed>> $entries */
    private function replace(SubscriptionCutoverEvidence $before, array $entries, string $nowUtc): SubscriptionCutoverEvidence
    {
        $after = new SubscriptionCutoverEvidence(
            $before->runId, $before->sourceKey, $before->packageHash, $before->decisionHash,
            $before->selectionHash, $before->sourceInstanceFingerprint, $before->sourceRuntimeFingerprint,
            $before->executionContext, $before->state, $entries, $nowUtc,
        );
        $this->repository->replace($before, $after);
        return $after;
    }
}
