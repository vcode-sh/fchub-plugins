<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

/** Durable mark-before-act source ownership release. Workers must already be paused. */
final readonly class SubscriptionSourceCutover
{
    public function __construct(
        private SubscriptionCutoverEvidenceRepository $repository,
        private SubscriptionSourceCutoverGateway $source,
    ) {}

    public function release(string $runId, bool $renewalsPaused, string $nowUtc): SubscriptionCutoverEvidence
    {
        if (!$renewalsPaused) throw new \RuntimeException('source_renewal_maintenance_unconfirmed');
        $evidence = $this->repository->get($runId);
        if (!in_array($evidence->state, [SubscriptionCutoverEvidence::PREPARED, SubscriptionCutoverEvidence::SOURCE_RELEASED], true)) {
            throw new \RuntimeException('subscription_source_cutover_state_invalid');
        }
        foreach ($evidence->entries as $index => $entry) {
            $identity = SourceIdentity::fromCanonical((string) $entry['source_identity']);
            if ($entry['release_state'] === 'not_required') {
                $inspection = $this->source->inspect($identity);
                if (!hash_equals((string) $entry['source_fingerprint'], (string) $inspection['source_fingerprint'])) {
                    throw new \RuntimeException('subscription_source_drift_without_release:' . $entry['source_identity']);
                }
                continue;
            }
            if ($entry['release_state'] === 'released') {
                $inspection = $this->source->inspect($identity);
                if (($inspection['requires_manual_renewal'] ?? false) !== true
                    || !hash_equals((string) ($entry['post_source_fingerprint'] ?? ''), (string) $inspection['source_fingerprint'])
                    || !hash_equals((string) ($entry['post_renewal_fingerprint'] ?? ''), (string) $inspection['renewal_fingerprint'])
                    || !hash_equals((string) ($entry['pre_release_comparison_fingerprint'] ?? ''), (string) $inspection['release_comparison_fingerprint'])) {
                    throw new \RuntimeException('subscription_source_drift_after_release:' . $entry['source_identity']);
                }
                continue;
            }
            $inspection = $this->source->inspect($identity);
            if ($entry['release_state'] === 'marked') {
                if (($inspection['requires_manual_renewal'] ?? false) === true
                    && hash_equals((string) ($entry['pre_renewal_fingerprint'] ?? ''), (string) $inspection['renewal_fingerprint'])
                    && hash_equals((string) ($entry['pre_release_comparison_fingerprint'] ?? ''), (string) $inspection['release_comparison_fingerprint'])) {
                    $updated = $evidence->entries;
                    $updated[$index] = ['release_state' => 'released',
                        'post_source_fingerprint' => $inspection['source_fingerprint'],
                        'post_renewal_fingerprint' => $inspection['renewal_fingerprint']] + $entry;
                    $evidence = $this->replace($evidence, $updated, $nowUtc);
                    continue;
                }
                if (!hash_equals((string) $entry['source_fingerprint'], (string) $inspection['source_fingerprint'])) {
                    throw new \RuntimeException('subscription_source_release_ambiguous_after_crash:' . $entry['source_identity']);
                }
            } elseif (!hash_equals((string) $entry['source_fingerprint'], (string) $inspection['source_fingerprint'])
                || ($inspection['requires_manual_renewal'] ?? true) !== false) {
                throw new \RuntimeException('subscription_source_drift_before_release:' . $entry['source_identity']);
            }
            $marked = $evidence->entries;
            $marked[$index] = ['release_state' => 'marked',
                'pre_renewal_fingerprint' => $inspection['renewal_fingerprint'],
                'pre_release_comparison_fingerprint' => $inspection['release_comparison_fingerprint'],
                'previous_requires_manual_renewal' => false] + $entry;
            $evidence = $this->replace($evidence, $marked, $nowUtc);
            $released = $this->source->release($identity);
            if (($released['requires_manual_renewal'] ?? false) !== true
                || ($released['previous_requires_manual_renewal'] ?? true) !== false
                || !hash_equals((string) $inspection['renewal_fingerprint'], (string) $released['renewal_fingerprint'])
                || !hash_equals((string) $inspection['release_comparison_fingerprint'], (string) $released['release_comparison_fingerprint'])) {
                throw new \RuntimeException('subscription_source_release_unverified:' . $entry['source_identity']);
            }
            $updated = $evidence->entries;
            $updated[$index] = ['release_state' => 'released',
                'post_source_fingerprint' => $released['source_fingerprint'],
                'post_renewal_fingerprint' => $released['renewal_fingerprint']] + $evidence->entries[$index];
            $evidence = $this->replace($evidence, $updated, $nowUtc);
        }
        $entries = $evidence->entries;
        if (array_filter($entries, static fn (array $e): bool => !in_array($e['release_state'], ['released', 'not_required'], true)) !== []) {
            throw new \RuntimeException('subscription_source_release_incomplete');
        }
        if ($evidence->state === SubscriptionCutoverEvidence::SOURCE_RELEASED) return $evidence;
        $after = new SubscriptionCutoverEvidence(
            $evidence->runId, $evidence->sourceKey, $evidence->packageHash, $evidence->decisionHash,
            $evidence->selectionHash, $evidence->sourceInstanceFingerprint, $evidence->sourceRuntimeFingerprint,
            $evidence->executionContext, SubscriptionCutoverEvidence::SOURCE_RELEASED, $entries, $nowUtc,
        );
        $this->repository->replace($evidence, $after);
        return $after;
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
