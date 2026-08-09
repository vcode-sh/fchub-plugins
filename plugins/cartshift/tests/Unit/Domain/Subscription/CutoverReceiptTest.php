<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\CutoverReceipt;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The receipt state and failure matrix — plan section 11's five phases as one
 * table, written before a single line of source-mutating code exists.
 *
 * The machine is monotonic and it is the only thing standing between "the
 * source still bills" and "the destination bills too". Every assertion here is
 * therefore a refusal: a state skipped, a state reversed, a checksum that moved,
 * an approval bound to settings that are no longer the settings, a target
 * activated while its source is still automatic. The one positive assertion —
 * that repeating a completed transition is a no-op — matters for the same
 * reason: an operator who reruns a command after a timeout must not be told the
 * cutover is broken.
 */
final class CutoverReceiptTest extends PluginTestCase
{
    private const string PACKAGE_CHECKSUM = 'a1b2c3';
    private const string SELECTION = 'sel-fingerprint';
    private const string MAPPING = 'map-fingerprint';
    private const string SETTINGS = 'settings-fingerprint';

    private string $workspace;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = realpath(sys_get_temp_dir()) . '/cartshift-receipt-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ((array) glob($this->workspace . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // The forward path
    // ──────────────────────────────────────────────

    public function testANewReceiptStartsAssessed(): void
    {
        $this->assertSame(CutoverReceipt::STATE_ASSESSED, $this->receipt()->state);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function forwardTransitions(): array
    {
        return [
            [CutoverReceipt::STATE_ASSESSED, CutoverReceipt::STATE_STAGED],
            [CutoverReceipt::STATE_STAGED, CutoverReceipt::STATE_SOURCE_RELEASED],
            [CutoverReceipt::STATE_SOURCE_RELEASED, CutoverReceipt::STATE_ACTIVATED],
            [CutoverReceipt::STATE_ACTIVATED, CutoverReceipt::STATE_RECONCILED],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forwardTransitions')]
    public function testEachForwardStepIsAllowedFromItsPredecessor(string $from, string $to): void
    {
        $receipt = $this->receipt()->withState($from)->withEntry($this->entryFor($to));

        $this->assertSame([], $receipt->transitionFailures($to, $this->context()));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forwardTransitions')]
    public function testRepeatingACompletedTransitionIsANoOp(string $from, string $to): void
    {
        $receipt = $this->receipt()->withState($to)->withEntry($this->entryFor($to));

        $this->assertSame([], $receipt->transitionFailures($to, $this->context()));
    }

    /**
     * Staging rewrites entries from the dataset, so a cohort in which any
     * source has actually been released cannot be staged again — not even
     * through the same-state no-op, which is the door a partially failed
     * `cutover-source` leaves open. Losing `previous_requires_manual_renewal`
     * means the subscriber's automatic renewal is off permanently with no
     * record it was ever on.
     */
    public function testStagingIsRefusedOnceAnySourceHasActuallyBeenReleased(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_STAGED)
            ->withEntry($this->entry('subscription:910001'))
            ->withEntry($this->releasedEntry('subscription:910002'));

        $this->assertSame(
            [CutoverReceipt::REASON_TRANSITION_INVALID],
            $this->codes($receipt->transitionFailures(CutoverReceipt::STATE_STAGED, $this->context())),
        );
    }

    /**
     * A terminal record travels to `source_released` with the cohort — the
     * release short-circuit promotes it without touching WooCommerce — and is
     * STILL re-stageable, because nothing was written for it.
     *
     * The fixture carries that promoted state deliberately. An earlier version
     * of this test defaulted to `assessed`, so it passed under a rank-based
     * guard that in fact locked any cohort containing one terminal record out of
     * re-staging for ever. The name promised a guarantee the code had withdrawn.
     */
    public function testStagingIsStillAllowedOverATerminalRecordThatTravelledWithTheCohort(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_STAGED)
            ->withEntry($this->entry('subscription:910003', [
                'terminal'       => true,
                'state'          => CutoverReceipt::STATE_SOURCE_RELEASED,
                'source_release' => [
                    'required'       => false,
                    'state'          => CutoverReceipt::RELEASE_NOT_REQUIRED,
                    'source_mutated' => false,
                ],
            ]));

        $this->assertSame([], $receipt->transitionFailures(CutoverReceipt::STATE_STAGED, $this->context()));
    }

    /**
     * And the case a rank guard misses in the other direction: a post-save drift
     * block leaves the entry at `staged`, because the release never completed —
     * while WooCommerce has already been set to manual renewal.
     *
     * Nothing but the mutation fact tells these two apart, and this is the test
     * that pins it: the two entries below are opposites on the one question and
     * agree on everything a rank or a release state could see.
     */
    public function testStagingIsRefusedOverASourceMutatedByARunThatThenStopped(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_STAGED)
            ->withEntry($this->entry('subscription:910004', [
                'state'          => CutoverReceipt::STATE_STAGED,
                'source_release' => [
                    'required'       => true,
                    'state'          => CutoverReceipt::RELEASE_BLOCKED,
                    'source_mutated' => true,
                    'previous_requires_manual_renewal' => false,
                ],
            ]));

        $this->assertSame(
            [CutoverReceipt::REASON_TRANSITION_INVALID],
            $this->codes($receipt->transitionFailures(CutoverReceipt::STATE_STAGED, $this->context())),
        );

        // And it is restorable, so the rollback route is not lost either.
        $this->assertSame([], $receipt->transitionFailures(
            CutoverReceipt::STATE_SOURCE_RESTORED,
            ['selection_fingerprint' => self::SELECTION],
        ));
    }

    /**
     * A refusal that happened BEFORE anything was written is not a mutation, so
     * it does not lock the cohort. Same release state as the test above, and the
     * opposite answer.
     */
    public function testStagingIsAllowedOverASourceThatWasRefusedBeforeAnythingWasWritten(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_STAGED)
            ->withEntry($this->entry('subscription:910005', [
                'state'          => CutoverReceipt::STATE_STAGED,
                'source_release' => [
                    'required'       => true,
                    'state'          => CutoverReceipt::RELEASE_BLOCKED,
                    'source_mutated' => false,
                ],
            ]));

        $this->assertSame([], $receipt->transitionFailures(CutoverReceipt::STATE_STAGED, $this->context()));
    }

