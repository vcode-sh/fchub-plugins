<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Graph\TransferDependencyGraph;
use CartShift\Domain\Transfer\Package\TransferPackageReader;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Read-only target preparation. Its only writes are immutable files in the supplied private directory. */
final readonly class LoadedTargetPreparePipeline
{
    private \Closure $clock;

    /** @param callable():string $clock */
    public function __construct(
        private TransferRuntimeInspector $runtime,
        private TargetSettingsInspector $settings,
        private PreparedTargetBaselineProbe $baselineProbe,
        callable $clock,
        private TransferPackageValidator $validator = new TransferPackageValidator(),
        private bool $allowGuidedContext = false,
    ) {
        $this->clock = $clock(...);
    }

    public static function create(): self
    {
        return new self(
            new TransferRuntimeProbe(),
            new LoadedTargetSettingsInspector(),
            new LoadedPreparedTargetBaselineProbe(),
            static fn (): string => gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /** Same-site composition root; `guided` is never accepted by the CLI adapter. */
    public static function createGuided(): self
    {
        return new self(
            new TransferRuntimeProbe(),
            new LoadedTargetSettingsInspector(),
            new LoadedPreparedTargetBaselineProbe(),
            static fn (): string => gmdate('Y-m-d\TH:i:s\Z'),
            allowGuidedContext: true,
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function __invoke(array $input): array
    {
        $required = ['decision_hash', 'decision_set', 'execution_context', 'package', 'package_hash', 'private_dir', 'selection_hash', 'source_key'];
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        if ($keys !== $required) {
            throw new \InvalidArgumentException('target_prepare_input_shape_invalid');
        }
        $package = realpath((string) $input['package']);
        $decisionPath = realpath((string) $input['decision_set']);
        $private = PrivateTransferFile::directory((string) $input['private_dir']);
        if ($package === false || !is_dir($package) || is_link((string) $input['package'])
            || $decisionPath === false || !is_file($decisionPath) || is_link((string) $input['decision_set'])) {
            throw new \RuntimeException('target_prepare_input_path_changed');
        }
        $manifest = $this->validator->assertValid($package);
        $decisions = TransferDecisionSet::fromFile($decisionPath);
        $decisions->assertSourceKey($manifest->sourceKey);
        $records = iterator_to_array((new TransferPackageReader($package, $this->validator))->records(), false);
        $closure = (new TransferDependencyGraph())->validate($records, $decisions);
        if (!$closure->closed) {
            throw new \RuntimeException('transfer_dependency_graph_blocked:' . implode(',', $closure->reasonCodes));
        }
        foreach ([
            'package_hash' => hash('sha256', $manifest->canonicalJson()),
            'decision_hash' => $decisions->fingerprint(),
            'selection_hash' => $manifest->selectionFingerprint,
        ] as $field => $expected) {
            if (!is_string($input[$field]) || !hash_equals($expected, $input[$field])) {
                throw new \RuntimeException('target_prepare_sealed_input_changed:' . $field);
            }
        }
        $executionContexts = $this->allowGuidedContext
            ? ['rehearsal', 'cutover', 'guided']
            : ['rehearsal', 'cutover'];
        if ($input['source_key'] !== $manifest->sourceKey
            || !in_array($input['execution_context'], $executionContexts, true)) {
            throw new \RuntimeException('target_prepare_context_changed');
        }
        $runtime = $this->runtime->inspect(TransferRuntimeProbe::ROLE_TARGET);
        if (!$runtime->isReady()) {
            throw new \RuntimeException('target_runtime_not_ready:' . implode(',', $runtime->errors));
        }
        $settingsHash = $this->settings->fingerprint();
        $gatewayHash = $this->settings->gatewayFingerprint();
        $this->assertHash($runtime->fingerprint, 'target_runtime_fingerprint_invalid');
        $this->assertHash($settingsHash, 'target_settings_fingerprint_invalid');
        $this->assertHash($gatewayHash, 'target_gateway_fingerprint_invalid');

        $runId = 'tr-' . substr(CanonicalJson::fingerprint([
            'package' => $input['package_hash'],
            'decision' => $input['decision_hash'],
            'selection' => $input['selection_hash'],
            'runtime' => $runtime->fingerprint,
            'settings' => $settingsHash,
            'gateway' => $gatewayHash,
            'context' => $input['execution_context'],
        ]), 0, 24);
        $baseline = $this->baselineProbe->capture($manifest->sourceKey, $closure->orderedRecords, $decisions, $runId);
        if ($baseline->sourceKey !== $manifest->sourceKey) {
            throw new \RuntimeException('target_baseline_source_changed');
        }
        if ($baseline->blockingFindings !== []) {
            throw new \RuntimeException('target_preflight_blocked:' . implode(',', $baseline->blockingFindings));
        }

        $targetState = new TargetStateFingerprint(
            (string) $input['package_hash'],
            (string) $input['decision_hash'],
            $runtime->fingerprint,
            $settingsHash,
            $gatewayHash,
            (string) $input['selection_hash'],
            $baseline->fingerprint(),
        );
        $productRecords = array_values(array_filter(
            $closure->orderedRecords,
            static fn ($record): bool => $record->identity->entityType === 'product',
        ));
        $leaveDraftAccepted = $productRecords !== [] && array_filter(
            $productRecords,
            static fn ($record): bool => !in_array(
                $decisions->for($record->identity)['action'] ?? null,
                ['leave_catalogue_draft', 'link_existing_product'],
                true,
            ),
        ) === [];
        $prepared = new PreparedTransfer(
            $runId,
            $package,
            (string) $input['package_hash'],
            $targetState,
            (string) $input['execution_context'],
            [],
            $leaveDraftAccepted,
            ($this->clock)(),
            $manifest->sourceKey,
        );
        (new PreparedTargetBaselineRepository($private))->save($runId, $baseline);
        (new PreparedDecisionSetRepository($private))->save($runId, $decisions);
        (new PreparedTransferRepository($private))->save($prepared);

        return [
            'descriptor' => $runId,
            'descriptor_hash' => $prepared->descriptorHash(),
            'state' => TransferRunState::Prepared->value,
            'blocking_findings' => [],
            'leave_draft_accepted' => $leaveDraftAccepted,
            'package_fingerprint' => $targetState->packageHash,
            'decision_fingerprint' => $targetState->decisionHash,
            'compatibility_fingerprint' => $targetState->compatibilityHash,
            'settings_fingerprint' => $targetState->settingsHash,
            'gateway_fingerprint' => $targetState->gatewayHash,
            'selection_fingerprint' => $targetState->selectionHash,
            'target_fingerprint' => $targetState->targetHash,
            'next_legal_actions' => ['stage'],
        ];
    }

    private function assertHash(string $value, string $reason): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \RuntimeException($reason);
        }
    }
}
