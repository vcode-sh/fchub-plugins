<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;

defined('ABSPATH') || exit;

final readonly class LoadedTargetStateInspector implements TargetStateInspector
{
    public function __construct(
        private string $packageHash,
        private string $decisionHash,
        private string $selectionHash,
        private PreparedTargetBaseline $baseline,
        private string $runId,
        private TransferRuntimeInspector $runtime,
        private TargetSettingsInspector $settings,
        private PreparedTargetBaselineProbe $baselineProbe,
        private ?string $expectedRuntimeHash = null,
        private ?string $expectedSettingsHash = null,
        private ?string $expectedGatewayHash = null,
    ) {
    }

    public function inspect(): TargetStateFingerprint
    {
        $runtime = $this->runtime->inspect(TransferRuntimeProbe::ROLE_TARGET);
        if (!$runtime->isReady()) {
            throw new \RuntimeException('target_runtime_not_ready:' . implode(',', $runtime->errors));
        }
        $settings = $this->settings->fingerprint();
        $gateway = $this->settings->gatewayFingerprint();
        self::assertHash($settings, 'target_settings_fingerprint_invalid');
        self::assertHash($gateway, 'target_gateway_fingerprint_invalid');
        if ($this->expectedRuntimeHash !== null && !hash_equals($this->expectedRuntimeHash, $runtime->fingerprint)) {
            throw new \RuntimeException('target_runtime_fingerprint_changed');
        }
        if ($this->expectedSettingsHash !== null && !hash_equals($this->expectedSettingsHash, $settings)) {
            throw new \RuntimeException('target_settings_fingerprint_changed');
        }
        if ($this->expectedGatewayHash !== null && !hash_equals($this->expectedGatewayHash, $gateway)) {
            throw new \RuntimeException('target_gateway_fingerprint_changed');
        }
        $this->baselineProbe->verify($this->baseline, $this->runId);

        return new TargetStateFingerprint(
            $this->packageHash,
            $this->decisionHash,
            $runtime->fingerprint,
            $settings,
            $gateway,
            $this->selectionHash,
            $this->baseline->fingerprint(),
        );
    }

    private static function assertHash(string $value, string $reason): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \RuntimeException($reason);
        }
    }
}