    /**
     * The other half of the partially-failed cutover. Some sources are manual,
     * the header never advanced, and `restore-source` is the plan's rollback
     * route — so it has to be available in exactly that case.
     */
    public function testRestorationIsAllowedFromStagedWhenSomeSourceWasAlreadyReleased(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_STAGED)
            ->withEntry($this->entry('subscription:910001'))
            ->withEntry($this->releasedEntry('subscription:910002'));

        $this->assertSame([], $receipt->transitionFailures(
            CutoverReceipt::STATE_SOURCE_RESTORED,
            ['selection_fingerprint' => self::SELECTION],
        ));
    }

    // ──────────────────────────────────────────────
    // A restoration that started and never came back
    // ──────────────────────────────────────────────

    /**
     * The state that used to be inexpressible, and the one sequence that could
     * put both systems on the same customer.
     *
     * A restoration that hands the source back and then fails to record the
     * outcome leaves an entry whose release state still reads `released` —
     * which `releaseSatisfied()` accepts — while that source is automatic
     * again. Activating on it is two systems billing one person.
     */
    public function testAnUnfinishedRestorationBlocksActivation(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_SOURCE_RELEASED)
            ->withEntry($this->entry('subscription:910006', [
                'state'          => CutoverReceipt::STATE_SOURCE_RELEASED,
                'source_release' => [
                    'required'              => true,
                    'state'                 => CutoverReceipt::RELEASE_RELEASED,
                    'source_mutated'        => true,
                    'restore_intent_at_utc' => '2026-08-09 12:00:00',
                ],
            ]));

        $this->assertSame(
            [CutoverReceipt::REASON_SOURCE_RELEASE_UNVERIFIED],
            $this->codes($receipt->transitionFailures(CutoverReceipt::STATE_ACTIVATED, $this->context())),
        );
    }

    /**
     * And it is not stageable either — the naive symmetric fix's failure mode,
     * where the entry would have read as restored-and-done and `stage` would
     * have rebuilt a source nobody had verified.
     */
    public function testAnUnfinishedRestorationIsNeitherStageableNorForgotten(): void
    {
        $entry = $this->entry('subscription:910007', [
            'state'          => CutoverReceipt::STATE_SOURCE_RELEASED,
            'source_release' => [
                'required'              => true,
                'state'                 => CutoverReceipt::RELEASE_RELEASED,
                // Even with the mutation fact already cleared — the guard
                // succeeded, only the write did not — the intent keeps it out.
                'source_mutated'        => false,
                'restore_intent_at_utc' => '2026-08-09 12:00:00',
            ],
        ]);

        $receipt = $this->receipt()->withState(CutoverReceipt::STATE_STAGED)->withEntry($entry);

        $this->assertSame(
            [CutoverReceipt::REASON_TRANSITION_INVALID],
            $this->codes($receipt->transitionFailures(CutoverReceipt::STATE_STAGED, $this->context())),
        );

        // And the rollback can still reach it, which is how it gets resolved.
        $this->assertSame([], $receipt->transitionFailures(
            CutoverReceipt::STATE_SOURCE_RESTORED,
            ['selection_fingerprint' => self::SELECTION],
        ));
    }

    // ──────────────────────────────────────────────
    // History
    // ──────────────────────────────────────────────

    /**
     * A destination row whose history did not reconcile carries an unverified
     * bill count, so it takes no part in the cutover: nothing demands its
     * source be released, and nothing may activate it.
     */
    public function testAnUnreconciledEntryIsHeldOutOfTheCutover(): void
    {
        $entry = $this->entry('subscription:910004', ['history' => ['reconciled' => false]]);

        $this->assertTrue(CutoverReceipt::isHeld($entry));

        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_SOURCE_RELEASED)
            ->withEntry($this->releasedEntry('subscription:910001'))
            ->withEntry($entry);

        $this->assertSame([], $receipt->transitionFailures(CutoverReceipt::STATE_ACTIVATED, $this->context()));
        $this->assertCount(1, $receipt->participating());
    }

    public function testAReconciledEntryParticipatesNormally(): void
    {
        $this->assertFalse(CutoverReceipt::isHeld($this->entry()));
    }

    /**
     * "No history recorded" is not "history disagreed". An entry that never got
     * a destination row is blocked, and its reason codes say why.
     */
    public function testAnEntryWithNoDestinationRowIsNotHeldForItsHistory(): void
    {
        $this->assertFalse(CutoverReceipt::isHeld(CutoverReceipt::entry([
            'source_ref'             => 'subscription:910005',
            'outcome'                => CutoverReceipt::OUTCOME_READY,
            'target_subscription_id' => null,
        ])));
    }

    // ──────────────────────────────────────────────
    // Skips and reversals
    // ──────────────────────────────────────────────

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function refusedTransitions(): array
    {
        return [
            // Skips.
            'stage straight to release'    => [CutoverReceipt::STATE_ASSESSED, CutoverReceipt::STATE_SOURCE_RELEASED],
            'stage straight to activate'  => [CutoverReceipt::STATE_ASSESSED, CutoverReceipt::STATE_ACTIVATED],
            'staged straight to activate' => [CutoverReceipt::STATE_STAGED, CutoverReceipt::STATE_ACTIVATED],
            'released straight to done'   => [CutoverReceipt::STATE_SOURCE_RELEASED, CutoverReceipt::STATE_RECONCILED],
            // Reversals.
            'activated back to staged'    => [CutoverReceipt::STATE_ACTIVATED, CutoverReceipt::STATE_STAGED],
            'released back to staged'     => [CutoverReceipt::STATE_SOURCE_RELEASED, CutoverReceipt::STATE_STAGED],
            'reconciled back to activate' => [CutoverReceipt::STATE_RECONCILED, CutoverReceipt::STATE_ACTIVATED],
            // Nonsense.
            'unknown target'              => [CutoverReceipt::STATE_STAGED, 'nearly_there'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedTransitions')]
    public function testASkippedOrReversedTransitionIsRefused(string $from, string $to): void
    {
        $receipt = $this->receipt()->withState($from)->withEntry($this->releasedEntry());

        $this->assertSame(
            [CutoverReceipt::REASON_TRANSITION_INVALID],
            $this->codes($receipt->transitionFailures($to, $this->context())),
        );
    }

    // ──────────────────────────────────────────────
    // Fingerprints
    // ──────────────────────────────────────────────

    public function testAMovedPackageChecksumRefusesEveryTransition(): void
    {
        $receipt = $this->receipt()->withState(CutoverReceipt::STATE_ASSESSED);

        $failures = $receipt->transitionFailures(
            CutoverReceipt::STATE_STAGED,
            ['package_checksum' => 'something-else'] + $this->context(),
        );

        $this->assertContains(CutoverReceipt::REASON_PACKAGE_CHECKSUM_MISMATCH, $this->codes($failures));
    }

    public function testAMovedSelectionFingerprintRefusesEveryTransition(): void
    {
        $receipt = $this->receipt()->withState(CutoverReceipt::STATE_ASSESSED);

        $failures = $receipt->transitionFailures(
            CutoverReceipt::STATE_STAGED,
            ['selection_fingerprint' => 'moved'] + $this->context(),
        );

        $this->assertContains(CutoverReceipt::REASON_SOURCE_FINGERPRINT_CHANGED, $this->codes($failures));
    }

    /**
     * Obligation: the audit screen publishes `mapping.fingerprint` and nothing
     * consumed it. The receipt binds it, and a mapping decision changed after
     * staging invalidates every later transition.
     */
    public function testAMovedMappingFingerprintRefusesEveryTransition(): void
    {
        $receipt = $this->receipt()->withState(CutoverReceipt::STATE_STAGED)->withEntry($this->releasedEntry());

        $failures = $receipt->transitionFailures(
            CutoverReceipt::STATE_SOURCE_RELEASED,
            ['mapping_fingerprint' => 'remapped'] + $this->context(),
        );

        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $this->codes($failures));
    }

    public function testAMovedTargetSettingsFingerprintRefusesEveryTransition(): void
    {
        $receipt = $this->receipt()->withState(CutoverReceipt::STATE_SOURCE_RELEASED)
            ->withEntry($this->releasedEntry());

        $failures = $receipt->transitionFailures(
            CutoverReceipt::STATE_ACTIVATED,
            ['target_settings_fingerprint' => 'the-owner-changed-a-setting'] + $this->context(),
        );

        $this->assertContains(CutoverReceipt::REASON_SETTINGS_NOT_APPROVED, $this->codes($failures));
    }

    /**
     * The source runtime has no FluentCart, so it cannot recompute a mapping-set
     * or subscription-settings fingerprint. Absent keys are not checked; present
     * ones always are. A guard that demanded all four everywhere would make
     * `cutover-source` impossible to run in the runtime it belongs to.
     */
    public function testAContextMayOmitTheFingerprintsItsRuntimeCannotCompute(): void
    {
        $receipt = $this->receipt()->withState(CutoverReceipt::STATE_STAGED)->withEntry($this->releasedEntry());

        $this->assertSame([], $receipt->transitionFailures(
            CutoverReceipt::STATE_SOURCE_RELEASED,
            ['selection_fingerprint' => self::SELECTION],
        ));
    }

    // ──────────────────────────────────────────────
    // Source ownership
    // ──────────────────────────────────────────────

    public function testActivationIsRefusedWhileAnyParticipatingEntryHasNotReleasedItsSource(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_SOURCE_RELEASED)
            ->withEntry($this->releasedEntry('subscription:910001'))
            ->withEntry($this->entry('subscription:910002', [
                'source_release' => ['required' => true, 'state' => CutoverReceipt::RELEASE_PENDING],
            ]));

        $this->assertContains(
            CutoverReceipt::REASON_SOURCE_RELEASE_UNVERIFIED,
            $this->codes($receipt->transitionFailures(CutoverReceipt::STATE_ACTIVATED, $this->context())),
        );
    }

    public function testActivationIgnoresBlockedEntriesWhichWereNeverStaged(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_SOURCE_RELEASED)
            ->withEntry($this->releasedEntry('subscription:910001'))
            ->withEntry($this->entry('subscription:910002', [
                'outcome'        => CutoverReceipt::OUTCOME_BLOCKED,
                'state'          => CutoverReceipt::STATE_ASSESSED,
                'source_release' => ['required' => false, 'state' => CutoverReceipt::RELEASE_NOT_REQUIRED],
            ]));

        $this->assertSame([], $receipt->transitionFailures(CutoverReceipt::STATE_ACTIVATED, $this->context()));
    }

    /**
     * A terminal historical record has no automatic owner to disable, so it
     * needs no release — but it must say so rather than simply not mentioning it.
     */
    public function testATerminalEntryNeedsNoReleaseToActivate(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_SOURCE_RELEASED)
            ->withEntry($this->entry('subscription:910003', [
                'terminal'       => true,
                'state'          => CutoverReceipt::STATE_SOURCE_RELEASED,
                'source_release' => ['required' => false, 'state' => CutoverReceipt::RELEASE_NOT_REQUIRED],
            ]));

        $this->assertSame([], $receipt->transitionFailures(CutoverReceipt::STATE_ACTIVATED, $this->context()));
    }

    // ──────────────────────────────────────────────
    // Restoration
    // ──────────────────────────────────────────────

    public function testRestorationIsAllowedFromSourceReleased(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_SOURCE_RELEASED)
            ->withEntry($this->releasedEntry());

        $this->assertSame([], $receipt->transitionFailures(
            CutoverReceipt::STATE_SOURCE_RESTORED,
            ['selection_fingerprint' => self::SELECTION],
        ));
    }

    public function testRestorationIsRefusedBeforeAnySourceWasReleased(): void
    {
        $receipt = $this->receipt()->withState(CutoverReceipt::STATE_STAGED);

        $this->assertSame(
            [CutoverReceipt::REASON_TRANSITION_INVALID],
            $this->codes($receipt->transitionFailures(CutoverReceipt::STATE_SOURCE_RESTORED, $this->context())),
        );
    }

    public function testRestorationIsRefusedOnceAnyTargetRecordReachedActivated(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_SOURCE_RELEASED)
            ->withEntry($this->releasedEntry('subscription:910001'))
            ->withEntry($this->entry('subscription:910002', [
                'state'          => CutoverReceipt::STATE_ACTIVATED,
                'source_release' => ['required' => true, 'state' => CutoverReceipt::RELEASE_RELEASED],
            ]));

        $this->assertSame(
            [CutoverReceipt::REASON_TRANSITION_INVALID],
            $this->codes($receipt->transitionFailures(CutoverReceipt::STATE_SOURCE_RESTORED, $this->context())),
        );
    }

    /**
     * After a restoration the source is authoritative again and the target is
     * still paused. Releasing again is the way forward; activating is a skip.
     */
    public function testARestoredReceiptMayReleaseAgainButMayNotActivate(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_SOURCE_RESTORED)
            ->withEntry($this->entry('subscription:910001', [
                'state'          => CutoverReceipt::STATE_SOURCE_RESTORED,
                'source_release' => ['required' => true, 'state' => CutoverReceipt::RELEASE_RESTORED],
            ]));

        $this->assertSame([], $receipt->transitionFailures(
            CutoverReceipt::STATE_SOURCE_RELEASED,
            ['selection_fingerprint' => self::SELECTION],
        ));

        // Two refusals, both correct: the step is a skip, and a restored source
        // is by definition automatic again.
        $codes = $this->codes($receipt->transitionFailures(CutoverReceipt::STATE_ACTIVATED, $this->context()));

        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $codes);
        $this->assertContains(CutoverReceipt::REASON_SOURCE_RELEASE_UNVERIFIED, $codes);
    }

    // ──────────────────────────────────────────────
    // The file
    // ──────────────────────────────────────────────

    public function testAReceiptRoundTripsThroughItsFile(): void
    {
        $receipt = $this->receipt()
            ->withState(CutoverReceipt::STATE_STAGED)
            ->withEntry($this->releasedEntry());

        $path = $this->workspace . '/receipt.ndjson';

        $this->assertSame([], $receipt->write($path)['failures']);

        $read = CutoverReceipt::read($path);

        $this->assertSame([], $read['failures']);
        $this->assertNotNull($read['receipt']);
        $this->assertSame($receipt->toArray(), $read['receipt']->toArray());
    }

    public function testTheEntryPayloadChecksumIsCanonicalAndOrderIndependent(): void
    {
        $one = $this->receipt()
            ->withEntry($this->entry('subscription:910001'))
            ->withEntry($this->entry('subscription:910002'));

        $two = $this->receipt()
            ->withEntry($this->entry('subscription:910002'))
            ->withEntry($this->entry('subscription:910001'));

        $this->assertSame($one->payloadChecksum(), $two->payloadChecksum());
    }

    public function testAnEditedReceiptIsRefusedRatherThanTrusted(): void
    {
        $path = $this->workspace . '/tampered.ndjson';

        $this->receipt()->withState(CutoverReceipt::STATE_STAGED)->withEntry($this->releasedEntry())->write($path);

        $lines = explode("\n", (string) file_get_contents($path));
        $lines[1] = str_replace('"target_subscription_id":4242', '"target_subscription_id":9999', $lines[1]);
        file_put_contents($path, implode("\n", $lines));

        $read = CutoverReceipt::read($path);

        $this->assertNull($read['receipt']);
        $this->assertContains(CutoverReceipt::REASON_CHECKSUM_MISMATCH, $read['failures']);
    }

    public function testAReceiptCarriesNoSecrets(): void
    {
        $receipt = $this->receipt()->withEntry($this->entry('subscription:910001', [
            // Everything a caller might carelessly hand over. None of it may
            // land in a file that travels between two machines by hand.
            'billing_email'      => 'subscriber@example.invalid',
            'vendor_customer_id' => 'cus_live_should_never_appear',
            'payment_references' => ['stripe_source_id' => 'pm_live_should_never_appear'],
        ]));

        $encoded = json_encode($receipt->toArray());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('example.invalid', $encoded);
        $this->assertStringNotContainsString('cus_live', $encoded);
        $this->assertStringNotContainsString('pm_live', $encoded);
    }

    public function testAReceiptRefusesToBeWrittenIntoAGitWorkingTree(): void
    {
        mkdir($this->workspace . '/repo/.git', 0700, true);

        $result = $this->receipt()->write($this->workspace . '/repo/receipt.ndjson');

        $this->assertNull($result['path']);
        $this->assertNotSame([], $result['failures']);

        foreach ((array) glob($this->workspace . '/repo/.git/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->workspace . '/repo/.git');
        @rmdir($this->workspace . '/repo');
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function receipt(): CutoverReceipt
    {
        return CutoverReceipt::begin(
            'lapka',
            self::PACKAGE_CHECKSUM,
            self::SELECTION,
            self::MAPPING,
            self::SETTINGS,
            self::SETTINGS,
        );
    }

    /**
     * @return array<string, string>
     */
    private function context(): array
    {
        return [
            'package_checksum'            => self::PACKAGE_CHECKSUM,
            'selection_fingerprint'       => self::SELECTION,
            'mapping_fingerprint'         => self::MAPPING,
            'target_settings_fingerprint' => self::SETTINGS,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function entry(string $sourceRef = 'subscription:910001', array $overrides = []): array
    {
        return CutoverReceipt::entry(array_merge([
            'source_ref'             => $sourceRef,
            'source_subscription_id' => (int) substr($sourceRef, strlen('subscription:')),
            'source_fingerprint'     => 'fp-' . $sourceRef,
            'target_subscription_id' => 4242,
            // Reconciled unless a test says otherwise: an entry whose history
            // disagreed is held out of the cutover entirely, which is its own
            // set of tests rather than the ambient condition of all of them.
            'history'                => ['reconciled' => true],
            'source_release'         => ['required' => true, 'state' => CutoverReceipt::RELEASE_PENDING],
        ], $overrides));
    }

    /**
     * The entry shape a transition INTO `$to` legitimately runs over.
     *
     * Staging over a released source is refused outright, so the two rows of
     * the forward table that end at `staged` need an entry that has not been
     * released — which is what a real cohort looks like at that point anyway.
     *
     * @return array<string, mixed>
     */
    private function entryFor(string $to): array
    {
        return in_array($to, [CutoverReceipt::STATE_ASSESSED, CutoverReceipt::STATE_STAGED], true)
            ? $this->entry()
            : $this->releasedEntry();
    }

    /**
     * @return array<string, mixed>
     */
    private function releasedEntry(string $sourceRef = 'subscription:910001'): array
    {
        return $this->entry($sourceRef, [
            'state'          => CutoverReceipt::STATE_SOURCE_RELEASED,
            'source_release' => [
                'required'       => true,
                'state'          => CutoverReceipt::RELEASE_RELEASED,
                'source_mutated' => true,
            ],
        ]);
    }

    /**
     * @param list<array{code: string, message: string}> $failures
     * @return list<string>
     */
    private function codes(array $failures): array
    {
        return array_values(array_unique(array_column($failures, 'code')));
    }
}
