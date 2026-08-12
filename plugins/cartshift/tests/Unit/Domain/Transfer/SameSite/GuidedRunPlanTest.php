<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\TransferRunState;
use CartShift\Domain\Transfer\SameSite\GuidedEvidence;
use CartShift\Domain\Transfer\SameSite\GuidedRunPlan;
use CartShift\Domain\Transfer\SameSite\RehearsalProof;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Sixteen decisions and an ordering, computed instead of typed.
 *
 * This is where the guided route earns its keep, so it is pure: state in,
 * ordered verbs with fully-resolved arguments out, no database, no filesystem,
 * no globals. That is what makes it possible to assert the whole contract
 * rather than smoke-test one path through it.
 *
 * The ordering is not invented here. `TransferRunState::canTransitionTo()` is
 * the authority — Exported → Validated → Prepared → Staging → Staged →
 * Reconciling → Reconciled → Promoted → CatalogueActivating → Completed — and
 * these tests assert the verb sequence that walks it.
 */
final class GuidedRunPlanTest extends PluginTestCase
{
    private const string SOURCE_KEY = 'site-0123456789abcdef';
    private const string WORKSPACE = '/srv/private/cartshift-private/site-0123456789abcdef';
    private const string OPERATOR = 'wp-user:1';
    private const string DECIDED_AT = '2026-08-12T11:00:00Z';

    public function testTheSequenceWalksTheTransferStateMachineInOrder(): void
    {
        $verbs = array_map(
            static fn (object $step): string => $step->verb,
            $this->rehearsal()->steps(),
        );

        self::assertSame([
            'compatibility',
            'compatibility',
            'audit',
            'propose-decisions',
            'export',
            'validate-package',
            'prepare',
            'stage',
            'reconcile',
            'promote',
            'activate-catalogue',
            'complete',
        ], $verbs);
    }

    public function testTheShopIsReadFromTheSourceRoleAndWrittenThroughTheTargetRole(): void
    {
        $roles = [];

        foreach ($this->rehearsal()->steps() as $step) {
            $roles[] = $step->verb . ':' . $step->arguments['role'];
        }

        self::assertSame([
            'compatibility:source',
            'compatibility:target',
            'audit:source',
            'propose-decisions:source',
            'export:source',
            'validate-package:target',
            'prepare:target',
            'stage:target',
            'reconcile:target',
            'promote:target',
            'activate-catalogue:target',
            'complete:target',
        ], $roles);
    }

    /**
     * A package holds every customer and order in the shop. Every path this
     * plan emits must therefore be absolute and inside the private workspace —
     * a relative path would resolve against whatever the runner's working
     * directory happens to be, and one outside the workspace is not covered by
     * the rule that keeps it off the web.
     */
    public function testEveryPathIsAbsoluteAndInsideThePrivateWorkspace(): void
    {
        $paths = [];

        foreach ($this->rehearsal(GuidedEvidence::none()->withPackage(self::WORKSPACE . '/package.ndjson'))->steps() as $step) {
            foreach (['decision-set', 'destination', 'package', 'private-dir'] as $option) {
                if (isset($step->arguments[$option])) {
                    $paths[] = $step->arguments[$option];
                }
            }
        }

        self::assertNotSame([], $paths);

        foreach ($paths as $path) {
            self::assertStringStartsWith('/', $path, 'A relative path resolves against the runner, not the shop.');
            self::assertStringStartsWith(self::WORKSPACE, $path);
        }
    }

    public function testNothingIsLeftForSomebodyToTypeIn(): void
    {
        $plan = $this->rehearsal($this->completeEvidence());

        foreach ($plan->steps() as $step) {
            self::assertSame([], $step->pending, $step->verb . ' still needs evidence it should have been given.');

            foreach ($step->arguments as $option => $value) {
                self::assertNotSame('', $value, $step->verb . ' emitted an empty --' . $option . '.');
                self::assertStringNotContainsString('<', (string) $value, 'A placeholder reached the command line.');
            }
        }
    }

    // ──────────────────────────────────────────────
    // Evidence, threaded rather than guessed
    // ──────────────────────────────────────────────

    /**
     * `--confirm` is the gate that stops a stage running against a selection
     * nobody audited. Threading a real fingerprint through proves the plan
     * carries the audit's own answer rather than a value that merely looks
     * like one.
     */
    public function testStageConfirmsTheExactFingerprintTheAuditReturned(): void
    {
        $fingerprint = str_repeat('c', 64);

        $plan = $this->rehearsal($this->completeEvidence()->withSelectionFingerprint($fingerprint));

        foreach ($plan->steps() as $step) {
            if (in_array($step->verb, ['stage', 'reconcile', 'promote', 'activate-catalogue', 'complete'], true)) {
                self::assertSame($fingerprint, $step->arguments['confirm']);
            }
        }
    }

