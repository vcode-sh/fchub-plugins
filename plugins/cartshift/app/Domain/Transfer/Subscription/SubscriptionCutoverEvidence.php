<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;
use CartShift\Support\Enums\FcSubscriptionStatus;

defined('ABSPATH') || exit;

final readonly class SubscriptionCutoverEvidence
{
    public const string PREPARED = 'prepared';
    public const string SOURCE_RELEASED = 'source_released';
    public const string TARGET_ACTIVATED = 'target_activated';
    public const string RECONCILED = 'reconciled';

    /** @param list<array<string,mixed>> $entries */
    public function __construct(
        public string $runId,
        public string $sourceKey,
        public string $packageHash,
        public string $decisionHash,
        public string $selectionHash,
        public string $sourceInstanceFingerprint,
        public string $sourceRuntimeFingerprint,
        public string $executionContext,
        public string $state,
        public array $entries,
        public string $updatedAtUtc,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1
            || !in_array($executionContext, ['rehearsal', 'cutover', 'guided'], true)
            || !in_array($state, [self::PREPARED, self::SOURCE_RELEASED, self::TARGET_ACTIVATED, self::RECONCILED], true)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $updatedAtUtc) !== 1) {
            throw new \InvalidArgumentException('subscription_cutover_evidence_header_invalid');
        }
        SourceIdentity::assertValidSourceKey($sourceKey);
        foreach ([$packageHash, $decisionHash, $selectionHash, $sourceInstanceFingerprint, $sourceRuntimeFingerprint] as $hash) self::hash($hash);
        if (!array_is_list($entries) || $entries === []) throw new \InvalidArgumentException('subscription_cutover_evidence_entries_missing');
        $identities = [];
        $targets = [];
        $previousIdentity = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) self::invalidEntry();
            $keys = array_keys($entry);
            sort($keys, SORT_STRING);
            $allowed = [
                'activated_target_fingerprint', 'activation_state', 'intended_status',
                'post_renewal_fingerprint', 'post_source_fingerprint',
                'pre_release_comparison_fingerprint', 'pre_renewal_fingerprint',
                'previous_requires_manual_renewal', 'release_state', 'source_fingerprint',
                'source_identity', 'source_release_required', 'staged_target_fingerprint', 'target_id',
            ];
            if (array_diff($keys, $allowed) !== []) self::invalidEntry();
            $identity = SourceIdentity::fromCanonical((string) ($entry['source_identity'] ?? ''));
            if ($identity->sourceKey !== $sourceKey || $identity->entityType !== 'subscription'
                || isset($identities[$identity->canonical()])
                || !is_int($entry['target_id'] ?? null) || $entry['target_id'] <= 0
                || isset($targets[$entry['target_id']])
                || !is_bool($entry['source_release_required'] ?? null)
                || !is_string($entry['intended_status'] ?? null)
                || FcSubscriptionStatus::tryFrom($entry['intended_status']) === null
                || !in_array($entry['release_state'] ?? null, ['pending', 'marked', 'not_required', 'released'], true)
                || !in_array($entry['activation_state'] ?? null, ['paused', 'marked', 'activated', 'reconciled'], true)) {
                self::invalidEntry();
            }
            if ($previousIdentity !== null && strnatcmp($previousIdentity, $identity->canonical()) >= 0) self::invalidEntry();
            foreach (['source_fingerprint', 'staged_target_fingerprint'] as $field) self::hash((string) ($entry[$field] ?? ''));
            foreach ([
                'activated_target_fingerprint', 'post_renewal_fingerprint', 'post_source_fingerprint',
                'pre_release_comparison_fingerprint', 'pre_renewal_fingerprint',
            ] as $field) {
                if (array_key_exists($field, $entry)) self::hash(is_string($entry[$field]) ? $entry[$field] : '');
            }
            $releaseState = (string) $entry['release_state'];
            $releaseFields = [
                'post_renewal_fingerprint', 'post_source_fingerprint',
                'pre_release_comparison_fingerprint', 'pre_renewal_fingerprint',
                'previous_requires_manual_renewal',
            ];
            $presentReleaseFields = array_values(array_intersect($releaseFields, $keys));
            $expectedReleaseFields = match ($releaseState) {
                'marked' => ['pre_release_comparison_fingerprint', 'pre_renewal_fingerprint', 'previous_requires_manual_renewal'],
                'released' => $releaseFields,
                default => [],
            };
            sort($presentReleaseFields, SORT_STRING);
            sort($expectedReleaseFields, SORT_STRING);
            if ($presentReleaseFields !== $expectedReleaseFields
                || (array_key_exists('previous_requires_manual_renewal', $entry) && $entry['previous_requires_manual_renewal'] !== false)
                || ($entry['source_release_required'] === false && $releaseState !== 'not_required')
                || ($entry['source_release_required'] === true && $releaseState === 'not_required')) {
                self::invalidEntry();
            }
            $activationState = (string) $entry['activation_state'];
            if ($activationState === 'reconciled' && !isset($entry['activated_target_fingerprint'])) self::invalidEntry();
            if (in_array($activationState, ['paused', 'marked'], true) && isset($entry['activated_target_fingerprint'])) self::invalidEntry();
            if (in_array($state, [self::SOURCE_RELEASED, self::TARGET_ACTIVATED, self::RECONCILED], true)
                && !in_array($releaseState, ['released', 'not_required'], true)) {
                self::invalidEntry();
            }
            if ($state === self::TARGET_ACTIVATED && $activationState !== 'activated') self::invalidEntry();
            if ($state === self::RECONCILED && $activationState !== 'reconciled') self::invalidEntry();
            $identities[$identity->canonical()] = true;
            $targets[$entry['target_id']] = true;
            $previousIdentity = $identity->canonical();
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return CanonicalJson::canonicalise([
            'version' => 1,
            'run_id' => $this->runId,
            'source_key' => $this->sourceKey,
            'package_hash' => $this->packageHash,
            'decision_hash' => $this->decisionHash,
            'selection_hash' => $this->selectionHash,
            'source_instance_fingerprint' => $this->sourceInstanceFingerprint,
            'source_runtime_fingerprint' => $this->sourceRuntimeFingerprint,
            'execution_context' => $this->executionContext,
            'state' => $this->state,
            'entries' => $this->entries,
            'updated_at_utc' => $this->updatedAtUtc,
        ]);
    }

    public function requiresSourceRelease(): bool
    {
        return array_filter(
            $this->entries,
            static fn (array $entry): bool => ($entry['source_release_required'] ?? false) === true,
        ) !== [];
    }

    /** Mark-before-act makes any marked entry as irreversible as the cohort state. */
    public function releaseStarted(): bool
    {
        if ($this->state !== self::PREPARED) {
            return true;
        }

        return array_filter(
            $this->entries,
            static fn (array $entry): bool => in_array(
                $entry['release_state'] ?? null,
                ['marked', 'released'],
                true,
            ),
        ) !== [];
    }

    public function fingerprint(): string { return CanonicalJson::fingerprint($this->toArray()); }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== 1) throw new \InvalidArgumentException('subscription_cutover_evidence_version_invalid');
        return new self(
            (string) ($data['run_id'] ?? ''), (string) ($data['source_key'] ?? ''),
            (string) ($data['package_hash'] ?? ''), (string) ($data['decision_hash'] ?? ''),
            (string) ($data['selection_hash'] ?? ''), (string) ($data['source_instance_fingerprint'] ?? ''),
            (string) ($data['source_runtime_fingerprint'] ?? ''), (string) ($data['execution_context'] ?? ''),
            (string) ($data['state'] ?? ''),
            is_array($data['entries'] ?? null) ? array_values($data['entries']) : [],
            (string) ($data['updated_at_utc'] ?? ''),
        );
    }

    private static function hash(string $hash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) throw new \InvalidArgumentException('subscription_cutover_evidence_fingerprint_invalid');
    }

    private static function invalidEntry(): never
    {
        throw new \InvalidArgumentException('subscription_cutover_evidence_entry_invalid');
    }
}
