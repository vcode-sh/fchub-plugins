<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\Package\PackagePath;

/**
 * The private cutover receipt, and the monotonic state machine written on it —
 * plan section 11.
 *
 * ```text
 * assessed -> staged -> source_released -> activated -> reconciled
 *                    \-> source_restored (only before activation)
 * ```
 *
 * This is a two-site handoff, so the receipt is the only thing both runtimes
 * agree about. The WooCommerce runtime cannot see FluentCart and the FluentCart
 * runtime cannot see WooCommerce; a file carried between them, with a checksum
 * over its entries and a state that only ever moves forward, is what stops one
 * side acting on an assumption about the other.
 *
 * THE INVARIANT IT ENFORCES is negative and it is the only one that matters:
 * no target subscription is activated before its source automatic owner has been
 * disabled or explicitly transferred, and no source is restored once any target
 * has been activated. An interruption at any command boundary therefore leaves
 * source billing authoritative or the destination paused, and never both
 * eligible to charge.
 *
 * FOUR FINGERPRINTS TRAVEL WITH IT, and every transition revalidates whichever
 * of them the current runtime can compute: the package checksum, the selection
 * fingerprint, the mapping-set fingerprint the audit published, and the target
 * subscription-settings fingerprint the operator approved. A context that omits
 * one is a runtime that cannot compute it — the source has no FluentCart mapping
 * set — rather than a caller choosing not to check.
 *
 * NOTHING SECRET GOES IN IT. `entry()` is an allow-list, not a filter with
 * exceptions: a caller may hand over a billing email, a Stripe customer or a
 * vault ID, and none of them will appear in the file. The receipt travels
 * between two machines by hand, and a hand is not an encrypted channel.
 */
