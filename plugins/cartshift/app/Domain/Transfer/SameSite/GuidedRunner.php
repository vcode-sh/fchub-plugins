<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;
use CartShift\Domain\Transfer\Execution\LoadedTargetPreparePipeline;
use CartShift\Domain\Transfer\Execution\LoadedTargetTransferPipeline;
use CartShift\Domain\Transfer\Audit\LoadedWooTransferAuditor;
use CartShift\Domain\Transfer\Decision\LoadedWooDecisionProposalPipeline;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\PrivateTransferFile;
use CartShift\Domain\Transfer\Graph\TransferDependencyGraph;
use CartShift\Domain\Transfer\Package\LoadedWooExportPipeline;
use CartShift\Domain\Transfer\Package\TransferPackageReader;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/**
 * Runs one step of a guided plan, and refuses three ways before it does.
 *
 * IT OWNS NO ORCHESTRATION. `TransferCoordinator` and the loaded pipelines do
 * the work, byte for byte as they do for WP-CLI. What lives here is the part
 * that has no other home: deciding whether a step may be dispatched at all, and
 * translating the plan's CLI-shaped option names into the input keys the
 * pipelines read.
 *
 * THE TRANSLATION IS THE DANGEROUS PART. `GuidedRunPlan` speaks CLI, because
 * that is the vocabulary an operator would have typed and the one a support
 * ticket quotes. The pipelines read snake_case. A key lost between the two does
 * not fail loudly — the pipeline runs with a default nobody chose, and for
 * `execution_context` that is a guided move quietly becoming a CLI mode. So
 * the map is exhaustive, it lives in exactly one place, and a test walks every
 * step of a full plan asserting the runner can name every option it emits.
 */
final class GuidedRunner
{
    /**
     * Plan option name => pipeline input key.
     *
     * `role` and `format` are absent deliberately: the role is implied by which
     * pipeline is being called and `format` is a display choice that belongs to
     * whoever renders the result, not to the run.
     */
    private const array PIPELINE_KEYS = [
        'package' => 'package',
        'descriptor' => 'descriptor',
        'confirm' => 'confirm',
        'source-key' => 'source_key',
        'decision-set' => 'decision_set',
        'private-dir' => 'private_dir',
        'destination' => 'destination',
        'execution-context' => 'execution_context',
        'lease-recovery' => 'lease_recovery',
        'batch-size' => 'batch_size',
        'cutover-approval' => 'cutover_approval',
        'operator' => 'operator',
        'decided-at' => 'decided_at',
        'all-kinds' => 'all_kinds',
        'products' => 'products',
        'customers' => 'customers',
        'orders' => 'orders',
        'subscriptions' => 'subscriptions',
        'renewals-paused' => 'renewals_paused',
    ];

    /** Options the pipelines never see. */
    private const array DISPLAY_ONLY = ['role', 'format'];

    /** Everything routed through `LoadedTargetTransferPipeline`. */
    private const array TARGET_LIFECYCLE = [
        'stage',
        'reconcile',
        'promote',
        'prepare-subscription-cutover',
        'activate-subscriptions',
        'activate-catalogue',
        'complete',
    ];

    /**
     * Nothing is unwired any more. Kept as the list rather than deleted because
     * `run()` still consults it and a future seam may land here first.
     *
     * The drift this guarded against turned out to be smaller than it looked.
     * `audit`, `propose-decisions` and `prepare` are ninety-nine, seventy-three
     * and forty lines inside `TransferCommand`, but almost all of that is
     * guarding untrusted operator input — combinations of `--all-kinds` and
     * per-kind clauses, malformed paths, unknown formats. `GuidedRunPlan`
     * cannot emit any of those: it is trusted by construction and asserted to
     * be. What remains is four lines, two lines and twelve, and every one of
     * them calls the same shared domain object the CLI calls. The logic that
     * could drift already lives in one place.
     *
     * @var list<string>
     */
    private const array UNWIRED = [];

    public function __construct(
        private readonly GuidedSetup $setup,
        private readonly ?\Closure $targetPipeline = null,
        private readonly ?\Closure $preparePipeline = null,
        private readonly ?\Closure $exportPipeline = null,
        private readonly ?\Closure $packageValidator = null,
        private readonly ?\Closure $probe = null,
        private readonly ?\Closure $targetReadiness = null,
        private readonly ?\Closure $proposalPipeline = null,
        private readonly ?GuidedProductDecisionBuilder $productDecisions = null,
        private readonly ?GuidedCustomerDecisionBuilder $customerDecisions = null,
        private readonly ?GuidedCollisionDecisionBuilder $collisionDecisions = null,
        private readonly ?\Closure $subscriptionSourceRelease = null,
        private readonly ?\Closure $sourceDependencyIndex = null,
        private readonly ?\Closure $sourceScope = null,
    ) {
    }

