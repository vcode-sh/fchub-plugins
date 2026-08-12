<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;

defined('ABSPATH') || exit;

/** The private setup the guided route can safely choose once and then forget. */
final readonly class GuidedSetup
{
    public function __construct(
        private string $sourceKey,
        private string $operatorId,
    ) {
    }

    public function isComplete(): bool
    {
        return PrivateWorkspace::isTransferDirectoryConfigured() && $this->isOperatorConfigured();
    }

    /** Prepare identity and private defaults as one guarded member action. */
    public static function initialise(string $operatorId): string
    {
        $identity = new SiteSourceIdentity();
        $existingSourceKey = $identity->current();
        $configurationExisted = get_option(GuidedSetupConfiguration::OPTION, null) !== null;
        $lock = GuidedSetupLock::acquire('initialise');

        $sourceKey = null;
        try {
            $sourceKey = $identity->ensure();
            (new self($sourceKey, $operatorId))->ensure();

            return $sourceKey;
        } catch (\Throwable $failure) {
            if ($existingSourceKey === null && is_string($sourceKey)) {
                $identity->forgetIfCurrent($sourceKey);
            }
            if (!$configurationExisted) {
                (new GuidedSetupConfiguration())->forget();
            }

            throw $failure;
        }
    }

    /** Create the private workspace and atomically retain the first valid owner. */
    public function ensure(): void
    {
        if ($this->isComplete()) {
            return;
        }

        (new GuidedSetupConfiguration())->store(
            (new PrivateWorkspace($this->sourceKey))->path(),
            $this->operatorId,
        );

        if (!$this->isComplete()) {
            throw new \RuntimeException('guided_transfer_setup_incomplete');
        }
    }

    /**
     * Whether the browser can perform the real thing, and why not.
     *
     * @return array{available: bool, reason: string, message: string}
     */
    public function cutover(): array
    {
        return [
            'available' => false,
            'reason' => 'cutover_approval_is_per_run_evidence',
            'message' => 'Cutover remains unavailable until CartShift can roll back a completed rehearsal and '
                . 'prove the shop returned to its exact starting state. The guided check stops before target '
                . 'records while that core contract is missing.',
        ];
    }

    private function isOperatorConfigured(): bool
    {
        try {
            ConfiguredTransferEvidence::operatorId();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
