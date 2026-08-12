<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Package\TransferPackageWriter;
use CartShift\Domain\Transfer\Product\StockOwnership;
use CartShift\Domain\Transfer\Product\StockProfile;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SameSite\GuidedEvidence;
use CartShift\Domain\Transfer\SameSite\GuidedRunner;
use CartShift\Domain\Transfer\SameSite\GuidedRunPlan;
use CartShift\Domain\Transfer\SameSite\GuidedSetup;
use CartShift\Domain\Transfer\SameSite\GuidedStep;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\Domain\Transfer\Product\ProductAssessmentFixture;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The thing that actually runs a step, and the three refusals in front of it.
 *
 * It owns no orchestration. `TransferCoordinator` and the pipelines do the work
 * exactly as they do for WP-CLI; this decides whether a step may be dispatched
 * at all and translates the plan's CLI-shaped option names into the input keys
 * the pipelines read. Both of those are places a silent mistake would be
 * expensive — a dropped key becomes a pipeline running with a default it was
 * never meant to have — so both are asserted exhaustively rather than sampled.
 */
final class GuidedRunnerTest extends PluginTestCase
{
    private const string SOURCE_KEY = 'site-0123456789abcdef';
    private const string WORKSPACE = '/srv/private/cartshift';
    private const string OPERATOR = 'wp-user:1';

    #[\Override]
    protected function tearDown(): void
    {
        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY);
        putenv(ConfiguredTransferEvidence::OPERATOR_ID);
        unset($GLOBALS['_cartshift_test_get_col_callback'], $GLOBALS['_cartshift_test_get_results_callback']);

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // Refusal 1: evidence that has not arrived
    // ──────────────────────────────────────────────

    /**
     * `--confirm` is what stops a stage running against a selection nobody
     * audited. A runner that dispatched a step with `pending` entries would be
     * handing the pipeline a hole where that gate should be.
     */
    public function testAStepWaitingOnEvidenceIsRefusedBeforeAnythingIsDispatched(): void
    {
        $dispatched = 0;

        $runner = $this->runner(targetPipeline: function () use (&$dispatched): array {
            $dispatched++;

            return [];
        });

        try {
            $runner->run($this->stepFor('stage', GuidedEvidence::none()));
            self::fail('A step missing its evidence was dispatched.');
        } catch (\RuntimeException $refusal) {
            self::assertStringContainsString('guided_step_evidence_missing', $refusal->getMessage());
            self::assertStringContainsString('confirm', $refusal->getMessage());
        }

        self::assertSame(0, $dispatched, 'The pipeline was reached despite the refusal.');
    }

    // ──────────────────────────────────────────────
    // Refusal 2: the one-time configuration
    // ──────────────────────────────────────────────

    /**
     * The raw failure is `transfer_private_directory_not_configured`, thrown
     * deep inside the pipeline after it has already resolved a descriptor. A
     * member reading that has no idea it means "paste two lines". So the gate
     * is in front, and it carries the same reason codes the setup screen shows.
     */
    public function testATargetStepIsRefusedWithSetupsOwnCodesWhenTheConstantsAreAbsent(): void
    {
        $dispatched = 0;

        $runner = $this->runner(targetPipeline: function () use (&$dispatched): array {
            $dispatched++;

            return [];
        });

        try {
            $runner->run($this->stepFor('stage', $this->completeEvidence()));
            self::fail('A target step ran on an unconfigured runtime.');
        } catch (\RuntimeException $refusal) {
            self::assertStringContainsString('guided_setup_incomplete', $refusal->getMessage());
            self::assertStringContainsString(ConfiguredTransferEvidence::PRIVATE_DIRECTORY, $refusal->getMessage());
            self::assertStringContainsString(ConfiguredTransferEvidence::OPERATOR_ID, $refusal->getMessage());
        }

        self::assertSame(0, $dispatched);
    }

    /**
     * The source side is unaffected by the missing constants, because nothing on
     * it reads `ConfiguredTransferEvidence`. A setup gate that blocked the whole
     * run would be the "missing optional thing breaks everything" defect this
     * whole workstream started from.
     */
    public function testTheSourceSideRunsWithoutTheTargetConstants(): void
    {
        $probed = [];

        $runner = $this->runner(probe: function (string $role) use (&$probed): array {
            $probed[] = $role;

            return ['role' => $role, 'ready' => true];
        });

        $result = $runner->run($this->stepFor('compatibility', GuidedEvidence::none()));

        self::assertSame(['source'], $probed);
        self::assertTrue($result['ready']);
    }