    /** The verbs a guided run may currently promise. */
    public static function wiredVerbs(): array
    {
        $wired = array_diff(
            [
                'compatibility',
                'audit',
                'propose-decisions',
                'export',
                'validate-package',
                'prepare',
                'release-subscription-source',
                ...self::TARGET_LIFECYCLE,
            ],
            self::UNWIRED,
        );

        sort($wired);

        return array_values($wired);
    }

    /** Does the runner know what this plan option becomes? */
    public static function translates(string $option): bool
    {
        return isset(self::PIPELINE_KEYS[$option]) || in_array($option, self::DISPLAY_ONLY, true);
    }

    /**
     * @return array<string, mixed> Whatever the seam returned.
     * @throws \RuntimeException on any of the three refusals.
     */
    public function run(GuidedStep $step): array
    {
        if (!$step->isRunnable()) {
            throw new \RuntimeException(sprintf(
                'guided_step_evidence_missing: %s cannot run until the run has produced %s.',
                $step->verb,
                implode(', ', $step->pending),
            ));
        }

        if (in_array($step->verb, [...self::TARGET_LIFECYCLE, 'release-subscription-source'], true)) {
            $this->assertConfigured($step->verb);
        }

        if (in_array($step->verb, self::UNWIRED, true)) {
            throw new \RuntimeException(sprintf(
                'guided_step_seam_not_wired: %s still marshals its arguments inside the WP-CLI command, and '
                . 'copying that here would let the terminal and the screen migrate different things. Run this '
                . 'step with `%s`.',
                $step->verb,
                $step->command(),
            ));
        }

        $input = $this->pipelineInput($step);

        return match (true) {
            $step->verb === 'compatibility' => $this->runProbe((string) $step->arguments['role']),
            $step->verb === 'validate-package' => $this->runPackageValidation($input),
            $step->verb === 'export' => $this->runExport($input),
            $step->verb === 'audit' => $this->runAudit($input),
            $step->verb === 'propose-decisions' => $this->runProposal($input),
            $step->verb === 'prepare' => $this->runPrepare($input),
            $step->verb === 'release-subscription-source' => $this->runSubscriptionSourceRelease($input),
            in_array($step->verb, self::TARGET_LIFECYCLE, true) => $this->runTargetLifecycle($step->verb, $input),
            default => throw new \RuntimeException('guided_step_unknown: ' . $step->verb),
        };
    }

