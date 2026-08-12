<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeReport;
use CartShift\Domain\Transfer\SameSite\GuidedSubscriptionSourceRelease;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Domain\Transfer\Subscription\SubscriptionSourceCutover;
use CartShift\Domain\Transfer\Subscription\SubscriptionSourceCutoverGateway;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedSubscriptionSourceReleaseTest extends PluginTestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir() . '/cartshift-guided-source-release-' . bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->workspace);
        parent::tearDown();
    }

    public function testGuidedReleaseRevalidatesPreparedEvidenceAndKeepsMarkBeforeActIdempotency(): void
    {
        $this->savePreparedTransfer();
        $this->saveCutoverEvidence();
        $gateway = new GuidedSourceCutoverGateway();
        $release = $this->release($gateway);

        $first = $release($this->input());
        $retried = $release($this->input());

        self::assertSame('source_released', $first['state']);
        self::assertSame($first, $retried);
        self::assertSame(1, $gateway->releaseCalls, 'A guided retry released Woo renewal ownership twice.');
        self::assertSame(2, $gateway->inspectCalls, 'The retry must revalidate the live source.');
    }

    public function testSourceRuntimeDriftStopsBeforeTheSharedCutoverServiceReadsOrWritesWoo(): void
    {
        $this->savePreparedTransfer();
        $this->saveCutoverEvidence();
        $gateway = new GuidedSourceCutoverGateway();
        $release = $this->release($gateway, str_repeat('9', 64));

        $this->expectExceptionMessage('subscription_cutover_source_runtime_changed');
        try {
            $release($this->input());
        } finally {
            self::assertSame(0, $gateway->inspectCalls);
            self::assertSame(0, $gateway->releaseCalls);
        }
    }

    public function testAutomaticRenewalReleaseRequiresTheExplicitGuidedPauseConfirmation(): void
    {
        $this->savePreparedTransfer();
        $this->saveCutoverEvidence();
        $gateway = new GuidedSourceCutoverGateway();
        $release = $this->release($gateway);
        $input = $this->input();
        $input['renewals_paused'] = false;

        $this->expectExceptionMessage('source_renewal_maintenance_unconfirmed');
        try {
            $release($input);
        } finally {
            self::assertSame(0, $gateway->inspectCalls);
            self::assertSame(0, $gateway->releaseCalls);
        }
    }

    private function release(
        GuidedSourceCutoverGateway $gateway,
        string $runtimeFingerprint = '',
    ): GuidedSubscriptionSourceRelease {
        $runtimeFingerprint = $runtimeFingerprint !== '' ? $runtimeFingerprint : str_repeat('e', 64);

        return new GuidedSubscriptionSourceRelease(
            sourceEnvironment: static fn (): array => [
                str_repeat('d', 64),
                new TransferRuntimeReport(
                    TransferRuntimeProbe::ROLE_SOURCE,
                    $runtimeFingerprint,
                    [],
                    [],
                    [],
                    [],
                ),
            ],
            sourceCutover: static fn (SubscriptionCutoverEvidenceRepository $repository): SubscriptionSourceCutover =>
                new SubscriptionSourceCutover($repository, $gateway),
            clock: static fn (): string => '2026-08-13T12:10:00Z',
        );
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'descriptor' => 'run-guided-22',
            'private_dir' => $this->workspace,
            'execution_context' => 'guided',
            'renewals_paused' => true,
        ];
    }

    private function savePreparedTransfer(): void
    {
        $target = new TargetStateFingerprint(
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('f', 64),
            str_repeat('6', 64),
            str_repeat('7', 64),
            str_repeat('c', 64),
            str_repeat('8', 64),
        );
        (new PreparedTransferRepository($this->workspace))->save(new PreparedTransfer(
            'run-guided-22',
            $this->workspace . '/package',
            str_repeat('a', 64),
            $target,
            'guided',
            [],
            false,
            '2026-08-13T12:00:00Z',
            'shop-alpha',
        ));
    }

    private function saveCutoverEvidence(): void
    {
        (new SubscriptionCutoverEvidenceRepository($this->workspace))->create(new SubscriptionCutoverEvidence(
            'run-guided-22',
            'shop-alpha',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
            str_repeat('e', 64),
            'guided',
            SubscriptionCutoverEvidence::PREPARED,
            [[
                'source_identity' => 'shop-alpha:subscription:31',
                'source_fingerprint' => str_repeat('1', 64),
                'target_id' => 9031,
                'staged_target_fingerprint' => str_repeat('2', 64),
                'source_release_required' => true,
                'intended_status' => 'active',
                'release_state' => 'pending',
                'activation_state' => 'paused',
            ]],
            '2026-08-13T12:00:00Z',
        ));
    }
}

final class GuidedSourceCutoverGateway implements SubscriptionSourceCutoverGateway
{
    public bool $manual = false;
    public int $inspectCalls = 0;
    public int $releaseCalls = 0;

    public function inspect(SourceIdentity $identity): array
    {
        ++$this->inspectCalls;

        return [
            'source_fingerprint' => $this->manual ? str_repeat('4', 64) : str_repeat('1', 64),
            'release_comparison_fingerprint' => str_repeat('5', 64),
            'renewal_fingerprint' => str_repeat('3', 64),
            'requires_manual_renewal' => $this->manual,
        ];
    }

    public function release(SourceIdentity $identity): array
    {
        ++$this->releaseCalls;
        $this->manual = true;

        return [
            'source_fingerprint' => str_repeat('4', 64),
            'release_comparison_fingerprint' => str_repeat('5', 64),
            'renewal_fingerprint' => str_repeat('3', 64),
            'requires_manual_renewal' => true,
            'previous_requires_manual_renewal' => false,
        ];
    }
}