    // ──────────────────────────────────────────────
    // The translation, asserted across a whole plan
    // ──────────────────────────────────────────────

    /**
     * EVERY OPTION THE PLAN EMITS SURVIVES TRANSLATION.
     *
     * The plan speaks CLI (`execution-context`, `decision-set`); the pipelines
     * read snake_case (`execution_context`, `decision_set`). A key that falls
     * between the two does not fail loudly — the pipeline simply runs with a
     * default nobody chose, which for `execution_context` means a rehearsal
     * quietly becoming something else. So the test walks the entire plan rather
     * than one step, and fails on any option the runner cannot name.
     */
    public function testNoOptionIsLostBetweenThePlanAndThePipelines(): void
    {
        $this->configure();

        foreach ($this->plan($this->completeEvidence())->steps() as $step) {
            foreach (array_keys($step->arguments) as $option) {
                self::assertTrue(
                    GuidedRunner::translates($option),
                    sprintf('%s emits --%s and the runner would drop it.', $step->verb, $option),
                );
            }
        }
    }

    public function testTheTargetPipelineReceivesTheKeysItActuallyReads(): void
    {
        $this->configure();

        $seen = [];

        $runner = $this->runner(targetPipeline: function (array $input) use (&$seen): array {
            $seen = $input;

            return ['state' => 'staged'];
        });

        $runner->run($this->stepFor('stage', $this->completeEvidence()));

        self::assertSame('stage', $seen['command']);
        self::assertSame('descriptor-0001', $seen['descriptor']);
        self::assertSame(self::WORKSPACE . '/package.ndjson', $seen['package']);
        self::assertSame(str_repeat('a', 64), $seen['confirm']);
        self::assertSame('rehearsal', $seen['execution_context']);

        // The CLI spellings must not survive alongside their translations, or a
        // pipeline reading `execution_context` and a reader reading
        // `execution-context` disagree about the same run.
        self::assertArrayNotHasKey('execution-context', $seen);
        self::assertArrayNotHasKey('format', $seen, 'A display-only option reached the pipeline.');
        self::assertArrayNotHasKey('role', $seen, 'The role is the pipeline choice, not an input.');
    }

    // ──────────────────────────────────────────────
    // Refusal 3: seams that are not wired yet
    // ──────────────────────────────────────────────

    /**
     * THE INVARIANT THAT OUTLIVED THE REFUSAL.
     *
     * `audit`, `propose-decisions` and `prepare` were refused while their
     * marshalling lived in `TransferCommand`; they are wired now. What must stay
     * true is the relationship, not the list: a plan that emits a verb the
     * runner cannot run is a screen offering a step that dies on click. Adding a
     * verb to `GuidedRunPlan` without a seam fails here.
     */
    public function testEveryVerbThePlanEmitsIsOneTheRunnerCanRun(): void
    {
        $emitted = array_values(array_unique(array_map(
            static fn (object $step): string => $step->verb,
            $this->plan($this->completeEvidence())->steps(),
        )));

        sort($emitted);

        self::assertSame($emitted, GuidedRunner::wiredVerbs());
    }

    public function testAnUnknownVerbIsRefusedRatherThanIgnored(): void
    {
        $this->configure();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('guided_step_unknown');

        $this->runner()->run(new GuidedStep('teleport', ['role' => 'source', 'format' => 'json']));
    }