final readonly class CutoverReceipt
{
    public const string SCHEMA_VERSION = '1';

    // ──────────────────────────────────────────────
    // States
    // ──────────────────────────────────────────────

    public const string STATE_ASSESSED = 'assessed';
    public const string STATE_STAGED = 'staged';
    public const string STATE_SOURCE_RELEASED = 'source_released';
    public const string STATE_ACTIVATED = 'activated';
    public const string STATE_RECONCILED = 'reconciled';
    public const string STATE_SOURCE_RESTORED = 'source_restored';

    /**
     * The forward line, as ranks.
     *
     * `source_restored` shares `staged`'s rank rather than getting one of its
     * own, and that is the whole of the branch. A restored receipt may release
     * again — rank 1 to rank 2 — and may not activate, because rank 3 from rank
     * 1 is a skip. One integer expresses both halves of the plan's diagram.
     *
     * @var array<string, int>
     */
    private const array RANKS = [
        self::STATE_ASSESSED        => 0,
        self::STATE_STAGED          => 1,
        self::STATE_SOURCE_RESTORED => 1,
        self::STATE_SOURCE_RELEASED => 2,
        self::STATE_ACTIVATED       => 3,
        self::STATE_RECONCILED      => 4,
    ];

    // ──────────────────────────────────────────────
    // Per-entry vocabulary
    // ──────────────────────────────────────────────

    public const string OUTCOME_READY = SubscriptionAssessment::OUTCOME_READY;
    public const string OUTCOME_CONFIRMED = 'confirmed';
    public const string OUTCOME_BLOCKED = SubscriptionAssessment::OUTCOME_BLOCKED;

    public const string RELEASE_PENDING = 'pending';
    public const string RELEASE_NOT_REQUIRED = 'not_required';
    public const string RELEASE_RELEASED = 'released';
    public const string RELEASE_ALREADY_MANUAL = 'already_manual';
    public const string RELEASE_TRANSFERRED = 'transferred';
    public const string RELEASE_RESTORED = 'restored';
    public const string RELEASE_BLOCKED = 'blocked';

    // ──────────────────────────────────────────────
    // Reason codes
    // ──────────────────────────────────────────────

    /** Section 9.4's History/cutover row. */
    public const string REASON_TRANSITION_INVALID = 'receipt_transition_invalid';
    public const string REASON_SOURCE_FINGERPRINT_CHANGED = 'source_fingerprint_changed';
    public const string REASON_SOURCE_RELEASE_UNVERIFIED = 'source_release_unverified';

    /** Section 9.4's Dataset row: the package underneath this receipt moved. */
    public const string REASON_PACKAGE_CHECKSUM_MISMATCH = 'dataset_checksum_mismatch';

    /**
     * Section 9.4's Payment row.
     *
     * A settings fingerprint that has moved since the approval was given is not
     * a different fault from an unapproved store mode — it IS an unapproved
     * store mode, because approval was bound to the configuration it was given
     * for. CartShift never repairs the mismatch by writing a FluentCart option.
     */
    public const string REASON_SETTINGS_NOT_APPROVED = 'system_store_mode_not_approved';

    /**
     * File integrity, deliberately outside section 9.4's vocabulary.
     *
     * These describe a receipt FILE — unreadable, edited, unwritable — rather
     * than a migration outcome. Mixing them into the migration codes would put
     * "somebody ran sed on the receipt" in the same list as "this subscriber's
     * next payment date is in the past", and retry logic keys off that list.
     */
    public const string REASON_UNREADABLE = 'receipt_unreadable';
    public const string REASON_CHECKSUM_MISMATCH = 'receipt_checksum_mismatch';
    public const string REASON_WRITE_FAILED = 'receipt_write_failed';

    /**
     * @param list<array<string, mixed>> $entries Canonical, sorted by source ref.
     * @param array<string, mixed>       $renewalMaintenance
     * @param array<string, mixed>       $selection
     */
    public function __construct(
        public string $state,
        public string $sourceKey,
        public string $packageChecksum,
        public string $selectionFingerprint,
        public string $mappingFingerprint,
        public string $targetSettingsFingerprint,
        public string $approvedSettingsFingerprint,
        public array $entries,
        public string $createdAtUtc,
        public string $updatedAtUtc,
        public array $renewalMaintenance = [],
        public array $selection = [],
    ) {
    }

    public static function begin(
        string $sourceKey,
        string $packageChecksum,
        string $selectionFingerprint,
        string $mappingFingerprint,
        string $targetSettingsFingerprint,
        string $approvedSettingsFingerprint = '',
        array $selection = [],
    ): self {
        $now = gmdate('Y-m-d H:i:s');

        return new self(
            self::STATE_ASSESSED,
            $sourceKey,
            $packageChecksum,
            $selectionFingerprint,
            $mappingFingerprint,
            $targetSettingsFingerprint,
            $approvedSettingsFingerprint,
            [],
            $now,
            $now,
            ['acknowledged' => false, 'acknowledged_at_utc' => null],
            $selection,
        );
    }

    // ──────────────────────────────────────────────
    // Entries
    // ──────────────────────────────────────────────

    /**
     * One receipt entry, normalised to the exact field set the receipt carries.
     *
     * An allow-list. Every key is named here and anything else a caller passes
     * is dropped — which is what makes "no secrets" a property of the type
     * rather than a promise about its callers.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function entry(array $fields): array
    {
        $release = (array) ($fields['source_release'] ?? []);
        $history = (array) ($fields['history'] ?? []);

        $entry = [
            'source_ref'             => (string) ($fields['source_ref'] ?? ''),
            'source_subscription_id' => (int) ($fields['source_subscription_id'] ?? 0),
            'source_fingerprint'     => (string) ($fields['source_fingerprint'] ?? ''),
            'source_status'          => (string) ($fields['source_status'] ?? ''),
            'outcome'                => (string) ($fields['outcome'] ?? self::OUTCOME_READY),
            'state'                  => (string) ($fields['state'] ?? self::STATE_ASSESSED),
            'terminal'               => (bool) ($fields['terminal'] ?? false),
            'target_subscription_id' => self::nullableInt($fields['target_subscription_id'] ?? null),
            'target_customer_id'     => self::nullableInt($fields['target_customer_id'] ?? null),
            'target_parent_order_id' => self::nullableInt($fields['target_parent_order_id'] ?? null),
            'payment_strategy'       => (string) ($fields['payment_strategy'] ?? ''),
            'collection_method'      => (string) ($fields['collection_method'] ?? ''),
            'next_action_owner'      => (string) ($fields['next_action_owner'] ?? ''),
            'intended_status'        => (string) ($fields['intended_status'] ?? ''),
            'staged_status'          => (string) ($fields['staged_status'] ?? ''),
            'reason_codes'           => self::codes($fields['reason_codes'] ?? []),
            'source_release'         => [
                'required'                         => (bool) ($release['required'] ?? false),
                'state'                            => (string) ($release['state'] ?? self::RELEASE_PENDING),
                /**
                 * Whether CartShift has written to this subscription's
                 * WooCommerce record. Written by exactly one thing —
                 * `SourceRenewalGuard`, the only code that touches the source —
                 * and read through `CutoverEntryState`. See that class for why
                 * this is not the same fact as the release state beside it.
                 *
                 * IT DEFAULTS TO FALSE, WHICH IS THE UNSAFE DIRECTION, and that
                 * is deliberate rather than overlooked: an entry being built for
                 * the first time has by definition not had its source touched.
                 * The consequence is that any future builder which omits the key
                 * silently CLEARS the mutation record, and the whole scheme's
                 * safety then rests on `structuralFailures()` refusing to
                 * rebuild an entry whose source was mutated — which is exactly
                 * the guard such a builder would be bypassing. Anything
                 * constructing an entry from an existing one must carry this
                 * field through; `SubscriptionCutover` does so by spreading the
                 * old `source_release` array behind the new keys.
                 */
                'source_mutated'                   => (bool) ($release['source_mutated'] ?? false),
                /**
                 * Set immediately before `restore-source` touches WooCommerce
                 * and cleared when the outcome is known. A value here with no
                 * outcome beside it means a restoration started and never came
                 * back — the source is in an UNKNOWN state and neither guard may
                 * assume either answer. See `CutoverEntryState::sourceStateIsUnknown()`.
                 */
                'restore_intent_at_utc'            => self::nullableString(
                    $release['restore_intent_at_utc'] ?? null,
                ),
                'previous_requires_manual_renewal' => self::nullableBool(
                    $release['previous_requires_manual_renewal'] ?? null,
                ),
                'pre_fingerprint'                  => (string) ($release['pre_fingerprint'] ?? ''),
                'post_fingerprint'                 => (string) ($release['post_fingerprint'] ?? ''),
                'released_at_utc'                  => self::nullableString($release['released_at_utc'] ?? null),
                'reason_codes'                     => self::codes($release['reason_codes'] ?? []),
            ],
            'history'                => [
                'related_orders'      => (int) ($history['related_orders'] ?? 0),
                'paid_orders'         => (int) ($history['paid_orders'] ?? 0),
                'linked_transactions' => (int) ($history['linked_transactions'] ?? 0),
                'reconciled'          => (bool) ($history['reconciled'] ?? false),
                'reason_codes'        => self::codes($history['reason_codes'] ?? []),
            ],
        ];

        ksort($entry);

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function withEntry(array $entry): self
    {
        $normalised = self::entry($entry);

        $entries = [];
        $replaced = false;

        foreach ($this->entries as $existing) {
            if (($existing['source_ref'] ?? '') === $normalised['source_ref']) {
                $entries[] = $normalised;
                $replaced  = true;

                continue;
            }

            $entries[] = $existing;
        }

        if (!$replaced) {
            $entries[] = $normalised;
        }

        return $this->withEntries($entries);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    public function withEntries(array $entries): self
    {
        $normalised = array_map(self::entry(...), array_values($entries));

        // Sorted here rather than at write time, so `payloadChecksum()` cannot
        // depend on the order somebody happened to add entries in. Two runs of
        // the same cohort produce the same checksum or the checksum is useless.
        usort(
            $normalised,
            static fn (array $a, array $b): int => [$a['source_subscription_id'], $a['source_ref']]
                <=> [$b['source_subscription_id'], $b['source_ref']],
        );

        return new self(
            $this->state,
            $this->sourceKey,
            $this->packageChecksum,
            $this->selectionFingerprint,
            $this->mappingFingerprint,
            $this->targetSettingsFingerprint,
            $this->approvedSettingsFingerprint,
            $normalised,
            $this->createdAtUtc,
            gmdate('Y-m-d H:i:s'),
            $this->renewalMaintenance,
            $this->selection,
        );
    }

    public function withState(string $state): self
    {
        return new self(
            $state,
            $this->sourceKey,
            $this->packageChecksum,
            $this->selectionFingerprint,
            $this->mappingFingerprint,
            $this->targetSettingsFingerprint,
            $this->approvedSettingsFingerprint,
            $this->entries,
            $this->createdAtUtc,
            gmdate('Y-m-d H:i:s'),
            $this->renewalMaintenance,
            $this->selection,
        );
    }

    /**
     * The operator's statement that source renewal workers are paused.
     *
     * Recorded, timestamped, and load-bearing for nothing else: the flag never
     * pauses a worker and this method never pretends it did. See
     * `SubscriptionCutover::cutoverSource()`.
     */
    public function withRenewalMaintenanceAcknowledged(): self
    {
        return new self(
            $this->state,
            $this->sourceKey,
            $this->packageChecksum,
            $this->selectionFingerprint,
            $this->mappingFingerprint,
            $this->targetSettingsFingerprint,
            $this->approvedSettingsFingerprint,
            $this->entries,
            $this->createdAtUtc,
            gmdate('Y-m-d H:i:s'),
            ['acknowledged' => true, 'acknowledged_at_utc' => gmdate('Y-m-d H:i:s')],
            $this->selection,
        );
    }

    public function withApprovedSettingsFingerprint(string $fingerprint): self
    {
        return new self(
            $this->state,
            $this->sourceKey,
            $this->packageChecksum,
            $this->selectionFingerprint,
            $this->mappingFingerprint,
            $this->targetSettingsFingerprint,
            $fingerprint,
            $this->entries,
            $this->createdAtUtc,
            gmdate('Y-m-d H:i:s'),
            $this->renewalMaintenance,
            $this->selection,
        );
    }

    /**
     * The exact cohort definition carried by this receipt.
     *
     * Receipts written before the definition was added represented the whole
     * source by construction, so absence deliberately means `all`.
     */
    public function selection(): SubscriptionSelection
    {
        return $this->selection === []
            ? SubscriptionSelection::all($this->sourceKey)
            : SubscriptionSelection::fromArray($this->selection, $this->sourceKey);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entryFor(string $sourceRef): ?array
    {
        foreach ($this->entries as $entry) {
            if (($entry['source_ref'] ?? '') === $sourceRef) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Entries the cutover is actually about.
     *
     * @return list<array<string, mixed>>
     */
    public function participating(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => CutoverEntryState::of($entry)->participates(),
        ));
    }

    /**
     * Whether this entry is out of the cutover, and stays out.
     *
     * Delegated, like every other question about an entry. See
     * `CutoverEntryState::isHeld()` for the two reasons and why they are
     * different sentences.
     *
     * @param array<string, mixed> $entry
     */
    public static function isHeld(array $entry): bool
    {
        return CutoverEntryState::of($entry)->isHeld();
    }

    public function hasEntryAtOrBeyond(string $state): bool
    {
        $rank = self::RANKS[$state] ?? PHP_INT_MAX;

        foreach ($this->entries as $entry) {
            if ((self::RANKS[(string) ($entry['state'] ?? '')] ?? -1) >= $rank) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────
    // The state machine
    // ──────────────────────────────────────────────

    /**
     * Why this transition may not happen, or an empty list.
     *
     * `$current` carries whichever fingerprints this runtime can compute. A key
     * that is absent is not checked, because the WooCommerce runtime has no
     * FluentCart mapping set and no FluentCart settings — demanding all four
     * everywhere would make `cutover-source` impossible to run where it belongs.
     * A key that is present is always checked.
     *
     * @param array<string, string> $current
     * @return list<array{code: string, message: string}>
     */
    public function transitionFailures(string $to, array $current): array
    {
        $failures = self::structuralFailures($this->state, $to, $this->entries);

        foreach (self::fingerprintChecks() as $key => $check) {
            if (!array_key_exists($key, $current)) {
                continue;
            }

            $expected = $this->{$check['property']};

            if ($expected === '' && (string) $current[$key] === '') {
                continue;
            }

            if (!hash_equals($expected, (string) $current[$key])) {
                $failures[] = [
                    'code'    => $check['code'],
                    'message' => sprintf($check['message'], $expected, (string) $current[$key]),
                ];
            }
        }

        // Section 11's second sentence, enforced rather than described: no
        // target subscription is activated before its source automatic owner is
        // disabled or explicitly transferred.
        if ($to === self::STATE_ACTIVATED) {
            foreach ($this->participating() as $entry) {
                $entryState = CutoverEntryState::of($entry);

                // An UNKNOWN source is checked before a merely unsatisfied one,
                // because it is the case that reads as satisfied: a restoration
                // that started and never recorded its outcome leaves the release
                // state saying `released` while the source may well be automatic
                // again. Activating on that is the one sequence that puts both
                // systems on the same customer.
                if ($entryState->sourceStateIsUnknown()) {
                    $failures[] = [
                        'code'    => self::REASON_SOURCE_RELEASE_UNVERIFIED,
                        'message' => sprintf(
                            'Subscription %s has a restoration that was started and never finished, so '
                            . 'nothing here knows whether its source is manual or automatic right now. '
                            . 'Nothing was activated. Run `restore-source` again to re-establish it, or '
                            . '`cutover-source` if you are going forward — either will verify the source '
                            . 'and say what it found.',
                            $entryState->sourceRef,
                        ),
                    ];

                    continue;
                }

                if ($entryState->releaseSatisfied()) {
                    continue;
                }

                $failures[] = [
                    'code'    => self::REASON_SOURCE_RELEASE_UNVERIFIED,
                    'message' => sprintf(
                        'Subscription %s has not released its source (%s), so activating it would leave '
                        . 'two systems able to charge the same person. Nothing was activated.',
                        $entryState->sourceRef,
                        $entryState->releaseState,
                    ),
                ];
            }
        }

        return $failures;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array{code: string, message: string}>
     */
    private static function structuralFailures(string $from, string $to, array $entries): array
    {
        if (!isset(self::RANKS[$to])) {
            return [[
                'code'    => self::REASON_TRANSITION_INVALID,
                'message' => sprintf('"%s" is not a receipt state.', $to),
            ]];
        }

        // A STATE THAT WOULD REBUILD ENTRIES CANNOT RUN OVER A RELEASED SOURCE.
        //
        // `stage` rewrites every entry from the dataset — state `staged`,
        // release `pending`, no `previous_requires_manual_renewal`. Run it on a
        // cohort where `cutover-source` released some subscriptions and then
        // stopped on one, and the header is still `staged` (the advance is
        // skipped when anything failed), so `staged -> staged` reads as a
        // same-state no-op and proceeds. Each released entry silently goes
        // backwards to `pending`, its previous manual flag is gone, and
        // `restore-source` then skips it. The subscriber's automatic renewal is
        // off permanently with no record it was ever on.
        //
        // The header alone cannot see that, which is why this reads the ENTRIES.
        // Checked before the rank rules so it catches the no-op too.
        //
        // AND IT DISCRIMINATES ON THE ENTRY'S OWN STATE, NOT ON THE RELEASE
        // VOCABULARY. Asking `source_release.state` for this was reopenable and
        // was reopened: `restore-source` overwrites that field with `blocked`
        // when the guard refuses, and the guard refuses BEFORE mutating on
        // fingerprint drift — so the source stayed manual while the entry
        // stopped saying `released`, and the next `stage` walked straight
        // through. The entry's own state does not have that property: a
        // released entry is at `source_released` and every branch that touches
        // it afterwards preserves it.
        if (in_array($to, [self::STATE_ASSESSED, self::STATE_STAGED], true)) {
            foreach ($entries as $entry) {
                if (!self::isReleasedSource($entry)) {
                    continue;
                }

                return [[
                    'code'    => self::REASON_TRANSITION_INVALID,
                    'message' => sprintf(
                        'Subscription %s has already had its source released (%s), and staging would rewrite '
                        . 'that entry — losing the previous manual-renewal flag, which is the only record of '
                        . 'what this source was before CartShift touched it. Nothing was done. To re-stage '
                        . 'this cohort, run `restore-source` first and let it finish: a fully restored receipt '
                        . 'may be staged again. To carry on instead, fix what stopped the cutover and run '
                        . '`cutover-source` again.',
                        (string) ($entry['source_ref'] ?? '?'),
                        (string) (((array) ($entry['source_release'] ?? []))['state'] ?? 'unknown'),
                    ),
                ]];
            }
        }

        // A FULLY RESTORED COHORT MAY BE STAGED AGAIN, DELIBERATELY.
        //
        // This is the repair route for a record whose history did not reconcile
        // after a cutover has already run, and without it the documented remedy
        // — "fix the history and re-run `stage`" — is a dead end: `stage` is
        // refused from `source_restored` by the rank rules below, so a held
        // subscriber in a cut-over cohort would have no in-tool way back.
        //
        // It is safe because the guard above has already run: a partial
        // restoration still has entries at `source_released` and is refused
        // there. Reaching this line means every source is back under its own
        // ownership and every destination is paused, which is exactly the state
        // the first `stage` ran in.
        if ($from === self::STATE_SOURCE_RESTORED && $to === self::STATE_STAGED) {
            return [];
        }

        if ($to === self::STATE_SOURCE_RESTORED) {
            // `staged` is here beside the two obvious predecessors, and it is
            // the partially-failed cutover again from the other side. That run
            // left real sources manual and the header at `staged`; refusing to
            // roll it back would make `restore-source` unavailable in precisely
            // the case it exists for. It is still refused when nothing was
            // released, because then there genuinely is nothing to put back.
            $restorable = in_array($from, [self::STATE_SOURCE_RELEASED, self::STATE_SOURCE_RESTORED], true)
                || ($from === self::STATE_STAGED && self::anyReleasedSource($entries));

            if (!$restorable) {
                return [[
                    'code'    => self::REASON_TRANSITION_INVALID,
                    'message' => sprintf(
                        'A source can only be restored once one has been released; this receipt is "%s" and '
                        . 'no entry records a release. There is nothing to put back.',
                        $from,
                    ),
                ]];
            }

            foreach ($entries as $entry) {
                if ((self::RANKS[(string) ($entry['state'] ?? '')] ?? -1) >= self::RANKS[self::STATE_ACTIVATED]) {
                    return [[
                        'code'    => self::REASON_TRANSITION_INVALID,
                        'message' => sprintf(
                            'Subscription %s is already activated on the target, so restoring the source '
                            . 'would leave both able to charge. Nothing was restored.',
                            (string) ($entry['source_ref'] ?? '?'),
                        ),
                    ]];
                }
            }

            return [];
        }

        // Repeating a completed transition: a no-op after revalidation.
        if ($to === $from) {
            return [];
        }

        if ((self::RANKS[$to] - (self::RANKS[$from] ?? -99)) === 1) {
            return [];
        }

        return [[
            'code'    => self::REASON_TRANSITION_INVALID,
            'message' => sprintf(
                'A receipt at "%s" cannot move to "%s". The states are fixed and monotonic: %s.',
                $from,
                $to,
                'assessed -> staged -> source_released -> activated -> reconciled',
            ),
        ]];
    }

    /**
     * Whether CartShift has written to this entry's WooCommerce record.
     *
     * Delegated to the one authority. Two earlier answers lived here and both
     * were wrong in a way that cost a review round: the release vocabulary,
     * which a failure branch can clear, and the entry's state rank, which counts
     * a terminal record that was never touched and misses a post-save drift
     * block that was. `CutoverEntryState::sourceWasMutated()` reads the fact
     * itself.
     *
     * @param array<string, mixed> $entry
     */
    private static function isReleasedSource(array $entry): bool
    {
        return CutoverEntryState::of($entry)->sourceWasMutated();
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private static function anyReleasedSource(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (self::isReleasedSource($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{property: string, code: string, message: string}>
     */
    private static function fingerprintChecks(): array
    {
        return [
            'package_checksum' => [
                'property' => 'packageChecksum',
                'code'     => self::REASON_PACKAGE_CHECKSUM_MISMATCH,
                'message'  => 'The package this receipt was written from checksummed %s and now checksums '
                    . '%s. Something replaced the file; nothing was done.',
            ],
            // Worth being honest about what this one can and cannot detect.
            // `SubscriptionSelection::all()` is derived from the source key
            // alone, so for a whole-cohort run this compares source keys and
            // nothing else. It is kept because a narrowed selection WOULD move
            // it — but the real protection against a source that changed is the
            // per-subscription fingerprint `cutover-source` re-derives, and the
            // message must not claim more than it knows.
            'selection_fingerprint' => [
                'property' => 'selectionFingerprint',
                'code'     => self::REASON_SOURCE_FINGERPRINT_CHANGED,
                'message'  => 'This receipt was written for selection %s and this run computes %s. Most '
                    . 'often that means --source-key does not match the one the package was exported under. '
                    . 'Nothing was done: check the key before assuming the source changed.',
            ],
            'mapping_fingerprint' => [
                'property' => 'mappingFingerprint',
                'code'     => self::REASON_TRANSITION_INVALID,
                'message'  => 'The mapping set was %s when this receipt was written and is now %s. A '
                    . 'mapping decision changed after staging; re-run the audit and start again.',
            ],
            'target_settings_fingerprint' => [
                'property' => 'targetSettingsFingerprint',
                'code'     => self::REASON_SETTINGS_NOT_APPROVED,
                'message'  => 'The target subscription settings fingerprinted %s when this cohort was '
                    . 'assessed and now fingerprint %s. The approval was given for the old ones. CartShift '
                    . 'changes no FluentCart setting: review the store and audit again.',
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // Serialisation
    // ──────────────────────────────────────────────

    /**
     * SHA-256 over the canonical entries. The receipt's own integrity marker.
     *
     * Taken over the entry lines and never over a header that contains it,
     * which is the same rule the package uses and for the same reason.
     */
    public function payloadChecksum(): string
    {
        return hash('sha256', implode("\n", $this->lines()));
    }

    /**
     * @return array<string, mixed>
     */
    public function header(): array
    {
        $header = [
            'approved_settings_fingerprint' => $this->approvedSettingsFingerprint,
            'created_at_utc'                => $this->createdAtUtc,
            'entry_count'                   => count($this->entries),
            'mapping_fingerprint'           => $this->mappingFingerprint,
            'package_checksum'              => $this->packageChecksum,
            'payload_checksum'              => $this->payloadChecksum(),
            'renewal_maintenance'           => $this->renewalMaintenance,
            'schema_version'                => self::SCHEMA_VERSION,
            'selection_fingerprint'         => $this->selectionFingerprint,
            'source_key'                    => $this->sourceKey,
            'state'                         => $this->state,
            'target_settings_fingerprint'   => $this->targetSettingsFingerprint,
            'updated_at_utc'                => $this->updatedAtUtc,
        ];

        if ($this->selection !== []) {
            $header['selection'] = $this->selection;
        }

        return $header;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['header' => $this->header(), 'entries' => $this->entries];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        $header  = (array) ($document['header'] ?? []);
        $entries = array_map(self::entry(...), array_values((array) ($document['entries'] ?? [])));

        return new self(
            (string) ($header['state'] ?? self::STATE_ASSESSED),
            (string) ($header['source_key'] ?? ''),
            (string) ($header['package_checksum'] ?? ''),
            (string) ($header['selection_fingerprint'] ?? ''),
            (string) ($header['mapping_fingerprint'] ?? ''),
            (string) ($header['target_settings_fingerprint'] ?? ''),
            (string) ($header['approved_settings_fingerprint'] ?? ''),
            $entries,
            (string) ($header['created_at_utc'] ?? ''),
            (string) ($header['updated_at_utc'] ?? ''),
            (array) ($header['renewal_maintenance'] ?? ['acknowledged' => false, 'acknowledged_at_utc' => null]),
            (array) ($header['selection'] ?? []),
        );
    }

    /**
     * Write the receipt where a web server will never serve it.
     *
     * The same `PackagePath` rules the package uses, and for a stronger reason:
     * a receipt names every subscription in the cohort, its destination ID, and
     * whether its source has been disabled. A copy in the Media Library is a
     * map of who can currently be charged and by whom.
     *
     * @return array{path: string|null, failures: list<string>}
     */
    public function write(string $path): array
    {
        $resolved = PackagePath::resolveForWrite($path);

        if ($resolved['path'] === null) {
            return $resolved;
        }

        $header = SubscriptionRecordFactory::canonicalJson($this->header());
        $body   = $header . "\n" . implode("\n", $this->lines()) . "\n";

        // Created empty and locked down BEFORE any content arrives: writing
        // first and chmod-ing after leaves a window in which the file is
        // world-readable, which on a shared host is the whole exposure.
        if (@file_put_contents($resolved['path'], '') === false) {
            return ['path' => null, 'failures' => [self::REASON_WRITE_FAILED]];
        }

        @chmod($resolved['path'], PackagePath::PRIVATE_MODE);

        if (@file_put_contents($resolved['path'], $body) === false) {
            return ['path' => null, 'failures' => [self::REASON_WRITE_FAILED]];
        }

        return ['path' => $resolved['path'], 'failures' => []];
    }

    /**
     * @return array{receipt: self|null, path: string|null, failures: list<string>}
     */
    public static function read(string $path): array
    {
        $resolved = PackagePath::resolveForRead($path);

        if ($resolved['path'] === null) {
            return ['receipt' => null, 'path' => null, 'failures' => $resolved['failures']];
        }

        $raw = @file_get_contents($resolved['path']);

        if ($raw === false || trim($raw) === '') {
            return ['receipt' => null, 'path' => $resolved['path'], 'failures' => [self::REASON_UNREADABLE]];
        }

        $lines = array_values(array_filter(
            explode("\n", $raw),
            static fn (string $line): bool => trim($line) !== '',
        ));

        $header = json_decode((string) array_shift($lines), true);

        if (!is_array($header)) {
            return ['receipt' => null, 'path' => $resolved['path'], 'failures' => [self::REASON_UNREADABLE]];
        }

        $entries = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);

            if (!is_array($entry)) {
                return ['receipt' => null, 'path' => $resolved['path'], 'failures' => [self::REASON_UNREADABLE]];
            }

            $entries[] = $entry;
        }

        $receipt = self::fromArray(['header' => $header, 'entries' => $entries]);

        if (array_key_exists('selection', $header)) {
            $selection = $receipt->selection();

            if (
                !hash_equals($receipt->sourceKey, $selection->sourceKey)
                || !hash_equals($receipt->selectionFingerprint, $selection->fingerprint())
            ) {
                return [
                    'receipt'  => null,
                    'path'     => $resolved['path'],
                    'failures' => [self::REASON_CHECKSUM_MISMATCH],
                ];
            }

            foreach ($receipt->entries as $entry) {
                if ($selection->includes(
                    (int) ($entry['source_subscription_id'] ?? 0),
                    (string) ($entry['source_status'] ?? ''),
                )) {
                    continue;
                }

                return [
                    'receipt'  => null,
                    'path'     => $resolved['path'],
                    'failures' => [self::REASON_CHECKSUM_MISMATCH],
                ];
            }
        }

        // Recomputed rather than believed. A receipt is edited by hand more
        // often than anyone admits — usually to "just fix" a state — and a
        // hand-edited state is exactly what the machine exists to refuse.
        if (!hash_equals((string) ($header['payload_checksum'] ?? ''), $receipt->payloadChecksum())) {
            return [
                'receipt'  => null,
                'path'     => $resolved['path'],
                'failures' => [self::REASON_CHECKSUM_MISMATCH],
            ];
        }

        return ['receipt' => $receipt, 'path' => $resolved['path'], 'failures' => []];
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        return array_map(
            static fn (array $entry): string => SubscriptionRecordFactory::canonicalJson($entry),
            $this->entries,
        );
    }

    // ──────────────────────────────────────────────
    // Normalisation
    // ──────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private static function codes(mixed $value): array
    {
        $codes = array_values(array_unique(array_map(
            static fn (mixed $code): string => (string) $code,
            array_filter((array) $value, static fn (mixed $code): bool => is_scalar($code)),
        )));

        sort($codes);

        return $codes;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
