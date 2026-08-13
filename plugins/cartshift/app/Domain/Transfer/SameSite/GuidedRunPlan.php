<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

defined('ABSPATH') || exit;

/**
 * Every argument of a same-site transfer, computed instead of typed.
 *
 * The v2 contract asks an operator for sixteen things and an ordering, across
 * two shells. Fifteen of the sixteen are only variable because the cross-runtime
 * topology makes them variable — on one WordPress there is one role pair, one
 * disk, one shop and one clock, so CartShift can answer them itself. This class
 * is where it does.
 *
 * PURE, AND THAT IS LOAD-BEARING. No `$wpdb`, no options, no filesystem, no
 * filters. State in, ordered verbs out. It is the object that decides what runs
 * against somebody's shop, so it is the object that has to be exhaustively
 * assertable rather than smoke-tested down one path.
 *
 * THE ORDERING IS NOT INVENTED HERE. `TransferRunState::canTransitionTo()` is
 * the authority — Exported → Validated → Prepared → Staging → Staged →
 * Reconciling → Reconciled → Promoted → CatalogueActivating → Completed — and
 * the verb sequence below is the walk that produces it. Where that authority is
 * silent, this class emits no extra transition.
 */
final readonly class GuidedRunPlan
{
    private const int BASE_STEP_COUNT = 12;

    private const int SUBSCRIPTION_STEP_COUNT = 3;

    private const string CONTEXT_GUIDED = 'guided';
    private const string CONTEXT_CUTOVER = 'cutover';

    private const string DECISION_SET = 'decisions.json';

    private function __construct(
        private string $sourceKey,
        private string $workspace,
        private string $operator,
        private string $decidedAtUtc,
        private GuidedEvidence $evidence,
        private bool $includesSubscriptions,
        private string $executionContext,
        /** Null asks the adapter for the guided whole-shop source scope. */
        private ?array $clauses,
    ) {
        // THE FORMAT IS NOT A PREFERENCE. `TransferDecisionProposalPipeline`
        // refuses anything but ISO 8601 with the T and the Z, and it does so
        // several steps into a run. Rejecting it here means a plan that exists
        // is a plan whose timestamp its consumer will accept. Found against the
        // mounted shop: this shipped as `Y-m-d H:i:s`, and the test asserted
        // that same wrong shape — a test pinning a defect rather than catching it.
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $decidedAtUtc) !== 1) {
            throw new \InvalidArgumentException(
                'A decision timestamp must be ISO 8601 UTC, as gmdate(\'Y-m-d\\TH:i:s\\Z\') produces. Given: '
                . $decidedAtUtc,
            );
        }

        if (trim($operator) === '') {
            throw new \InvalidArgumentException('A decision needs an operator.');
        }
    }

    /**
     * @param array<string, string>|null $clauses Lower-level selection override. Null is every supported kind.
     */
    public static function rehearsal(
        string $sourceKey,
        string $workspace,
        string $operator,
        string $decidedAtUtc,
        GuidedEvidence $evidence,
        bool $includesSubscriptions,
        ?array $clauses = null,
    ): self {
        return new self(
            $sourceKey,
            $workspace,
            $operator,
            $decidedAtUtc,
            $evidence,
            $includesSubscriptions,
            self::CONTEXT_GUIDED,
            $clauses,
        );
    }

    /**
     * A cutover, which exists only downstream of a rehearsal that finished.
     *
     * The proof is the parameter, so skipping the rehearsal is not a decision an
     * operator can take — there is nothing to pass.
     *
     * @param array<string, string>|null $clauses
     */
    public static function cutover(
        string $sourceKey,
        string $workspace,
        string $operator,
        string $decidedAtUtc,
        GuidedEvidence $evidence,
        RehearsalProof $proof,
        bool $includesSubscriptions = false,
        ?array $clauses = null,
    ): self {
        return new self(
            $sourceKey,
            $workspace,
            $operator,
            $decidedAtUtc,
            $evidence,
            $includesSubscriptions,
            self::CONTEXT_CUTOVER,
            $clauses,
        );
    }

    public function includesSubscriptions(): bool
    {
        return $this->includesSubscriptions;
    }

    /**
     * Why subscriptions are not in this plan, or null when they are.
     *
     * An absent add-on is a capability that is not here. It is never a
     * subscription selection that came back empty, and the two must not be
     * spelled the same way anywhere a person can read them.
     */
    public function subscriptionsSkippedReason(): ?string
    {
        return $this->includesSubscriptions ? null : 'wc_subscriptions_inactive';
    }

    /**
     * The whole run, in order.
     *
     * @return list<GuidedStep>
     */
    public function steps(): array
    {
        $decisionSet = $this->workspace . '/' . self::DECISION_SET;
        $packageDestination = $this->workspace . '/guided-packages/' . substr(hash(
            'sha256',
            $this->sourceKey . '|' . $this->operator . '|' . $this->decidedAtUtc,
        ), 0, 24);

        $steps = [
            $this->step('compatibility', ['role' => 'source']),
            $this->step('compatibility', ['role' => 'target']),
            // THE AUDIT READS THE DECISIONS TOO.
            // Without `--decision-set` it judges the shop as if nobody had
            // decided anything, so the nine order-note findings the owner has
            // just accepted still read as blockers and `export` refuses with
            // "a selected record did not pass source assessment". The CLI takes
            // the same optional flag; omitting it here made acceptance
            // invisible to the very step that gates on it. Found by running the
            // whole rehearsal, not by any assertion.
            $this->step('audit', [
                'role' => 'source',
                'source-key' => $this->sourceKey,
            ] + $this->selection() + ['decision-set' => $decisionSet]),
            $this->step('propose-decisions', [
                'role' => 'source',
                'source-key' => $this->sourceKey,
            ] + $this->selection() + [
                'decision-set' => $decisionSet,
                'operator' => $this->operator,
                'decided-at' => $this->decidedAtUtc,
            ]),
            $this->step('export', [
                'role' => 'source',
                'source-key' => $this->sourceKey,
            ] + $this->selection() + [
                'decision-set' => $decisionSet,
                'destination' => $packageDestination,
            ]),
            $this->step('validate-package', [
                'role' => 'target',
                'decision-set' => $decisionSet,
            ], ['package']),
            $this->step('prepare', [
                'role' => 'target',
            ], ['package'], [
                'decision-set' => $decisionSet,
                'private-dir' => $this->workspace,
                'execution-context' => $this->executionContext,
            ]),
            ...array_map(
                fn (string $verb): GuidedStep => $this->step(
                    $verb,
                    ['role' => 'target'],
                    ['package', 'descriptor', 'confirm'],
                    ['execution-context' => $this->executionContext],
                ),
                ['stage', 'reconcile', 'promote'],
            ),
        ];

        if ($this->includesSubscriptions) {
            $steps[] = $this->step(
                'prepare-subscription-cutover',
                ['role' => 'target'],
                ['package', 'descriptor', 'confirm'],
                ['execution-context' => $this->executionContext],
            );
            $steps[] = $this->step(
                'release-subscription-source',
                ['role' => 'source', 'private-dir' => $this->workspace],
                ['descriptor'],
                ['execution-context' => $this->executionContext],
            );
            $steps[] = $this->step(
                'activate-subscriptions',
                ['role' => 'target'],
                ['package', 'descriptor', 'confirm'],
                ['execution-context' => $this->executionContext],
            );
        }

        return [
            ...$steps,
            $this->step(
                'activate-catalogue',
                ['role' => 'target'],
                ['package', 'descriptor', 'confirm'],
                ['execution-context' => $this->executionContext],
            ),
            $this->step(
                'complete',
                ['role' => 'target'],
                ['package', 'descriptor', 'confirm'],
                ['execution-context' => $this->executionContext],
            ),
        ];
    }

    public static function stepCount(bool $includesSubscriptions): int
    {
        return self::BASE_STEP_COUNT + ($includesSubscriptions ? self::SUBSCRIPTION_STEP_COUNT : 0);
    }

    /**
     * Request the guided source scope while keeping subscription availability
     * fixed so a later plugin activation cannot widen an in-flight run.
     *
     * @return array<string, string|true>
     */
    private function selection(): array
    {
        return $this->clauses ?? [
            'all-kinds' => true,
            'subscriptions' => $this->includesSubscriptions ? 'all' : 'none',
        ];
    }

    /**
     * @param array<string, string|true> $leading
     * @param list<string> $needs Evidence options, supplied when it has arrived.
     * @param array<string, string|true> $trailing
     */
    private function step(string $verb, array $leading, array $needs = [], array $trailing = []): GuidedStep
    {
        $resolved = [];
        $pending = [];

        foreach ($needs as $option) {
            $value = match ($option) {
                'package' => $this->evidence->packagePath,
                'descriptor' => $this->evidence->descriptor,
                'confirm' => $this->evidence->selectionFingerprint,
            };

            $value === null ? $pending[] = $option : $resolved[$option] = $value;
        }

        sort($pending);

        return new GuidedStep(
            $verb,
            [...$leading, ...$resolved, ...$trailing, 'format' => 'json'],
            $pending,
        );
    }
}