    /**
     * `wiredVerbs()` is what the screen may promise, so it must not be able to
     * name a verb that then refuses. Export claimed to be wired while its arm
     * threw `guided_step_seam_unavailable` — found by running a whole plan
     * against the mounted shop, not by any assertion here at the time.
     */
    public function testEveryVerbCalledWiredActuallyReachesItsSeam(): void
    {
        $this->configure();

        $reached = [];

        $runner = new GuidedRunner(
            new GuidedSetup(self::SOURCE_KEY, self::OPERATOR),
            targetPipeline: static function (array $input) use (&$reached): array {
                $reached[] = $input['command'];

                return [];
            },
            preparePipeline: null,
            exportPipeline: static function () use (&$reached): array {
                $reached[] = 'export';

                return ['path' => '/srv/private/cartshift/package.ndjson', 'records_sha256' => str_repeat('d', 64)];
            },
            packageValidator: static function () use (&$reached): object {
                $reached[] = 'validate-package';

                return (object) [
                    'sourceKey' => self::SOURCE_KEY,
                    'selectionFingerprint' => str_repeat('a', 64),
                    'recordsSha256' => str_repeat('d', 64),
                    'recordCounts' => [],
                ];
            },
            probe: static function (string $role) use (&$reached): array {
                $reached[] = 'compatibility';

                return ['role' => $role];
            },
            targetReadiness: static function (string $package, string $decisionSet) use (&$reached): void {
                $reached[] = 'target-readiness';
                self::assertSame('/srv/private/cartshift/package.ndjson', $package);
                self::assertSame('/srv/private/cartshift/decisions.json', $decisionSet);
            },
        );

        // The subset with an injectable seam. `audit`, `propose-decisions` and
        // `prepare` reach live WooCommerce and FluentCart composition roots and
        // are covered against the mounted runtime instead.
        $stubbable = ['compatibility', 'validate-package', 'export', ...['stage', 'reconcile', 'promote', 'activate-catalogue', 'complete']];

        foreach ($this->plan($this->completeEvidence())->steps() as $step) {
            if (in_array($step->verb, $stubbable, true)) {
                $runner->run($step);
            }
        }

        self::assertSame(
            $this->sorted([...array_unique($stubbable), 'target-readiness']),
            $this->sorted(array_unique($reached)),
            'A verb the runner claims to handle never reached a seam.',
        );
    }

    public function testTargetReadinessPropagatesAnUnrepresentableProductBeforePrepare(): void
    {
        $checked = 0;
        $runner = new GuidedRunner(
            new GuidedSetup(self::SOURCE_KEY, self::OPERATOR),
            packageValidator: static fn (): object => (object) [
                'sourceKey' => self::SOURCE_KEY,
                'selectionFingerprint' => str_repeat('a', 64),
                'recordsSha256' => str_repeat('d', 64),
                'recordCounts' => ['product' => 1],
            ],
            targetReadiness: static function () use (&$checked): void {
                ++$checked;
                throw new \RuntimeException(
                    'target_product_assessment_blocked:target_schema_unrepresentable',
                );
            },
        );

        $this->expectExceptionMessage('target_schema_unrepresentable');
        try {
            $runner->run($this->stepFor('validate-package', $this->completeEvidence()));
        } finally {
            self::assertSame(1, $checked);
        }
    }

    public function testSuccessfulPackageValidationCarriesMigrationExceptionsIntoDurableState(): void
    {
        $exception = ['kind' => 'shared_parent_stock', 'source_quantity' => 11];
        $runner = new GuidedRunner(
            new GuidedSetup(self::SOURCE_KEY, self::OPERATOR),
            packageValidator: static fn (): object => (object) [
                'sourceKey' => self::SOURCE_KEY,
                'selectionFingerprint' => str_repeat('a', 64),
                'recordsSha256' => str_repeat('d', 64),
                'recordCounts' => ['product' => 1],
            ],
            targetReadiness: static fn (): array => [$exception],
        );

        $result = $runner->run($this->stepFor('validate-package', $this->completeEvidence()));

        self::assertSame([$exception], $result['migration_exceptions']);
    }

