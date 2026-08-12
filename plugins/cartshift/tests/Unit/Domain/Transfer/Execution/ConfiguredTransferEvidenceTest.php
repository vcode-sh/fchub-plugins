<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;
use CartShift\Domain\Transfer\Execution\CutoverApprovalManifest;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

final class ConfiguredTransferEvidenceTest extends PluginTestCase
{
    private ?string $approvalDirectory = null;

    protected function tearDown(): void
    {
        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY);
        putenv(ConfiguredTransferEvidence::CUTOVER_APPROVAL);
        putenv(ConfiguredTransferEvidence::CUTOVER_MANIFEST);
        putenv(ConfiguredTransferEvidence::OPERATOR_ID);
        if ($this->approvalDirectory !== null) {
            foreach (glob($this->approvalDirectory . '/*') ?: [] as $path) unlink($path);
            rmdir($this->approvalDirectory);
        }
        parent::tearDown();
    }

    public function testLaterCommandsCannotGuessThePreparedEvidenceDirectory(): void
    {
        $this->expectExceptionMessage('transfer_private_directory_not_configured');
        ConfiguredTransferEvidence::privateDirectory();
    }

    public function testCutoverApprovalMustMatchTheSeparatelyConfiguredEvidenceHash(): void
    {
        [$path, $digest] = $this->approvalFile();
        putenv(ConfiguredTransferEvidence::CUTOVER_APPROVAL . '=' . $digest);
        putenv(ConfiguredTransferEvidence::CUTOVER_MANIFEST . '=' . $path);
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=migration-operator-01');
        self::assertInstanceOf(
            CutoverApprovalManifest::class,
            ConfiguredTransferEvidence::assertCutoverApproval($digest),
        );

        $this->expectExceptionMessage('cutover_approval_not_configured_or_changed');
        ConfiguredTransferEvidence::assertCutoverApproval(str_repeat('b', 64));
    }

    public function testOperatorIdentityIsStableAcrossCliProcessesAndCannotBeGuessed(): void
    {
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=rehearsal-owner-01');
        self::assertSame('rehearsal-owner-01', ConfiguredTransferEvidence::operatorId());
        self::assertSame('rehearsal-owner-01', ConfiguredTransferEvidence::operatorId());

        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=operator with spaces');
        $this->expectExceptionMessage('transfer_operator_id_not_configured_or_invalid');
        ConfiguredTransferEvidence::operatorId();
    }

    public function testCutoverApprovalRejectsAChangedOperatorAndAnyPostApprovalFileMutation(): void
    {
        [$path, $digest] = $this->approvalFile();
        putenv(ConfiguredTransferEvidence::CUTOVER_APPROVAL . '=' . $digest);
        putenv(ConfiguredTransferEvidence::CUTOVER_MANIFEST . '=' . $path);
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=different-operator');

        try {
            ConfiguredTransferEvidence::assertCutoverApproval($digest);
            self::fail('A different operator used the approval.');
        } catch (\RuntimeException $exception) {
            self::assertSame('cutover_approval_operator_changed', $exception->getMessage());
        }

        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=migration-operator-01');
        $changed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $changed['approval_reference'] = str_repeat('f', 64);
        file_put_contents($path, CanonicalJson::encode($changed) . "\n");

        $this->expectExceptionMessage('cutover_approval_not_configured_or_changed');
        ConfiguredTransferEvidence::assertCutoverApproval($digest);
    }

    /** @return array{string,string} */
    private function approvalFile(): array
    {
        $this->approvalDirectory = realpath(sys_get_temp_dir()) . '/cartshift-cutover-approval-' . bin2hex(random_bytes(8));
        mkdir($this->approvalDirectory, 0700);
        $path = $this->approvalDirectory . '/cutover-preflight.json';
        $data = [
            'version' => 1,
            'status' => 'owner_approved_for_cutover',
            'source_key' => 'shop-alpha',
            'operator_id' => 'migration-operator-01',
            'approved_at_utc' => '2026-08-11T05:00:00Z',
            'approval_reference' => str_repeat('a', 64),
            'candidate_zip_sha256' => str_repeat('1', 64),
            'package_sha256' => str_repeat('2', 64),
            'decision_sha256' => str_repeat('3', 64),
            'selection_sha256' => str_repeat('4', 64),
            'source_backup_sha256' => str_repeat('5', 64),
            'target_backup_sha256' => str_repeat('6', 64),
            'source_files_backup_sha256' => str_repeat('7', 64),
            'target_files_backup_sha256' => str_repeat('8', 64),
            'evidence_manifest_sha256' => str_repeat('9', 64),
            'installed_contracts_sha256' => str_repeat('a', 64),
            'failure_matrix_sha256' => str_repeat('b', 64),
            'rollback_restoration_sha256' => str_repeat('c', 64),
            'rehearsal_reports' => [
                'empty_target' => str_repeat('d', 64),
                'populated_target' => str_repeat('e', 64),
                'repeat' => str_repeat('f', 64),
                'rollback' => str_repeat('0', 64),
            ],
            'fingerprints' => [
                'source_runtime_sha256' => str_repeat('1', 64),
                'target_runtime_sha256' => str_repeat('2', 64),
                'settings_sha256' => str_repeat('3', 64),
                'gateway_sha256' => str_repeat('4', 64),
                'target_state_sha256' => str_repeat('5', 64),
            ],
            'maintenance' => [
                'source_checkout_frozen' => true,
                'target_access_restricted' => true,
                'cron_paused' => true,
                'workers_paused' => true,
                'mail_paused' => true,
                'webhooks_paused' => true,
                'payment_callbacks_paused' => true,
                'fulfilment_paused' => true,
                'entitlements_paused' => true,
                'integrations_paused' => true,
            ],
            'in_flight' => [
                'orders' => 0,
                'renewals' => 0,
                'refunds' => 0,
                'payment_callbacks' => 0,
                'cartshift_runs' => 0,
            ],
            'preflight_checks' => [
                'backups_verified' => true,
                'compatibility_ready' => true,
                'decision_validated' => true,
                'dependency_closure_closed' => true,
                'fingerprints_match_rehearsal' => true,
                'lock_available' => true,
                'package_validated' => true,
                'rollback_path_verified' => true,
                'target_inspection_clear' => true,
                'zero_write_audit_passed' => true,
            ],
            'blocking_findings' => 0,
            'warnings_reviewed' => true,
            'rollback_available' => true,
        ];
        file_put_contents($path, CanonicalJson::encode($data) . "\n");
        chmod($path, 0600);
        return [$path, hash_file('sha256', $path)];
    }
}