    /** Refuse before pipeline work if the guided workspace was not prepared. */
    private function assertConfigured(string $verb): void
    {
        if ($this->setup->isComplete()) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'guided_setup_incomplete: %s needs the private guided workspace prepared first. '
            . 'Return to the CartShift screen and run the store check again.',
            $verb,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineInput(GuidedStep $step): array
    {
        $input = [];

        foreach ($step->arguments as $option => $value) {
            if (in_array($option, self::DISPLAY_ONLY, true)) {
                continue;
            }

            $key = self::PIPELINE_KEYS[$option]
                ?? throw new \RuntimeException('guided_step_option_untranslated: --' . $option);

            $input[$key] = $value;
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function runProbe(string $role): array
    {
        if ($this->probe !== null) {
            return ($this->probe)($role);
        }

        $probe = new TransferRuntimeProbe();
        $report = $probe->inspect($role);

        return [
            'role' => $report->role,
            'topology' => $probe->topology()->value,
            'runtime_fingerprint' => $report->fingerprint,
            'ready' => $report->isReady(),
            'errors' => $report->errors,
            'warnings' => $report->warnings,
        ];
    }

    /** @return array<string, mixed> */
    private function runPackageValidation(array $input): array
    {
        return (new GuidedTargetReadinessInspector(
            $this->packageValidator,
            $this->targetReadiness,
        ))->inspect($input);
    }

    /**
     * Export takes the same captured selection as audit and proposal. That is
     * what prevents a newly activated optional add-on widening the package.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runExport(array $input): array
    {
        $selection = $this->selectionFrom($input);

        $destination = (string) $input['destination'];
        $decisionSet = (string) $input['decision_set'];

        if ($this->exportPipeline !== null) {
            return ($this->exportPipeline)($selection, $destination, $decisionSet);
        }

        $private = ConfiguredTransferEvidence::privateDirectory();
        $packageRoot = $private . '/guided-packages';
        if (!str_starts_with($destination . '/', $packageRoot . '/')
            || is_link($packageRoot)
            || is_link($destination)) {
            throw new \RuntimeException('guided_package_destination_invalid');
        }
        if (!is_dir($destination)
            && !mkdir($destination, 0700, true)
            && !is_dir($destination)) {
            throw new \RuntimeException('guided_package_destination_unavailable');
        }
        chmod($packageRoot, 0700);
        chmod($destination, 0700);
        PrivateTransferFile::directory($destination);

        return (new LoadedWooExportPipeline())($selection, $destination, $decisionSet);
    }

    /**
     * The source-side read. Four lines, all of them shared objects.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runAudit(array $input): array
    {
        $selection = $this->selectionFrom($input);
        $decisions = $this->decisionsFor($selection, $input);

        $report = LoadedWooTransferAuditor::create()->audit($selection, $decisions);

        return [
            'ready' => $report->ready,
            'source_key' => $selection->sourceKey,
            'selection_fingerprint' => $report->selectionFingerprint,
            'decision_fingerprint' => $report->decisionFingerprint,
            'counts' => $report->counts,
            'blockers' => $report->blockers,
        ];
    }

    /**
     * The proposal, which is where the one surviving member decision lives.
     *
     * It returns `owner_review_required` and does not write anything. That is
     * the mapping step: CartShift proposes, the owner approves, and only then
     * does a decision set exist for `export` to read. A guided run therefore
     * cannot complete unattended, by design rather than by omission — the whole
     * point of the plan's decision ledger was that this is the one question
     * nobody can answer for the shop.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runProposal(array $input): array
    {
        $scope = $this->sourceScopeFrom($input);
        $selection = $scope->selection;
        $decisions = $this->decisionsFor($selection, $input);
        $proposal = $this->proposalPipeline !== null
            ? ($this->proposalPipeline)(
                $selection,
                $decisions,
                (string) $input['operator'],
                (string) $input['decided_at'],
            )
            : LoadedWooDecisionProposalPipeline::create()->propose(
                $selection,
                $decisions,
                (string) $input['operator'],
                (string) $input['decided_at'],
            );
        $candidateRows = $proposal['decision_set']['decisions'] ?? null;
        if (!is_array($candidateRows) || !array_is_list($candidateRows)) {
            throw new \RuntimeException('guided_decision_proposal_invalid');
        }
        $candidateDecisions = TransferDecisionSet::fromArray($candidateRows);

        $index = null;
        if ($this->productDecisions === null || $this->customerDecisions === null || $this->collisionDecisions === null) {
            $index = $this->sourceDependencyIndex === null
                ? GuidedSourceDependencyIndex::forLoadedSelection($selection, $candidateDecisions)
                : ($this->sourceDependencyIndex)($selection, $candidateDecisions);
            if (!$index instanceof GuidedSourceDependencyIndex) {
                throw new \RuntimeException('guided_source_dependency_index_invalid');
            }
        }
        $products = $this->productDecisions ?? new GuidedProductDecisionBuilder(
            sourceRecords: static fn (): iterable => $index->records(RecordKind::Product),
            sourceDependencyRecords: static fn (): iterable => $index->records(),
        );
        $customers = $this->customerDecisions ?? new GuidedCustomerDecisionBuilder(
            recordLoader: static fn (SourceIdentity $identity): RecordEnvelope => $index->record($identity),
        );
        $collisions = $this->collisionDecisions ?? new GuidedCollisionDecisionBuilder(
            sourceRecords: static fn (): iterable => $index->records(),
        );

        $result = $collisions->enrich(
            $customers->enrich($products->enrich($proposal, $selection), $selection),
            $selection,
        );
        if (!$index instanceof GuidedSourceDependencyIndex) {
            return $result;
        }
        $result = (new GuidedReviewContextBuilder())->enrich($result, $index);
        $summary = $scope->summary($index->records());
        $exceptions = is_array($result['migration_exceptions'] ?? null)
            ? array_values($result['migration_exceptions'])
            : [];

        return array_replace($result, [
            'source_scope' => $summary,
            'migration_exceptions' => [...$exceptions, ['kind' => 'source_scope'] + $summary],
        ]);
    }

    /**
     * Record the owner's acceptance of a decision proposal.
     *
     * THIS IS THE REVIEW STEP, AND IT IS THE ONLY THING IN THE GUIDED ROUTE
     * THAT A MACHINE MUST NOT DO ALONE. `propose-decisions` returns
     * `owner_review_required` and writes nothing on purpose: product mapping
     * and order-note visibility are judgements about somebody's shop, and
     * publishing an internal note to a customer's order history is a different
     * kind of wrong from losing it. CartShift proposes; the owner accepts; only
     * then does a decision set exist for `export` to read.
     *
     * The rows written are the proposal's own — the acceptance is the act, not
     * an edit. Canonical bytes, mode 0600, inside the private workspace, because
     * `TransferDecisionSet::fromFile()` refuses anything else and refuses a file
     * whose bytes are not canonically serialised.
     *
     * @param array<string, mixed> $proposal As `propose-decisions` returned it.
     * @return array<string, mixed>
     */
    public function acceptProposal(array $proposal, string $decisionSetPath): array
    {
        return (new GuidedDecisionSetAcceptor())->accept($proposal, $decisionSetPath);
    }

    /**
     * Seal a descriptor: validate the package, close the dependency graph, hash
     * the four things the target state is bound to.
     *
     * Every one of these is the same call `TransferCommand::prepare()` makes, in
     * the same order. What is absent is its argument guarding, because the plan
     * cannot emit a malformed path or an unknown execution context.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runPrepare(array $input): array
    {
        $package = (string) $input['package'];
        $decisionPath = (string) $input['decision_set'];

        $validator = new TransferPackageValidator();
        $manifest = $validator->assertValid($package);

        $decisions = TransferDecisionSet::fromFile($decisionPath);
        $decisions->assertSourceKey($manifest->sourceKey);

        $records = iterator_to_array((new TransferPackageReader($package, $validator))->records(), false);
        $closure = (new TransferDependencyGraph())->validate($records, $decisions);

        if (!$closure->closed) {
            throw new \RuntimeException('transfer_dependency_graph_blocked:' . implode(',', $closure->reasonCodes));
        }

        $pipeline = $this->preparePipeline;
        $payload = [
            'package' => realpath($package) ?: throw new \RuntimeException('transfer_package_path_changed'),
            'decision_set' => realpath($decisionPath) ?: throw new \RuntimeException('transfer_decision_path_changed'),
            'private_dir' => PrivateTransferFile::directory((string) $input['private_dir']),
            'execution_context' => (string) $input['execution_context'],
            'package_hash' => hash('sha256', $manifest->canonicalJson()),
            'decision_hash' => $decisions->fingerprint(),
            'selection_hash' => $manifest->selectionFingerprint,
            'source_key' => $manifest->sourceKey,
        ];

        if ($pipeline !== null) {
            return $pipeline($payload);
        }

        return LoadedTargetPreparePipeline::createGuided()($payload);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function runSubscriptionSourceRelease(array $input): array
    {
        if ($this->subscriptionSourceRelease !== null) {
            return ($this->subscriptionSourceRelease)($input);
        }

        return (new GuidedSubscriptionSourceRelease())($input);
    }

    /**
     * Build the guided source scope once for this step. Customers follow the
     * included commerce records and terminal subscriptions stay in WooCommerce.
     *
     * @param array<string, mixed> $input
     */
    private function selectionFrom(array $input): TransferSelection
    {
        return $this->sourceScopeFrom($input)->selection;
    }

    /** @param array<string, mixed> $input */
    private function sourceScopeFrom(array $input): GuidedSourceScope
    {
        if (($input['all_kinds'] ?? null) !== true) {
            throw new \RuntimeException(
                'guided_step_seam_not_wired: explicit selection clauses are not marshalled yet.',
            );
        }
        $subscriptions = $input['subscriptions'] ?? 'none';
        if (!in_array($subscriptions, ['all', 'none'], true)) {
            throw new \RuntimeException('guided_subscription_selection_invalid');
        }

        $sourceKey = (string) $input['source_key'];
        $scope = $this->sourceScope === null
            ? GuidedSourceScope::read($sourceKey, $subscriptions === 'all')
            : ($this->sourceScope)($sourceKey, $subscriptions === 'all');
        if (!$scope instanceof GuidedSourceScope
            || $scope->selection->sourceKey !== $sourceKey
            || $scope->selection->customers->mode !== \CartShift\Domain\Transfer\SelectionMode::None) {
            throw new \RuntimeException('guided_source_scope_invalid');
        }

        return $scope;
    }

    /**
     * The decision set this step reads, or an empty one before the owner has
     * approved anything.
     *
     * @param array<string, mixed> $input
     */
    private function decisionsFor(TransferSelection $selection, array $input): TransferDecisionSet
    {
        $path = (string) ($input['decision_set'] ?? '');

        $decisions = $path !== '' && is_file($path)
            ? TransferDecisionSet::fromFile($path)
            : TransferDecisionSet::empty();

        $decisions->assertSourceKey($selection->sourceKey);

        return $decisions;
    }

    /**
     * Everything from `stage` onwards, through the one pipeline that owns it.
     *
     * Written as a statement rather than a first-class callable on a static
     * call: the plugin supports PHP 8.3, `(new X())(...)` and `X::y()(...)`
     * parse only on newer builds, and the local test runner is 8.5 while the
     * runtime is 8.3 — so the suite is structurally unable to catch it.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runTargetLifecycle(string $verb, array $input): array
    {
        $payload = ['command' => $verb] + $input;

        if ($this->targetPipeline !== null) {
            return ($this->targetPipeline)($payload);
        }

        return LoadedTargetTransferPipeline::create()($payload);
    }
}