    public function testDefaultTargetReadinessReportsSharedParentStockWithoutBlockingTheCatalogue(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $product = ProductAssessmentFixture::product([
            'productType' => 'variable',
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'identity' => ProductAssessmentFixture::identity('42:variation:101'),
                'stock' => new StockProfile(StockOwnership::Parent, $parent, 7, 'instock', 'no', false, null),
            ])],
        ]);

        $this->assertDefaultReadinessStopsBeforePrepare(
            [$product->envelope()],
            [$this->recordDecision($product->identity, $product->envelope()->sourceContentDigest)],
            'guided_completed_rehearsal_rollback_unavailable',
            'shared_parent_stock',
        );
    }

    public function testDefaultTargetReadinessStopsDependencyBoundOrdersBeforePrepare(): void
    {
        $order = new OrderRecord(
            new SourceIdentity('lapka-web', 'order', '9'),
            null,
            null,
            'checkout',
            'completed',
            'USD',
            'USD',
            'USD',
            '1.0000',
            'same_currency:USD',
            false,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            '2026-01-01T00:00:00Z',
            null, null, null, null,
            [], [], [], [], [], [], [], [], [],
        );

        $this->assertDefaultReadinessStopsBeforePrepare(
            [$order->envelope()],
            [],
            'guided_dependency_bound_target_readiness_unavailable',
        );
    }

    public function testDefaultTargetReadinessStopsARepresentablePackageUntilCompletedRollbackExists(): void
    {
        $product = ProductAssessmentFixture::product();

        $this->assertDefaultReadinessStopsBeforePrepare(
            [$product->envelope()],
            [$this->recordDecision($product->identity, $product->envelope()->sourceContentDigest)],
            'guided_completed_rehearsal_rollback_unavailable',
        );
    }

    public function testDefaultTargetReadinessLeavesDependencyRecordsWithTheirProductPlanner(): void
    {
        $product = ProductAssessmentFixture::product();
        $term = RecordEnvelope::forPayload(
            1,
            new SourceIdentity('lapka-web', 'taxonomy_term', '7:product-cat'),
            ['dependencies' => []],
        );

        $this->assertDefaultReadinessStopsBeforePrepare(
            [$term, $product->envelope()],
            [$this->recordDecision($product->identity, $product->envelope()->sourceContentDigest)],
            'guided_completed_rehearsal_rollback_unavailable',
        );
    }

    public function testABlockedProposalCannotBeWrittenAsThoughReviewResolvedIt(): void
    {
        $this->expectExceptionMessage('guided_decision_proposal_blocked');

        $this->runner()->acceptProposal([
            'status' => 'blocked',
            'blockers' => [['code' => 'existing_record_decision_stale', 'identity' => 'shop-alpha:order:9']],
            'decision_set' => ['decisions' => []],
        ], sys_get_temp_dir() . '/must-not-exist.json');
    }

    public function testAProposalCannotOverwriteADecisionSetChangedSinceReview(): void
    {
        $directory = sys_get_temp_dir() . '/cartshift-guided-accept-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/decisions.json';
        $newer = TransferDecisionSet::fromArray([$this->productDecision('shop-alpha:product:11')]);
        file_put_contents($path, $newer->canonicalJson());
        chmod($path, 0600);
        $before = file_get_contents($path);

        try {
            $this->expectExceptionMessage('guided_decision_set_changed');
            $this->runner()->acceptProposal([
                'status' => 'owner_review_required',
                'blockers' => [],
                'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
                'decision_set' => ['decisions' => [$this->productDecision('shop-alpha:product:10')]],
            ], $path);
        } finally {
            self::assertSame($before, file_get_contents($path));
            unlink($path);
            rmdir($directory);
        }
    }

    public function testAcceptedDecisionsReplaceTheReviewedBaseAtomically(): void
    {
        $directory = sys_get_temp_dir() . '/cartshift-guided-accept-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/decisions.json';

        try {
            $result = $this->runner()->acceptProposal([
                'status' => 'owner_review_required',
                'blockers' => [],
                'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
                'decision_set' => ['decisions' => [$this->productDecision('shop-alpha:product:10')]],
            ], $path);

            self::assertSame(1, $result['accepted']);
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(
                'shop-alpha:product:10',
                TransferDecisionSet::fromFile($path)->rows()[0]['identity'],
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            foreach (glob($path . '.tmp-*') ?: [] as $temporary) {
                unlink($temporary);
            }
            rmdir($directory);
        }
    }

    public function testTheSameAcceptedProposalCanFinishStatePersistenceAfterARetry(): void
    {
        $directory = sys_get_temp_dir() . '/cartshift-guided-accept-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/decisions.json';
        $proposal = [
            'status' => 'owner_review_required',
            'blockers' => [],
            'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
            'decision_set' => ['decisions' => [$this->productDecision('shop-alpha:product:10')]],
        ];

        try {
            $first = $this->runner()->acceptProposal($proposal, $path);
            $retried = $this->runner()->acceptProposal($proposal, $path);

            self::assertSame($first, $retried);
            self::assertSame(1, $retried['accepted']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function configure(): void
    {
        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY . '=' . sys_get_temp_dir());
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=' . self::OPERATOR);
    }

    private function runner(?\Closure $targetPipeline = null, ?\Closure $probe = null): GuidedRunner
    {
        return new GuidedRunner(
            new GuidedSetup(self::SOURCE_KEY, self::OPERATOR),
            targetPipeline: $targetPipeline,
            preparePipeline: null,
            exportPipeline: null,
            packageValidator: null,
            probe: $probe,
            targetReadiness: static function (): void {},
        );
    }

    private function plan(GuidedEvidence $evidence): GuidedRunPlan
    {
        return GuidedRunPlan::rehearsal(
            sourceKey: self::SOURCE_KEY,
            workspace: self::WORKSPACE,
            operator: self::OPERATOR,
            decidedAtUtc: '2026-08-12T11:00:00Z',
            evidence: $evidence,
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

    private function stepFor(string $verb, GuidedEvidence $evidence): GuidedStep
    {
        foreach ($this->plan($evidence)->steps() as $step) {
            if ($step->verb === $verb) {
                return $step;
            }
        }

        self::fail('The plan has no ' . $verb . ' step.');
    }

    /** @return array<string, mixed> */
    private function productDecision(string $identity): array
    {
        return [
            'identity' => $identity,
            'scope' => 'record',
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => str_repeat('a', 64),
            'operator' => self::OPERATOR,
            'reason' => 'Owner approved the reviewed product.',
            'decided_at' => '2026-08-12T11:00:00Z',
        ];
    }

    /** @param list<\CartShift\Domain\Transfer\RecordEnvelope> $records @param list<array<string,mixed>> $decisions */
    private function assertDefaultReadinessStopsBeforePrepare(
        array $records,
        array $decisions,
        string $expected,
        ?string $expectedException = null,
    ): void {
        $root = sys_get_temp_dir() . '/cartshift-guided-readiness-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $selection = new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::none(),
        );
        $package = (new TransferPackageWriter(new TransferPackageValidator()))->write(
            new SourceIdentity('lapka-web', 'product', '1'),
            $selection,
            $records,
            [],
            [
                'destination' => $root,
                'source_instance_fingerprint' => str_repeat('1', 64),
                'source_url_hash' => str_repeat('2', 64),
                'source_runtime_fingerprint' => str_repeat('3', 64),
                'source_settings_fingerprint' => str_repeat('4', 64),
                'source_capability_fingerprint' => str_repeat('5', 64),
                'cartshift_version' => '2.0.0',
                'woocommerce_version' => '11.0.0',
                'created_at_utc' => '2026-08-12T12:00:00Z',
            ],
        );
        $decisionPath = $root . '/decisions.json';
        file_put_contents($decisionPath, TransferDecisionSet::fromArray($decisions)->canonicalJson());
        chmod($decisionPath, 0600);
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (): array => ['standard'];
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [];
        $prepareCalls = 0;
        $runner = new GuidedRunner(
            new GuidedSetup('lapka-web', self::OPERATOR),
            preparePipeline: static function () use (&$prepareCalls): array {
                ++$prepareCalls;
                return [];
            },
        );

        try {
            $runner->run(new GuidedStep('validate-package', [
                'role' => 'target',
                'package' => $package,
                'decision-set' => $decisionPath,
            ]));
            self::fail('Target readiness advanced into prepare.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString($expected, $failure->getMessage());
            if ($expectedException !== null) {
                self::assertInstanceOf(\CartShift\Domain\Transfer\SameSite\GuidedRunFailure::class, $failure);
                self::assertSame(
                    $expectedException,
                    $failure->context['migration_exceptions'][0]['kind'] ?? null,
                );
            }
            self::assertSame(0, $prepareCalls);
        } finally {
            $this->removeTree($root);
        }
    }

    /** @return array<string,mixed> */
    private function recordDecision(SourceIdentity $identity, string $fingerprint): array
    {
        return [
            'identity' => $identity->canonical(),
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => $fingerprint,
            'operator' => self::OPERATOR,
            'reason' => 'Owner reviewed the product.',
            'decided_at' => '2026-08-12T12:00:00Z',
        ];
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