    public function testAStepWhoseEvidenceHasNotArrivedYetSaysSoRatherThanGuessing(): void
    {
        $steps = [];

        foreach ($this->rehearsal()->steps() as $step) {
            $steps[$step->verb] = $step;
        }

        // Nothing has run, so the audit's fingerprint, export's package and
        // prepare's descriptor do not exist yet.
        self::assertSame([], $steps['audit']->pending);
        self::assertSame(['package'], $steps['validate-package']->pending);
        self::assertSame(['confirm', 'descriptor', 'package'], $steps['stage']->pending);

        self::assertArrayNotHasKey('package', $steps['validate-package']->arguments);
        self::assertSame(self::WORKSPACE . '/decisions.json', $steps['validate-package']->arguments['decision-set']);
        self::assertArrayNotHasKey('confirm', $steps['stage']->arguments);
    }

    public function testEachPersistedRunGetsItsOwnPrivateImmutablePackageDestination(): void
    {
        $first = $this->stepFor('export', $this->rehearsal())->arguments['destination'];
        $secondPlan = GuidedRunPlan::rehearsal(
            self::SOURCE_KEY,
            self::WORKSPACE,
            self::OPERATOR,
            '2026-08-12T11:00:01Z',
            GuidedEvidence::none(),
            false,
        );
        $second = $this->stepFor('export', $secondPlan)->arguments['destination'];

        self::assertStringStartsWith(self::WORKSPACE . '/guided-packages/', $first);
        self::assertNotSame($first, $second);
        self::assertSame($first, $this->stepFor('export', $this->rehearsal())->arguments['destination']);
    }

    /**
     * `command()` exists so a person can read or paste a step, and a value with
     * whitespace in it has to survive that. ISO 8601 has no space, so the
     * timestamp is no longer the example — the quoting rule still is, and it is
     * asserted directly below rather than through a value that stopped needing it.
     */
    public function testARenderedCommandSurvivesBeingPasted(): void
    {
        $rendered = $this->stepFor('propose-decisions', $this->rehearsal())->command();

        self::assertStringContainsString('--decided-at=' . self::DECIDED_AT, $rendered);
        self::assertStringContainsString(
            "--operator='wp user'",
            (new \CartShift\Domain\Transfer\SameSite\GuidedStep('x', ['operator' => 'wp user']))->command(),
        );

        // Paths and keys are ordinary and stay unquoted, or every command on the
        // screen turns into a thicket of apostrophes for no reason.
        self::assertStringContainsString('--source-key=' . self::SOURCE_KEY, $rendered);
        self::assertStringContainsString('--decision-set=' . self::WORKSPACE . '/decisions.json', $rendered);
        self::assertStringContainsString('--all-kinds ', $rendered);
    }

