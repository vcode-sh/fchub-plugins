<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Durable adapter state for one same-site migration. */
final readonly class GuidedRunState
{
    public const string READY = 'ready';
    public const string RUNNING = 'running';
    public const string AWAITING_DECISIONS = 'awaiting_decisions';
    public const string AWAITING_RENEWAL_PAUSE = 'awaiting_renewal_pause';
    public const string FAILED = 'failed';
    public const string COMPLETED = 'completed';
    public const string CANCELLED = 'cancelled';
    public const string ROLLING_BACK = 'rolling_back';
    public const string ROLLED_BACK = 'rolled_back';

    /** @param list<array<string,mixed>> $migrationExceptions @param array<string, mixed> $lastResult */
    private function __construct(
        public string $sourceKey,
        public string $operator,
        public string $decidedAtUtc,
        public bool $includesSubscriptions,
        public int $nextStep,
        public GuidedEvidence $evidence,
        public array $migrationExceptions,
        public string $phase,
        public ?string $lastVerb,
        public array $lastResult,
        public ?string $failure,
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);
        if (trim($operator) === ''
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $decidedAtUtc) !== 1
            || $nextStep < 0 || $nextStep > GuidedRunPlan::stepCount(true)
            || !array_is_list($migrationExceptions)
            || !in_array($phase, self::phases(), true)
            || ($failure !== null && trim($failure) === '')) {
            throw new \InvalidArgumentException('guided_run_state_invalid');
        }
    }

    public static function start(
        string $sourceKey,
        string $operator,
        string $decidedAtUtc,
        bool $includesSubscriptions = false,
    ): self
    {
        return new self(
            $sourceKey,
            $operator,
            $decidedAtUtc,
            $includesSubscriptions,
            0,
            GuidedEvidence::none(),
            [],
            self::READY,
            null,
            [],
            null,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $legacy = [
            'decided_at_utc', 'evidence', 'failure', 'last_result', 'last_verb',
            'next_step', 'operator', 'phase', 'source_key',
        ];
        $expected = [...$legacy, 'includes_subscriptions', 'migration_exceptions'];
        sort($expected, SORT_STRING);
        sort($legacy, SORT_STRING);
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        if (!in_array($actual, [$legacy, $expected], true)
            || !is_array($data['evidence'])
            || !is_array($data['last_result'])
            || !is_array($data['migration_exceptions'] ?? [])) {
            throw new \InvalidArgumentException('guided_run_state_invalid');
        }

        $evidence = $data['evidence'];
        if (array_keys($evidence) !== ['descriptor', 'package_path', 'selection_fingerprint']) {
            throw new \InvalidArgumentException('guided_run_state_invalid');
        }

        return new self(
            self::string($data['source_key']),
            self::string($data['operator']),
            self::string($data['decided_at_utc']),
            ($data['includes_subscriptions'] ?? false) === true,
            is_int($data['next_step']) ? $data['next_step'] : -1,
            self::evidence($evidence),
            array_values($data['migration_exceptions'] ?? []),
            self::string($data['phase']),
            self::nullableString($data['last_verb']),
            $data['last_result'],
            self::nullableString($data['failure']),
        );
    }

    /** @param array<string, mixed> $result */
    public function afterStep(string $verb, array $result, int $stepCount): self
    {
        if ($stepCount <= 0 || $this->nextStep >= $stepCount || $this->isTerminal()) {
            throw new \LogicException('guided_run_step_transition_invalid');
        }
        if ($verb === 'compatibility' && ($result['ready'] ?? false) !== true) {
            throw new \RuntimeException('guided_compatibility_blocked');
        }
        if ($verb === 'validate-package' && ($result['status'] ?? null) !== 'validated') {
            throw new \RuntimeException('guided_package_validation_blocked');
        }
        if ($verb === 'propose-decisions'
            && !in_array($result['status'] ?? null, ['owner_review_required', 'blocked'], true)) {
            throw new \RuntimeException('guided_decision_proposal_invalid');
        }

        if ($verb === 'propose-decisions' && self::needsDecisionReview($result)) {
            return $this->copy(
                phase: self::AWAITING_DECISIONS,
                lastVerb: $verb,
                lastResult: $result,
            );
        }

        $evidence = match ($verb) {
            'audit' => $this->evidence->withSelectionFingerprint(
                self::sha256($result['selection_fingerprint'] ?? null, 'guided_run_selection_fingerprint_invalid'),
            ),
            'export' => $this->evidence->withPackage(
                self::absolutePath($result['path'] ?? null),
            ),
            'prepare' => $this->evidence->withDescriptor(
                self::nonEmpty($result['descriptor'] ?? null, 'guided_run_descriptor_invalid'),
            ),
            default => $this->evidence,
        };
        if ($verb === 'prepare-subscription-cutover'
            && (!is_bool($result['subscription_cutover_required'] ?? null)
                || !is_bool($result['subscription_release_required'] ?? null))) {
            throw new \RuntimeException('guided_subscription_cutover_result_invalid');
        }
        $skipEmptyCutover = $verb === 'prepare-subscription-cutover'
            && $result['subscription_cutover_required'] === false;
        $next = $this->nextStep + ($skipEmptyCutover ? 3 : 1);
        $phase = $verb === 'prepare-subscription-cutover'
            && $result['subscription_release_required'] === true
            ? self::AWAITING_RENEWAL_PAUSE
            : ($next === $stepCount ? self::COMPLETED : self::RUNNING);

        return new self(
            $this->sourceKey,
            $this->operator,
            $this->decidedAtUtc,
            $this->includesSubscriptions,
            $next,
            $evidence,
            is_array($result['migration_exceptions'] ?? null)
                ? self::mergeMigrationExceptions(
                    $this->migrationExceptions,
                    array_values($result['migration_exceptions']),
                )
                : $this->migrationExceptions,
            $phase,
            $verb,
            $result,
            null,
        );
    }

    /** @param array<string, mixed> $acceptance */
    public function afterDecisionAcceptance(array $acceptance, int $stepCount): self
    {
        if ($this->phase !== self::AWAITING_DECISIONS || $this->lastVerb !== 'propose-decisions') {
            throw new \LogicException('guided_run_not_awaiting_decisions');
        }
        $next = $this->nextStep + 1;

        return new self(
            $this->sourceKey,
            $this->operator,
            $this->decidedAtUtc,
            $this->includesSubscriptions,
            $next,
            $this->evidence,
            is_array($acceptance['migration_exceptions'] ?? null)
                ? array_values($acceptance['migration_exceptions'])
                : $this->migrationExceptions,
            $next === $stepCount ? self::COMPLETED : self::RUNNING,
            'propose-decisions',
            $acceptance,
            null,
        );
    }

    /** Replace a displayed proposal with current source evidence, or advance when no decision remains. */
    public function afterDecisionRefresh(array $proposal, int $stepCount): self
    {
        if ($this->phase !== self::AWAITING_DECISIONS || $this->lastVerb !== 'propose-decisions') {
            throw new \LogicException('guided_run_not_awaiting_decisions');
        }
        if (!in_array($proposal['status'] ?? null, ['owner_review_required', 'blocked'], true)) {
            throw new \RuntimeException('guided_decision_proposal_invalid');
        }
        if (self::needsDecisionReview($proposal)) {
            return $this->copy(lastResult: $proposal);
        }

        return $this->afterDecisionAcceptance(['accepted' => 0], $stepCount);
    }

    /** Record the owner's immediate maintenance assertion without advancing evidence. */
    public function afterRenewalsPaused(): self
    {
        if ($this->phase !== self::AWAITING_RENEWAL_PAUSE
            || $this->lastVerb !== 'prepare-subscription-cutover') {
            throw new \LogicException('guided_run_not_awaiting_renewal_pause');
        }

        return $this->copy(phase: self::RUNNING);
    }

    public function afterFailure(string $verb, \Throwable $failure): self
    {
        $context = $failure instanceof GuidedRunFailure ? $failure->context : [];

        return $this->copy(
            phase: self::FAILED,
            lastVerb: $verb,
            lastResult: $context,
            migrationExceptions: is_array($context['migration_exceptions'] ?? null)
                ? array_values($context['migration_exceptions'])
                : $this->migrationExceptions,
            failure: self::nonEmpty($failure->getMessage(), 'guided_run_failure_invalid'),
        );
    }

    public function cancel(): self
    {
        if ($this->phase !== self::AWAITING_DECISIONS) {
            throw new \LogicException('guided_run_cannot_cancel');
        }

        return $this->copy(phase: self::CANCELLED);
    }

    /** @param array<string,mixed> $sealed */
    public function beginRollback(array $sealed): self
    {
        if ($this->phase !== self::FAILED
            || array_keys($sealed) !== [
                'rollback_plan',
                'rollback_plan_fingerprint',
                'lease_recovery',
                'deletion_count',
            ]
            || !is_string($sealed['rollback_plan'])
            || !str_starts_with($sealed['rollback_plan'], '/')
            || !is_string($sealed['rollback_plan_fingerprint'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $sealed['rollback_plan_fingerprint']) !== 1
            || !is_string($sealed['lease_recovery'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $sealed['lease_recovery']) !== 1
            || !is_int($sealed['deletion_count'])
            || $sealed['deletion_count'] < 0) {
            throw new \LogicException('guided_run_rollback_intent_invalid');
        }

        return $this->copy(
            phase: self::ROLLING_BACK,
            lastVerb: 'rollback',
            lastResult: $sealed,
        );
    }

    /** @param array<string, mixed> $result */
    public function afterRollback(array $result): self
    {
        if (!in_array($this->phase, [self::FAILED, self::ROLLING_BACK], true)
            || ($result['state'] ?? null) !== self::ROLLED_BACK) {
            throw new \LogicException('guided_run_rollback_transition_invalid');
        }

        return $this->copy(
            phase: self::ROLLED_BACK,
            lastVerb: 'rollback',
            lastResult: $result,
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->phase, [
            self::FAILED,
            self::COMPLETED,
            self::CANCELLED,
            self::ROLLING_BACK,
            self::ROLLED_BACK,
        ], true);
    }

    public function canRestart(): bool
    {
        if ($this->phase !== self::FAILED || $this->evidence->descriptor !== null) {
            return false;
        }

        return !str_contains((string) $this->failure, 'guided_dependency_bound_target_readiness_unavailable')
            && !str_contains((string) $this->failure, 'guided_completed_rehearsal_rollback_unavailable')
            && !str_contains((string) $this->failure, 'guided_subscription_mode_changed');
    }

    /** A pre-target stop from the former rehearsal gate is safe to replace with a current review. */
    public function canReplaceBeforeTarget(): bool
    {
        if ($this->phase !== self::FAILED || $this->evidence->descriptor !== null) {
            return false;
        }

        return str_contains((string) $this->failure, 'guided_dependency_bound_target_readiness_unavailable')
            || str_contains((string) $this->failure, 'guided_completed_rehearsal_rollback_unavailable');
    }

    /** Resume only after durable subscription evidence proves rollback is no longer the safe direction. */
    public function resumeForward(): self
    {
        if ($this->phase !== self::FAILED || !$this->includesSubscriptions || $this->evidence->descriptor === null) {
            throw new \LogicException('guided_run_forward_resume_invalid');
        }

        return $this->copy(phase: self::RUNNING, failure: null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'decided_at_utc' => $this->decidedAtUtc,
            'evidence' => [
                'descriptor' => $this->evidence->descriptor,
                'package_path' => $this->evidence->packagePath,
                'selection_fingerprint' => $this->evidence->selectionFingerprint,
            ],
            'failure' => $this->failure,
            'includes_subscriptions' => $this->includesSubscriptions,
            'last_result' => $this->lastResult,
            'last_verb' => $this->lastVerb,
            'migration_exceptions' => $this->migrationExceptions,
            'next_step' => $this->nextStep,
            'operator' => $this->operator,
            'phase' => $this->phase,
            'source_key' => $this->sourceKey,
        ];
    }

    /** @param array<string, mixed> $lastResult */
    private function copy(
        ?string $phase = null,
        ?string $lastVerb = null,
        ?array $lastResult = null,
        ?array $migrationExceptions = null,
        ?string $failure = null,
    ): self {
        return new self(
            $this->sourceKey,
            $this->operator,
            $this->decidedAtUtc,
            $this->includesSubscriptions,
            $this->nextStep,
            $this->evidence,
            $migrationExceptions ?? $this->migrationExceptions,
            $phase ?? $this->phase,
            $lastVerb ?? $this->lastVerb,
            $lastResult ?? $this->lastResult,
            $failure,
        );
    }

    /** @return list<string> */
    private static function phases(): array
    {
        return [
            self::READY,
            self::RUNNING,
            self::AWAITING_DECISIONS,
            self::AWAITING_RENEWAL_PAUSE,
            self::FAILED,
            self::COMPLETED,
            self::CANCELLED,
            self::ROLLING_BACK,
            self::ROLLED_BACK,
        ];
    }

    /** @param array<string,mixed> $proposal */
    private static function needsDecisionReview(array $proposal): bool
    {
        if (($proposal['status'] ?? null) === 'blocked') {
            return true;
        }

        if (!is_array($proposal['proposal_decisions'] ?? null)
            || ($proposal['proposal_decisions'] ?? []) !== []) {
            return true;
        }
        foreach (['product_questions', 'customer_questions', 'collision_questions'] as $key) {
            if (array_key_exists($key, $proposal)
                && (!is_array($proposal[$key]) || $proposal[$key] !== [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep every owner-accepted follow-up while adding each target stock exception once.
     *
     * @param list<array<string,mixed>> $existing
     * @param list<array<string,mixed>> $targetReadiness
     * @return list<array<string,mixed>>
     */
    private static function mergeMigrationExceptions(array $existing, array $targetReadiness): array
    {
        $merged = $existing;
        $stockKeys = [];
        foreach ($existing as $exception) {
            if (($exception['kind'] ?? null) === 'shared_parent_stock') {
                $stockKeys[self::stockExceptionKey($exception)] = true;
            }
        }
        foreach ($targetReadiness as $exception) {
            if (($exception['kind'] ?? null) !== 'shared_parent_stock') {
                $merged[] = $exception;
                continue;
            }
            $key = self::stockExceptionKey($exception);
            if (isset($stockKeys[$key])) {
                continue;
            }
            $stockKeys[$key] = true;
            $merged[] = $exception;
        }

        return $merged;
    }

    /** @param array<string,mixed> $exception */
    private static function stockExceptionKey(array $exception): string
    {
        $variation = trim((string) ($exception['source_variation'] ?? ''));

        return $variation !== '' ? $variation : CanonicalJson::fingerprint($exception);
    }

    /** @param array<string, mixed> $data */
    private static function evidence(array $data): GuidedEvidence
    {
        $evidence = GuidedEvidence::none();
        if ($data['selection_fingerprint'] !== null) {
            $evidence = $evidence->withSelectionFingerprint(self::sha256($data['selection_fingerprint'], 'guided_run_state_invalid'));
        }
        if ($data['package_path'] !== null) {
            $evidence = $evidence->withPackage(self::absolutePath($data['package_path']));
        }
        if ($data['descriptor'] !== null) {
            $evidence = $evidence->withDescriptor(self::nonEmpty($data['descriptor'], 'guided_run_state_invalid'));
        }

        return $evidence;
    }

    private static function sha256(mixed $value, string $reason): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException($reason);
        }

        return $value;
    }

    private static function absolutePath(mixed $value): string
    {
        $path = self::nonEmpty($value, 'guided_run_package_path_invalid');
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('guided_run_package_path_invalid');
        }

        return $path;
    }

    private static function nonEmpty(mixed $value, string $reason): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException($reason);
        }

        return $value;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || is_string($value) ? $value : '';
    }
}
