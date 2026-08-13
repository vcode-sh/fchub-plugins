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
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SameSite\GuidedEvidence;
use CartShift\Domain\Transfer\SameSite\GuidedCollisionDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedCustomerDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedProductDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedRunner;
use CartShift\Domain\Transfer\SameSite\GuidedRunPlan;
use CartShift\Domain\Transfer\SameSite\GuidedSourceScope;
use CartShift\Domain\Transfer\SameSite\GuidedSourceDependencyIndex;
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

    /** Missing guided setup stops before the target pipeline and points back to the GUI. */
    public function testATargetStepIsRefusedBeforeDispatchWhenGuidedSetupIsAbsent(): void
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
            self::assertStringContainsString('CartShift screen', $refusal->getMessage());
            self::assertStringNotContainsString('CARTSHIFT_TRANSFER_', $refusal->getMessage());
        }

        self::assertSame(0, $dispatched);
    }

    /**
     * The source side is unaffected by missing guided setup, because nothing on
     * it reads `ConfiguredTransferEvidence`. A setup gate that blocked the whole
     * run would be the "missing optional thing breaks everything" defect this
     * whole workstream started from.
     */
    public function testTheSourceSideRunsWithoutGuidedSetup(): void
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

    public function testProposalRoutesAnExistingCatalogueThroughProductChoices(): void
    {
        $record = RecordEnvelope::forPayload(2, new SourceIdentity(self::SOURCE_KEY, 'product', '10'), [
            'identity' => self::SOURCE_KEY . ':product:10',
            'name' => 'Store membership',
            'sku' => 'MEMBERSHIP',
            'status' => 'publish',
            'variations' => [[
                'identity' => self::SOURCE_KEY . ':product:10:variation:11',
                'sku' => 'MEMBERSHIP',
                'attribute_assignments' => [],
                'price' => ['active_price' => 2500],
            ]],
        ]);
        $row = $this->productDecision($record->identity->canonical());
        $row['source_fingerprint'] = $record->sourceContentDigest;
        $proposal = [
            'status' => 'owner_review_required',
            'blockers' => [],
            'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
            'proposal_decisions' => [$row],
            'proposal_counts' => ['records' => 1, 'total' => 1],
            'decision_set' => ['decisions' => [$row]],
        ];
        $snapshot = [
            'product' => ['post_title' => 'Store membership', 'post_status' => 'publish'],
            'detail' => ['variation_type' => 'simple'],
            'variations' => [[
                'id' => 901,
                'post_id' => 501,
                'variation_title' => 'Default',
                'sku' => 'MEMBERSHIP',
                'item_price' => 2500,
            ]],
            'taxonomies' => [], 'taxonomy_rows' => [], 'media' => [], 'downloads' => [],
        ];
        $products = new GuidedProductDecisionBuilder(
            static fn (): iterable => [$record],
            static fn (): array => [[
                'id' => 501,
                'name' => 'Store membership',
                'sku' => 'MEMBERSHIP',
                'price' => 2500.0,
                'variation_count' => 1,
                'snapshot' => $snapshot,
            ]],
            static fn (): array => ['orders' => 0, 'subscriptions' => 0],
        );
        $runner = $this->runner(
            proposalPipeline: static fn (): array => $proposal,
            productDecisions: $products,
        );
        $evidence = GuidedEvidence::none()->withSelectionFingerprint(str_repeat('a', 64));

        $result = $runner->run($this->stepFor('propose-decisions', $evidence));

        self::assertCount(1, $result['product_questions']);
        self::assertSame([], $result['customer_questions']);
        self::assertSame([], $result['collision_questions']);
        self::assertSame([], $result['proposal_decisions']);
    }

    public function testGuidedProposalDoesNotTreatEveryWordPressUserOrEndedSubscriptionAsARoot(): void
    {
        $selection = new TransferSelection(
            self::SOURCE_KEY,
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::all(),
            SelectionClause::ids([21, 22]),
        );
        $scope = new GuidedSourceScope($selection, 683, 17);
        $seen = null;
        $proposal = [
            'status' => 'owner_review_required',
            'blockers' => [],
            'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
            'proposal_decisions' => [],
            'proposal_counts' => ['records' => 0, 'total' => 0],
            'decision_set' => ['decisions' => []],
        ];
        $runner = $this->runner(
            proposalPipeline: static function (TransferSelection $actual) use (&$seen, $proposal): array {
                $seen = $actual;
                return $proposal;
            },
            productDecisions: new GuidedProductDecisionBuilder(
                static fn (): iterable => [],
                static fn (): array => [],
                static fn (): array => ['orders' => 0, 'subscriptions' => 0],
            ),
            sourceScope: static fn (): GuidedSourceScope => $scope,
        );

        $runner->run($this->stepFor('propose-decisions', GuidedEvidence::none()));

        self::assertSame($selection, $seen);
        self::assertSame(SelectionMode::None, $seen->customers->mode);
        self::assertSame([21, 22], $seen->subscriptions->ids);
    }

    public function testProposalEnrichmentUsesTheCandidateDecisionSetItPresents(): void
    {
        $order = RecordEnvelope::forPayload(2, new SourceIdentity(self::SOURCE_KEY, 'order', '42'), [
            'dependencies' => [],
            'customer' => null,
            'created_utc' => '2025-01-20T11:12:13Z',
            'source_status' => 'completed',
            'currency' => 'PLN',
            'gross_total' => 2400,
            'product_lines' => [],
        ]);
        $skip = [
            'identity' => self::SOURCE_KEY . ':order:42',
            'scope' => 'audit_finding',
            'finding_code' => 'order_money_mismatch',
            'action' => 'excluded_by_policy',
            'source_fingerprint' => str_repeat('c', 64),
            'operator' => self::OPERATOR,
            'reason' => 'Owner review required for the exact source anomaly.',
            'decided_at' => '2026-08-12T11:00:00Z',
        ];
        $proposal = [
            'status' => 'owner_review_required',
            'blockers' => [],
            'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
            'proposal_decisions' => [$skip],
            'proposal_counts' => ['audit_findings' => 1, 'records' => 0, 'total' => 1],
            'decision_set' => ['decisions' => [$skip]],
        ];
        $seen = null;
        $runner = $this->runner(
            proposalPipeline: static fn (): array => $proposal,
            sourceDependencyIndex: static function (TransferSelection $selection, TransferDecisionSet $decisions) use (&$seen, $order): GuidedSourceDependencyIndex {
                $seen = $decisions;
                return new GuidedSourceDependencyIndex([$order]);
            },
        );

        $result = $runner->run($this->stepFor('propose-decisions', GuidedEvidence::none()));

        self::assertInstanceOf(TransferDecisionSet::class, $seen);
        self::assertSame(
            'excluded_by_policy',
            $seen->forAuditFinding(self::SOURCE_KEY . ':order:42', 'order_money_mismatch')['action'] ?? null,
        );
        self::assertSame('order', $result['review_context'][$order->identity->canonical()]['kind']);
        self::assertSame(2400, $result['review_context'][$order->identity->canonical()]['gross_total']);
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
     * default nobody chose, which for `execution_context` means a guided run
     * quietly becoming a CLI mode. So the test walks the entire plan rather
     * than one step, and fails on any option the runner cannot name.
     */
    public function testNoOptionIsLostBetweenThePlanAndThePipelines(): void
    {
        $this->configure();

        foreach ($this->plan($this->completeEvidence(), true)->steps() as $step) {
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
        self::assertSame('guided', $seen['execution_context']);

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
            $this->plan($this->completeEvidence(), true)->steps(),
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
            sourceScope: static fn (): GuidedSourceScope => self::emptySourceScope(),
        );

        // The subset with an injectable seam. `audit`, `propose-decisions` and
        // `prepare` reach live WooCommerce and FluentCart composition roots and
        // are covered against the mounted runtime instead.
        $stubbable = ['compatibility', 'validate-package', 'export', ...[
            'stage',
            'reconcile',
            'promote',
            'activate-catalogue',
            'complete',
        ]];

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

    public function testSubscriptionOwnershipVerbsReachTheirSharedDomainServices(): void
    {
        $this->configure();
        $target = [];
        $source = [];
        $runner = new GuidedRunner(
            new GuidedSetup(self::SOURCE_KEY, self::OPERATOR),
            targetPipeline: static function (array $input) use (&$target): array {
                $target[] = $input;
                return ['state' => $input['command']];
            },
            subscriptionSourceRelease: static function (array $input) use (&$source): array {
                $source[] = $input;
                return ['state' => 'source_released'];
            },
        );

        foreach ($this->plan($this->completeEvidence(), true)->steps() as $step) {
            if (in_array($step->verb, [
                'prepare-subscription-cutover',
                'release-subscription-source',
                'activate-subscriptions',
            ], true)) {
                $runner->run($step);
            }
        }

        self::assertSame(
            ['prepare-subscription-cutover', 'activate-subscriptions'],
            array_column($target, 'command'),
        );
        foreach ($target as $input) {
            self::assertSame('descriptor-0001', $input['descriptor']);
            self::assertSame(self::WORKSPACE . '/package.ndjson', $input['package']);
            self::assertSame(str_repeat('a', 64), $input['confirm']);
            self::assertSame('guided', $input['execution_context']);
        }
        self::assertCount(1, $source);
        self::assertSame('descriptor-0001', $source[0]['descriptor']);
        self::assertSame(self::WORKSPACE, $source[0]['private_dir']);
        self::assertSame('guided', $source[0]['execution_context']);
        self::assertArrayNotHasKey('command', $source[0]);

        $runner->run(new GuidedStep('release-subscription-source', [
            'role' => 'source',
            'private-dir' => self::WORKSPACE,
            'descriptor' => 'descriptor-0001',
            'execution-context' => 'guided',
            'renewals-paused' => true,
        ]));

        self::assertTrue($source[1]['renewals_paused']);
    }

    public function testPrepareReadsTheValidatedPackageBeforeDispatchingItsPayload(): void
    {
        $product = ProductAssessmentFixture::product();
        [$root, $package, $decisionPath] = $this->package(
            [$product->envelope()],
            [$this->recordDecision($product->identity, $product->envelope()->sourceContentDigest)],
        );
        $received = null;
        $runner = new GuidedRunner(
            new GuidedSetup('lapka-web', self::OPERATOR),
            preparePipeline: static function (array $payload) use (&$received): array {
                $received = $payload;

                return ['descriptor' => 'prepared-001'];
            },
        );

        try {
            $result = $runner->run(new GuidedStep('prepare', [
                'role' => 'target',
                'package' => $package,
                'decision-set' => $decisionPath,
                'private-dir' => $root,
                'execution-context' => 'rehearsal',
            ]));

            self::assertSame(['descriptor' => 'prepared-001'], $result);
            self::assertIsArray($received);
            self::assertSame(realpath($package), $received['package']);
            self::assertSame(realpath($decisionPath), $received['decision_set']);
            self::assertSame(realpath($root), $received['private_dir']);
            self::assertSame('rehearsal', $received['execution_context']);
            self::assertSame('lapka-web', $received['source_key']);
        } finally {
            $this->removeTree($root);
        }
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

        $this->assertDefaultReadinessValidatesWithoutPrepare(
            [$product->envelope()],
            [$this->recordDecision($product->identity, $product->envelope()->sourceContentDigest)],
            'shared_parent_stock',
        );
    }

    public function testDefaultTargetReadinessDefersDependencyBoundOrdersToStage(): void
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

        $this->assertDefaultReadinessValidatesWithoutPrepare(
            [$order->envelope()],
            [],
        );
    }

    public function testDefaultTargetReadinessAcceptsARepresentablePackage(): void
    {
        $product = ProductAssessmentFixture::product();

        $this->assertDefaultReadinessValidatesWithoutPrepare(
            [$product->envelope()],
            [$this->recordDecision($product->identity, $product->envelope()->sourceContentDigest)],
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

        $this->assertDefaultReadinessValidatesWithoutPrepare(
            [$term, $product->envelope()],
            [$this->recordDecision($product->identity, $product->envelope()->sourceContentDigest)],
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

    private function runner(
        ?\Closure $targetPipeline = null,
        ?\Closure $probe = null,
        ?\Closure $proposalPipeline = null,
        ?GuidedProductDecisionBuilder $productDecisions = null,
        ?GuidedCustomerDecisionBuilder $customerDecisions = null,
        ?GuidedCollisionDecisionBuilder $collisionDecisions = null,
        ?\Closure $sourceScope = null,
        ?\Closure $sourceDependencyIndex = null,
    ): GuidedRunner
    {
        return new GuidedRunner(
            new GuidedSetup(self::SOURCE_KEY, self::OPERATOR),
            targetPipeline: $targetPipeline,
            preparePipeline: null,
            exportPipeline: null,
            packageValidator: null,
            probe: $probe,
            targetReadiness: static function (): void {},
            proposalPipeline: $proposalPipeline,
            productDecisions: $productDecisions,
            customerDecisions: $customerDecisions ?? new GuidedCustomerDecisionBuilder(
                targetCandidates: static fn (): array => [],
            ),
            collisionDecisions: $collisionDecisions ?? new GuidedCollisionDecisionBuilder(
                static fn (): iterable => [],
                static fn (): array => [],
            ),
            sourceScope: $sourceScope ?? static fn (): GuidedSourceScope => self::emptySourceScope(),
            sourceDependencyIndex: $sourceDependencyIndex,
        );
    }

    private static function emptySourceScope(): GuidedSourceScope
    {
        return new GuidedSourceScope(new TransferSelection(
            self::SOURCE_KEY,
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::all(),
            SelectionClause::none(),
        ), 0, 0);
    }

    private function plan(GuidedEvidence $evidence, bool $includesSubscriptions = false): GuidedRunPlan
    {
        return GuidedRunPlan::rehearsal(
            sourceKey: self::SOURCE_KEY,
            workspace: self::WORKSPACE,
            operator: self::OPERATOR,
            decidedAtUtc: '2026-08-12T11:00:00Z',
            evidence: $evidence,
            includesSubscriptions: $includesSubscriptions,
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
    private function assertDefaultReadinessValidatesWithoutPrepare(
        array $records,
        array $decisions,
        ?string $expectedException = null,
    ): void {
        [$root, $package, $decisionPath] = $this->package($records, $decisions);
        $prepareCalls = 0;
        $runner = new GuidedRunner(
            new GuidedSetup('lapka-web', self::OPERATOR),
            preparePipeline: static function () use (&$prepareCalls): array {
                ++$prepareCalls;
                return [];
            },
        );

        try {
            $result = $runner->run(new GuidedStep('validate-package', [
                'role' => 'target',
                'package' => $package,
                'decision-set' => $decisionPath,
            ]));
            self::assertSame('validated', $result['status']);
            if ($expectedException !== null) {
                self::assertSame(
                    $expectedException,
                    $result['migration_exceptions'][0]['kind'] ?? null,
                );
            }
            self::assertSame(0, $prepareCalls);
        } finally {
            $this->removeTree($root);
        }
    }

    /** @param list<\CartShift\Domain\Transfer\RecordEnvelope> $records @param list<array<string,mixed>> $decisions @return array{string,string,string} */
    private function package(array $records, array $decisions): array
    {
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

        return [$root, $package, $decisionPath];
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
