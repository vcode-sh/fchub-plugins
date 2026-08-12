<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\CutoverApprovalManifest;
use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

final class CutoverApprovalManifestTest extends PluginTestCase
{
    public function testOnlyAClosedFullyGreenPreflightCanBecomeCutoverApproval(): void
    {
        $manifest = CutoverApprovalManifest::fromArray($this->validData());

        self::assertSame('shop-alpha', $manifest->sourceKey);
        self::assertSame(str_repeat('b', 64), $manifest->targetBackupHash);

        foreach ([
            ['blocking_findings', 1],
            ['warnings_reviewed', false],
            ['rollback_available', false],
            ['maintenance.cron_paused', false],
            ['in_flight.renewals', 1],
            ['preflight_checks.fingerprints_match_rehearsal', false],
        ] as [$field, $value]) {
            $data = $this->validData();
            $path = explode('.', $field);
            if (count($path) === 1) {
                $data[$path[0]] = $value;
            } else {
                $data[$path[0]][$path[1]] = $value;
            }

            try {
                CutoverApprovalManifest::fromArray($data);
                self::fail($field . ' was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('cutover_approval_stop_gate_not_clear', $exception->getMessage(), $field);
            }
        }
    }

    public function testUnknownOrMissingEvidenceCannotHideOutsideTheClosedContract(): void
    {
        $extra = $this->validData();
        $extra['trust_me'] = true;

        try {
            CutoverApprovalManifest::fromArray($extra);
            self::fail('An unknown approval field was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('cutover_approval_manifest_shape_invalid', $exception->getMessage());
        }

        $missing = $this->validData();
        unset($missing['failure_matrix_sha256']);

        $this->expectExceptionMessage('cutover_approval_manifest_shape_invalid');
        CutoverApprovalManifest::fromArray($missing);
    }

    public function testApprovalIsBoundToEveryPreparedTransferIdentityBoundary(): void
    {
        $prepared = $this->prepared();
        CutoverApprovalManifest::fromArray($this->validData())->assertPrepared($prepared);
        self::addToAssertionCount(1);

        foreach ([
            'source_key' => 'other-shop',
            'package_sha256' => str_repeat('9', 64),
            'decision_sha256' => str_repeat('9', 64),
            'selection_sha256' => str_repeat('9', 64),
            'fingerprints.settings_sha256' => str_repeat('9', 64),
            'fingerprints.gateway_sha256' => str_repeat('9', 64),
            'fingerprints.target_state_sha256' => str_repeat('9', 64),
        ] as $field => $value) {
            $data = $this->validData();
            $path = explode('.', $field);
            if (count($path) === 1) {
                $data[$path[0]] = $value;
            } else {
                $data[$path[0]][$path[1]] = $value;
            }

            try {
                CutoverApprovalManifest::fromArray($data)->assertPrepared($prepared);
                self::fail($field . ' drift was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('cutover_approval_prepared_transfer_changed:' . $field, $exception->getMessage());
            }
        }
    }

    public function testSchemaUpgradeApprovalIsBoundToTheExactTargetBackup(): void
    {
        $manifest = CutoverApprovalManifest::fromArray($this->validData());
        $manifest->assertSchemaUpgrade('7', '8', str_repeat('b', 64));
        self::addToAssertionCount(1);

        foreach ([
            ['6', '8', str_repeat('b', 64)],
            ['7', '9', str_repeat('b', 64)],
            ['7', '8', str_repeat('9', 64)],
        ] as [$from, $to, $backup]) {
            try {
                $manifest->assertSchemaUpgrade($from, $to, $backup);
                self::fail('An unapproved schema upgrade boundary was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('cutover_approval_schema_upgrade_changed', $exception->getMessage());
            }
        }
    }

    public function testManifestFileMustBePrivateCanonicalJsonAndCannotHideInvalidNestedHashes(): void
    {
        $directory = realpath(sys_get_temp_dir()) . '/cartshift-cutover-manifest-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/cutover-preflight.json';
        try {
            file_put_contents($path, json_encode($this->validData(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            chmod($path, 0600);
            try {
                CutoverApprovalManifest::fromFile($path);
                self::fail('Non-canonical approval JSON was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('cutover_approval_manifest_not_canonical', $exception->getMessage());
            }

            file_put_contents($path, CanonicalJson::encode($this->validData()) . "\n");
            chmod($path, 0644);
            try {
                CutoverApprovalManifest::fromFile($path);
                self::fail('A world-readable approval manifest was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('cutover_approval_manifest_path_invalid', $exception->getMessage());
            }

            $invalid = $this->validData();
            $invalid['rehearsal_reports']['repeat'] = strtoupper(str_repeat('a', 64));
            $this->expectExceptionMessage('cutover_approval_manifest_value_invalid');
            CutoverApprovalManifest::fromArray($invalid);
        } finally {
            if (is_file($path)) unlink($path);
            rmdir($directory);
        }
    }

    /** @return array<string,mixed> */
    private function validData(): array
    {
        return [
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
            'target_backup_sha256' => str_repeat('b', 64),
            'source_files_backup_sha256' => str_repeat('6', 64),
            'target_files_backup_sha256' => str_repeat('7', 64),
            'evidence_manifest_sha256' => str_repeat('8', 64),
            'installed_contracts_sha256' => str_repeat('c', 64),
            'failure_matrix_sha256' => str_repeat('d', 64),
            'rollback_restoration_sha256' => str_repeat('e', 64),
            'rehearsal_reports' => [
                'empty_target' => str_repeat('f', 64),
                'populated_target' => str_repeat('0', 64),
                'repeat' => str_repeat('a', 64),
                'rollback' => str_repeat('b', 64),
            ],
            'fingerprints' => [
                'source_runtime_sha256' => str_repeat('c', 64),
                'target_runtime_sha256' => str_repeat('d', 64),
                'settings_sha256' => str_repeat('5', 64),
                'gateway_sha256' => str_repeat('6', 64),
                'target_state_sha256' => str_repeat('7', 64),
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
    }

    private function prepared(): PreparedTransfer
    {
        return new PreparedTransfer(
            'tr-' . str_repeat('a', 24),
            '/srv/private/package',
            str_repeat('2', 64),
            new TargetStateFingerprint(
                str_repeat('2', 64),
                str_repeat('3', 64),
                str_repeat('4', 64),
                str_repeat('5', 64),
                str_repeat('6', 64),
                str_repeat('4', 64),
                str_repeat('7', 64),
            ),
            'cutover',
            [],
            false,
            '2026-08-11T05:00:00Z',
            'shop-alpha',
        );
    }
}
