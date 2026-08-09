<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * What state one receipt entry is actually in — the single authority, and the
 * reason this class exists at all.
 *
 * FOUR PLACES USED TO ANSWER THIS, AND THEY DISAGREED ONCE PER REVIEW ROUND.
 * `structuralFailures()` read the release vocabulary, `isHeld()` read the
 * outcome and the history, `isReleasedSource()` read first the vocabulary and
 * then the state rank, and `releaseOne()` carried its own idempotency list.
 * Every one of them was a slightly different guess at the same question, so a
 * failure branch that cleared one field walked straight past the others: a
 * blocked restoration cleared `released`, a post-save drift left the entry at
 * `staged`, and both times `stage` rebuilt an entry whose WooCommerce
 * subscription had already been set to manual renewal — losing the only record
 * that the subscriber was ever on automatic billing.
 *
 * THE QUESTION THEY KEPT FAILING TO ASK IS "WAS THE SOURCE MUTATED?", and it is
 * not the same question as "what state is the entry in" or "what does the
 * release vocabulary say":
 *
 * | Situation                        | Release state    | Source mutated |
 * |----------------------------------|------------------|----------------|
 * | terminal historical record       | `not_required`   | no             |
 * | source was already manual        | `already_manual` | no             |
 * | open renewal order, refused      | `blocked`        | no             |
 * | drift found AFTER the save       | `blocked`        | **yes**        |
 * | released cleanly                 | `released`       | yes            |
 * | restored cleanly                 | `restored`       | no (put back)  |
 *
 * Rows three and four carry the same release state and are opposites. That is
 * the distinction, it has exactly one writer — `SourceRenewalGuard`, which is
 * the only code that touches WooCommerce — and it is recorded in the receipt so
 * a later command inherits the fact rather than re-deriving it from a flag it
 * cannot interpret.
 *
 * This object is a reader. It never writes; it answers.
 */
