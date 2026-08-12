<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\PrivateTransferFile;
use CartShift\Domain\Transfer\Package\LoadedSourceInstanceFingerprint;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeReport;
use CartShift\Domain\Transfer\Subscription\LoadedSubscriptionSourceCutoverGateway;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Domain\Transfer\Subscription\SubscriptionSourceCutover;

defined('ABSPATH') || exit;

/** Same-site source adapter around the shared durable mark-before-act cutover. */
final class GuidedSubscriptionSourceRelease
{
    /** @var \Closure(): array{string, TransferRuntimeReport} */
    private readonly \Closure $sourceEnvironment;

    /** @var \Closure(SubscriptionCutoverEvidenceRepository): SubscriptionSourceCutover */
    private readonly \Closure $sourceCutover;

    /** @var \Closure(): string */
    private readonly \Closure $clock;

    /**
     * @param (callable(): array{string, TransferRuntimeReport})|null $sourceEnvironment
     * @param (callable(SubscriptionCutoverEvidenceRepository): SubscriptionSourceCutover)|null $sourceCutover
     * @param (callable(): string)|null $clock
     */
    public function __construct(
        ?callable $sourceEnvironment = null,
        ?callable $sourceCutover = null,
        ?callable $clock = null,
    ) {
        $this->sourceEnvironment = $sourceEnvironment === null
            ? static fn (): array => [
                (new LoadedSourceInstanceFingerprint())->fingerprint(),
                (new TransferRuntimeProbe())->inspect(TransferRuntimeProbe::ROLE_SOURCE),
            ]
            : $sourceEnvironment(...);
        $this->sourceCutover = $sourceCutover === null
            ? static fn (SubscriptionCutoverEvidenceRepository $repository): SubscriptionSourceCutover =>
                new SubscriptionSourceCutover($repository, new LoadedSubscriptionSourceCutoverGateway())
            : $sourceCutover(...);
        $this->clock = $clock === null
            ? static fn (): string => gmdate('Y-m-d\TH:i:s\Z')
            : $clock(...);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function __invoke(array $input): array
    {
        $renewalsPaused = ($input['renewals_paused'] ?? false) === true;
        unset($input['renewals_paused']);
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        if ($keys !== ['descriptor', 'execution_context', 'private_dir']) {
            throw new \InvalidArgumentException('guided_subscription_source_release_input_shape_invalid');
        }
        if ($input['execution_context'] !== 'guided') {
            throw new \RuntimeException('subscription_cutover_execution_context_changed');
        }

        $private = PrivateTransferFile::directory((string) $input['private_dir']);
        $descriptor = (string) $input['descriptor'];
        $prepared = (new PreparedTransferRepository($private))->get($descriptor);
        $repository = new SubscriptionCutoverEvidenceRepository($private);
        $evidence = $repository->get($descriptor);

        if ($evidence->requiresSourceRelease() && !$renewalsPaused) {
            throw new \RuntimeException('source_renewal_maintenance_unconfirmed');
        }

        if ($prepared->executionContext !== 'guided' || $evidence->executionContext !== 'guided') {
            throw new \RuntimeException('subscription_cutover_execution_context_changed');
        }
        if ($prepared->runId !== $evidence->runId
            || $prepared->sourceKey !== $evidence->sourceKey
            || !hash_equals($prepared->packageHash, $evidence->packageHash)
            || !hash_equals($prepared->targetState->decisionHash, $evidence->decisionHash)
            || !hash_equals($prepared->targetState->selectionHash, $evidence->selectionHash)) {
            throw new \RuntimeException('subscription_cutover_prepared_evidence_changed');
        }

        [$sourceInstance, $runtime] = ($this->sourceEnvironment)();
        if (!is_string($sourceInstance) || !$runtime instanceof TransferRuntimeReport) {
            throw new \RuntimeException('subscription_cutover_source_environment_invalid');
        }
        if (!hash_equals($evidence->sourceInstanceFingerprint, $sourceInstance)) {
            throw new \RuntimeException('subscription_cutover_source_instance_changed');
        }
        if (!$runtime->isReady()) {
            throw new \RuntimeException('subscription_cutover_source_runtime_unready');
        }
        if (!hash_equals($evidence->sourceRuntimeFingerprint, $runtime->fingerprint)) {
            throw new \RuntimeException('subscription_cutover_source_runtime_changed');
        }

        $result = (($this->sourceCutover)($repository))->release(
            $descriptor,
            $renewalsPaused || !$evidence->requiresSourceRelease(),
            ($this->clock)(),
        );

        return [
            'state' => $result->state,
            'evidence_fingerprint' => $result->fingerprint(),
            'entries' => count($result->entries),
        ];
    }
}