    public function testOperatorAndDecisionTimeAreSuppliedInUtc(): void
    {
        $propose = $this->stepFor('propose-decisions', $this->rehearsal());

        self::assertSame(self::OPERATOR, $propose->arguments['operator']);
        self::assertSame(self::DECIDED_AT, $propose->arguments['decided-at']);
        // THE EXACT PATTERN THE CONSUMER ENFORCES.
        // `TransferDecisionProposalPipeline` refuses anything but ISO 8601 with
        // the T and the Z. This assertion first shipped as `Y-m-d H:i:s`, which
        // is what the plan emitted — so the test pinned the defect instead of
        // catching it, and a real run refused at propose-decisions.
        self::assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',
            $propose->arguments['decided-at'],
        );
    }

    public function testATimestampTheProposalPipelineWouldRefuseCannotBecomeAPlan(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GuidedRunPlan::rehearsal(
            sourceKey: self::SOURCE_KEY,
            workspace: self::WORKSPACE,
            operator: self::OPERATOR,
            decidedAtUtc: '2026-08-12 11:00:00',
            evidence: GuidedEvidence::none(),
            includesSubscriptions: false,
        );
    }

    // ──────────────────────────────────────────────
    // Rehearsal is not a question
    // ──────────────────────────────────────────────

    public function testARehearsalPlanRunsEveryWritingVerbAsARehearsal(): void
    {
        foreach ($this->rehearsal($this->completeEvidence())->steps() as $step) {
            if (isset($step->arguments['execution-context'])) {
                self::assertSame('rehearsal', $step->arguments['execution-context']);
            }
        }
    }

    public function testACutoverPlanCannotBeBuiltWithoutAFinishedRehearsal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // The only route to a cutover plan is a proof, and the only route to a
        // proof is a rehearsal that reached Completed. An operator cannot decide
        // to skip it, because there is nothing to pass.
        RehearsalProof::fromRehearsal('run-0001', TransferRunState::Failed);
    }

    public function testACutoverPlanBuiltFromAFinishedRehearsalCutsOver(): void
    {
        $plan = GuidedRunPlan::cutover(
            sourceKey: self::SOURCE_KEY,
            workspace: self::WORKSPACE,
            operator: self::OPERATOR,
            decidedAtUtc: self::DECIDED_AT,
            evidence: $this->completeEvidence(),
            proof: RehearsalProof::fromRehearsal('run-0001', TransferRunState::Completed),
        );

        $contexts = [];

        foreach ($plan->steps() as $step) {
            if (isset($step->arguments['execution-context'])) {
                $contexts[] = $step->arguments['execution-context'];
            }
        }

        self::assertNotSame([], $contexts);
        self::assertSame(['cutover'], array_values(array_unique($contexts)));
    }

    // ──────────────────────────────────────────────
    // Subscriptions: optional, and never silently dropped
    // ──────────────────────────────────────────────

    public function testAShopWithoutSubscriptionsPlansNoSubscriptionVerbs(): void
    {
        $verbs = array_map(static fn (object $step): string => $step->verb, $this->rehearsal()->steps());

        foreach (['prepare-subscription-cutover', 'release-subscription-source', 'activate-subscriptions'] as $verb) {
            self::assertNotContains($verb, $verbs);
        }
    }

    public function testAShopWithoutSubscriptionsReportsThemSkippedRatherThanSelectedAndEmpty(): void
    {
        $plan = $this->rehearsal();

        self::assertFalse($plan->includesSubscriptions());
        self::assertSame('wc_subscriptions_inactive', $plan->subscriptionsSkippedReason());

        // The captured mode prevents a later plugin activation from widening
        // the same durable run.
        $audit = $this->stepFor('audit', $plan);
        self::assertSame('none', $audit->arguments['subscriptions']);
    }

    /**
     * FAIL CLOSED ON THE PART THAT IS NOT WRITTEN YET.
     *
     * `TransferRunState` fixes the commerce ordering exactly; it says nothing
     * about where the three subscription verbs slot in, and no other source in
     * this repository does either. Emitting a plausible order would be an
     * invention that migrates real subscribers, and omitting them silently
     * would be the empty-dataset defect wearing a wizard. So a shop that has
     * subscriptions is refused, by name, until the ordering is established
     * from evidence.
     */
    public function testAShopWithSubscriptionsIsRefusedRatherThanGivenAnInventedOrdering(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('guided_subscription_sequence_unplanned');

        GuidedRunPlan::rehearsal(
            sourceKey: self::SOURCE_KEY,
            workspace: self::WORKSPACE,
            operator: self::OPERATOR,
            decidedAtUtc: self::DECIDED_AT,
            evidence: GuidedEvidence::none(),
            includesSubscriptions: true,
        )->steps();
    }

    // ──────────────────────────────────────────────
    // Purity
    // ──────────────────────────────────────────────

    /**
     * Code, not prose.
     *
     * The first version of this read the raw file and tripped on the class's own
     * docblock, which names `$wpdb` in order to promise it is not used. A purity
     * check a comment can break is a check that punishes documentation — and,
     * worse, one a comment could also satisfy. So the comments are stripped and
     * what is left is what actually executes.
     */
    public function testThePlanTouchesNoDatabaseOptionOrFilesystem(): void
    {
        $tokens = token_get_all((string) file_get_contents(
            dirname(__DIR__, 5) . '/app/Domain/Transfer/SameSite/GuidedRunPlan.php',
        ));

        $code = implode('', array_map(
            static fn (array|string $token): string => is_string($token) ? $token : $token[1],
            array_filter(
                $tokens,
                static fn (array|string $token): bool
                    => is_string($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true),
            ),
        ));

        self::assertStringContainsString('function steps', $code, 'The comment stripper ate the class.');

        foreach (['$wpdb', 'get_option', 'update_option', 'file_put_contents', 'file_get_contents', 'apply_filters', 'is_dir', 'mkdir'] as $impurity) {
            self::assertStringNotContainsString(
                $impurity,
                $code,
                'The plan must stay pure, or the exhaustive assertions above stop meaning anything.',
            );
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function rehearsal(?GuidedEvidence $evidence = null): GuidedRunPlan
    {
        return GuidedRunPlan::rehearsal(
            sourceKey: self::SOURCE_KEY,
            workspace: self::WORKSPACE,
            operator: self::OPERATOR,
            decidedAtUtc: self::DECIDED_AT,
            evidence: $evidence ?? GuidedEvidence::none(),
            includesSubscriptions: false,
        );
    }

    private function completeEvidence(): GuidedEvidence
    {
        return GuidedEvidence::none()
            ->withSelectionFingerprint(str_repeat('a', 64))
            ->withPackage(self::WORKSPACE . '/package.ndjson')
            ->withDescriptor('descriptor-0001');
    }

    private function stepFor(string $verb, GuidedRunPlan $plan): object
    {
        foreach ($plan->steps() as $step) {
            if ($step->verb === $verb) {
                return $step;
            }
        }

        self::fail('The plan has no ' . $verb . ' step.');
    }
}