final readonly class CutoverEntryState
{
    /**
     * Release states that satisfy "the source cannot charge this any more".
     *
     * `transferred` is here for PayPal's remote schedule, where the provider
     * keeps billing and the target receives its events — ownership moved rather
     * than stopped. `not_required` is a terminal historical record, which has no
     * automatic owner to disable.
     *
     * @var list<string>
     */
    private const array SATISFIED = [
        CutoverReceipt::RELEASE_RELEASED,
        CutoverReceipt::RELEASE_ALREADY_MANUAL,
        CutoverReceipt::RELEASE_TRANSFERRED,
        CutoverReceipt::RELEASE_NOT_REQUIRED,
    ];

    private function __construct(
        public string $sourceRef,
        public string $state,
        public string $outcome,
        public string $releaseState,
        private bool $releaseRequired,
        private bool $sourceMutated,
        private bool $restorePending,
        private bool $historyReconciled,
        private int $targetSubscriptionId,
        public ?bool $previousRequiresManualRenewal,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function of(array $entry): self
    {
        $release  = (array) ($entry['source_release'] ?? []);
        $history  = (array) ($entry['history'] ?? []);
        $previous = $release['previous_requires_manual_renewal'] ?? null;

        return new self(
            (string) ($entry['source_ref'] ?? ''),
            (string) ($entry['state'] ?? CutoverReceipt::STATE_ASSESSED),
            (string) ($entry['outcome'] ?? CutoverReceipt::OUTCOME_READY),
            (string) ($release['state'] ?? CutoverReceipt::RELEASE_PENDING),
            ($release['required'] ?? false) === true,
            ($release['source_mutated'] ?? false) === true,
            trim((string) ($release['restore_intent_at_utc'] ?? '')) !== '',
            ($history['reconciled'] ?? false) === true,
            (int) ($entry['target_subscription_id'] ?? 0),
            // THE SAME COERCION `CutoverReceipt::nullableBool()` APPLIES, and
            // for the same reason: null means "nobody recorded it", and
            // everything else is a recorded value.
            //
            // The previous `is_bool()` test mapped a truthy non-bool — a `1`
            // out of a JSON round trip, a `"1"` out of a hand-edited receipt —
            // to `null`, which is not "unknown", it is a LIE. `releaseOne()`
            // reads null as "no recorded value, use the guard's fresh reading",
            // and on a resume the guard reads a source CartShift has already
            // set to manual. The entry would then record "this subscriber was
            // always on manual renewal" over the truth, with no second copy
            // anywhere to correct it from.
            //
            // Latent while every call site fed a normalised entry. It stopped
            // being latent the moment the resume-after-mutation path became
            // reachable.
            $previous === null ? null : (bool) $previous,
        );
    }

    // ──────────────────────────────────────────────
    // What happened to it
    // ──────────────────────────────────────────────

    /**
     * Whether CartShift has written to this subscription's WooCommerce record.
     *
     * The one question `stage` has to ask before rebuilding an entry, because
     * rebuilding drops `previous_requires_manual_renewal` — and if the source
     * was mutated, that flag is the only surviving record of what the source was
     * before CartShift touched it. Not the release state, which a failure can
     * clear; not the entry rank, which a failure can fail to advance.
     */
    public function sourceWasMutated(): bool
    {
        // An unknown source counts as mutated, because the two answers a
        // rebuild cares about are "definitely untouched" and "anything else".
        return $this->sourceMutated || $this->restorePending;
    }

    /**
     * Whether a restoration was started for this entry and never came back.
     *
     * `restore-source` records the INTENT before it touches WooCommerce and the
     * OUTCOME after, so a crash — or a receipt write that fails — between the
     * two leaves an entry saying "I was about to put this source back" and
     * nothing saying whether it happened. That is a third state, and it is the
     * honest one: not restored-and-done, not safely stageable, but a source
     * somebody has to look at.
     *
     * Recording the outcome first was the obvious symmetric fix and is wrong.
     * `activate()` marks before acting because over-stating an activation is
     * safe; for a restoration the direction inverts, and marking `restored`
     * before the guard runs would trade "both systems charge" for "this
     * subscriber is silently on manual renewal for ever" — `isRestorable()`
     * would skip the entry on the retry and `stage` would be free to rebuild it.
     */
    public function sourceStateIsUnknown(): bool
    {
        return $this->restorePending;
    }

    /**
     * Whether the source can no longer charge this subscription.
     *
     * Deliberately NOT the same as `sourceWasMutated()`. A terminal record was
     * never mutated and is satisfied; a post-save drift block WAS mutated and is
     * not, because the release never completed and the operator has to look at
     * whatever appeared.
     */
    public function releaseSatisfied(): bool
    {
        return in_array($this->releaseState, self::SATISFIED, true);
    }

    /**
     * Whether `cutover-source` still has work to do here.
     */
    public function needsRelease(): bool
    {
        if (!$this->participates()) {
            return false;
        }

        // An unknown source is re-established rather than assumed: whichever
        // way the operator is going, the next command has to find out what the
        // source actually is before anything else happens.
        return !$this->releaseSatisfied() || $this->restorePending;
    }

    /**
     * Whether an earlier `cutover-source` mutated this source and then stopped.
     *
     * The resume case, and the one that must never re-read the source's manual
     * flag: it is manual because CartShift made it manual, so asking WooCommerce
     * again answers `true` and records "this subscriber was always on manual
     * renewal" over the truth.
     */
    public function awaitsReleaseAfterMutation(): bool
    {
        return $this->sourceMutated && $this->needsRelease();
    }

    /**
     * Whether `restore-source` has anything to put back.
     */
    public function isRestorable(): bool
    {
        return $this->sourceMutated;
    }

    // ──────────────────────────────────────────────
    // Whether the cutover is about it at all
    // ──────────────────────────────────────────────

    public function isBlocked(): bool
    {
        return $this->outcome === CutoverReceipt::OUTCOME_BLOCKED;
    }

    /**
     * Whether this entry is out of the cutover, and stays out.
     *
     * TWO REASONS, AND THEY ARE DIFFERENT SENTENCES.
     *
     * A BLOCKED record was never staged. It has no destination row, so it has no
     * source to release and nothing to activate. Counting it towards "has
     * everything released?" would let one unmigratable subscription stop the
     * entire cohort for ever.
     *
     * A record whose HISTORY DID NOT RECONCILE has a destination row and must
     * not move either. `SubscriptionReconciler` returns `reconciled: false` on
     * any disagreement between the source payment count, the imported paid
     * orders and FluentCart's own `calculateBillCount()`, and it deliberately
     * does not write `bill_count` in that case — so the row carries an
     * unverified cycle count. Section 10 step 7 says keep it paused and report
     * it, and this task's governing rule is that no source is disabled before
     * its destination row AND HISTORY are ready.
     *
     * Held is not blocked: the row exists, it is paused, it is counted, and the
     * operator repairs the history and re-runs `stage`.
     */
    public function isHeld(): bool
    {
        if ($this->isBlocked()) {
            return true;
        }

        // Guarded on a destination row existing, so "no history recorded" cannot
        // masquerade as "history disagreed" for a record that never got as far
        // as having any.
        return $this->targetSubscriptionId > 0 && !$this->historyReconciled;
    }

    /**
     * A held record whose history is the reason — as distinct from a blocked one.
     */
    public function isHeldForHistory(): bool
    {
        return !$this->isBlocked() && $this->isHeld();
    }

    public function participates(): bool
    {
        return !$this->isHeld();
    }
}
