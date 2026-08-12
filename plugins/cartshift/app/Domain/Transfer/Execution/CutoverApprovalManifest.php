<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Owner-sealed Task 28 preflight. It authorises no identity other than the hashes it contains. */
final readonly class CutoverApprovalManifest
{
    /** @param array<string,string> $fingerprints */
    private function __construct(
        public string $sourceKey,
        public string $operatorId,
        public string $approvedAtUtc,
        public string $packageHash,
        public string $decisionHash,
        public string $selectionHash,
        public string $targetBackupHash,
        public array $fingerprints,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data, [
            'approval_reference', 'approved_at_utc', 'blocking_findings', 'candidate_zip_sha256',
            'decision_sha256', 'evidence_manifest_sha256', 'failure_matrix_sha256', 'fingerprints',
            'in_flight', 'installed_contracts_sha256', 'maintenance', 'operator_id', 'package_sha256',
            'preflight_checks', 'rehearsal_reports', 'rollback_available', 'rollback_restoration_sha256', 'selection_sha256',
            'source_backup_sha256', 'source_files_backup_sha256', 'source_key', 'status',
            'target_backup_sha256', 'target_files_backup_sha256', 'version', 'warnings_reviewed',
        ]);
        if (($data['version'] ?? null) !== 1
            || ($data['status'] ?? null) !== 'owner_approved_for_cutover'
            || !is_string($data['source_key'] ?? null)
            || !is_string($data['operator_id'] ?? null)
            || preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/D', $data['operator_id']) !== 1
            || !is_string($data['approved_at_utc'] ?? null)) {
            throw new \InvalidArgumentException('cutover_approval_manifest_value_invalid');
        }
        SourceIdentity::assertValidSourceKey($data['source_key']);
        self::assertUtc($data['approved_at_utc']);

        foreach ([
            'approval_reference', 'candidate_zip_sha256', 'package_sha256', 'decision_sha256',
            'selection_sha256', 'source_backup_sha256', 'target_backup_sha256',
            'source_files_backup_sha256', 'target_files_backup_sha256', 'evidence_manifest_sha256',
            'installed_contracts_sha256', 'failure_matrix_sha256', 'rollback_restoration_sha256',
        ] as $field) {
            self::assertHash($data[$field] ?? null);
        }

        if (!is_array($data['rehearsal_reports'] ?? null)) {
            throw new \InvalidArgumentException('cutover_approval_manifest_value_invalid');
        }
        self::assertExactKeys($data['rehearsal_reports'], ['empty_target', 'populated_target', 'repeat', 'rollback']);
        foreach ($data['rehearsal_reports'] as $hash) self::assertHash($hash);

        if (!is_array($data['fingerprints'] ?? null)) {
            throw new \InvalidArgumentException('cutover_approval_manifest_value_invalid');
        }
        self::assertExactKeys($data['fingerprints'], [
            'gateway_sha256', 'settings_sha256', 'source_runtime_sha256',
            'target_runtime_sha256', 'target_state_sha256',
        ]);
        foreach ($data['fingerprints'] as $hash) self::assertHash($hash);

        if (!is_array($data['maintenance'] ?? null)) {
            throw new \InvalidArgumentException('cutover_approval_manifest_value_invalid');
        }
        self::assertExactKeys($data['maintenance'], [
            'cron_paused', 'entitlements_paused', 'fulfilment_paused', 'integrations_paused',
            'mail_paused', 'payment_callbacks_paused', 'source_checkout_frozen',
            'target_access_restricted', 'webhooks_paused', 'workers_paused',
        ]);

        if (!is_array($data['in_flight'] ?? null)) {
            throw new \InvalidArgumentException('cutover_approval_manifest_value_invalid');
        }
        self::assertExactKeys($data['in_flight'], [
            'cartshift_runs', 'orders', 'payment_callbacks', 'refunds', 'renewals',
        ]);

        if (!is_array($data['preflight_checks'] ?? null)) {
            throw new \InvalidArgumentException('cutover_approval_manifest_value_invalid');
        }
        self::assertExactKeys($data['preflight_checks'], [
            'backups_verified', 'compatibility_ready', 'decision_validated',
            'dependency_closure_closed', 'fingerprints_match_rehearsal', 'lock_available',
            'package_validated', 'rollback_path_verified', 'target_inspection_clear',
            'zero_write_audit_passed',
        ]);

        if (($data['blocking_findings'] ?? null) !== 0
            || ($data['warnings_reviewed'] ?? null) !== true
            || ($data['rollback_available'] ?? null) !== true
            || array_filter($data['maintenance'], static fn (mixed $value): bool => $value !== true) !== []
            || array_filter($data['in_flight'], static fn (mixed $value): bool => $value !== 0) !== []
            || array_filter($data['preflight_checks'], static fn (mixed $value): bool => $value !== true) !== []) {
            throw new \InvalidArgumentException('cutover_approval_stop_gate_not_clear');
        }

        return new self(
            $data['source_key'],
            $data['operator_id'],
            $data['approved_at_utc'],
            $data['package_sha256'],
            $data['decision_sha256'],
            $data['selection_sha256'],
            $data['target_backup_sha256'],
            $data['fingerprints'],
        );
    }

    public static function fromFile(string $path): self
    {
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            throw new \RuntimeException('cutover_approval_manifest_path_invalid');
        }
        $directory = PrivateTransferFile::directory(dirname($path));
        $canonical = $directory . '/' . basename($path);
        if ($canonical !== $path || !is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException('cutover_approval_manifest_path_invalid');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) throw new \RuntimeException('cutover_approval_manifest_unreadable');
        try {
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('cutover_approval_manifest_invalid', 0, $exception);
        }
        if (!is_array($data)) throw new \RuntimeException('cutover_approval_manifest_invalid');
        try {
            $manifest = self::fromArray($data);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
        if (!hash_equals(CanonicalJson::encode($data) . "\n", $bytes)) {
            throw new \RuntimeException('cutover_approval_manifest_not_canonical');
        }
        return $manifest;
    }

    public function assertPrepared(PreparedTransfer $prepared): void
    {
        $this->assertTransferIdentity(
            $prepared->sourceKey,
            $prepared->packageHash,
            $prepared->targetState->decisionHash,
            $prepared->targetState->selectionHash,
        );
        foreach ([
            'fingerprints.settings_sha256' => [$this->fingerprints['settings_sha256'], $prepared->targetState->settingsHash],
            'fingerprints.gateway_sha256' => [$this->fingerprints['gateway_sha256'], $prepared->targetState->gatewayHash],
            'fingerprints.target_state_sha256' => [$this->fingerprints['target_state_sha256'], $prepared->targetState->targetHash],
        ] as $field => [$approved, $current]) {
            if (!hash_equals($approved, $current)) {
                throw new \RuntimeException('cutover_approval_prepared_transfer_changed:' . $field);
            }
        }
    }

    public function assertTransferIdentity(
        string $sourceKey,
        string $packageHash,
        string $decisionHash,
        string $selectionHash,
    ): void {
        foreach ([
            'source_key' => [$this->sourceKey, $sourceKey],
            'package_sha256' => [$this->packageHash, $packageHash],
            'decision_sha256' => [$this->decisionHash, $decisionHash],
            'selection_sha256' => [$this->selectionHash, $selectionHash],
        ] as $field => [$approved, $current]) {
            if (!hash_equals($approved, $current)) {
                throw new \RuntimeException('cutover_approval_prepared_transfer_changed:' . $field);
            }
        }
    }

    public function assertSchemaUpgrade(string $from, string $to, string $targetBackupHash): void
    {
        if ($from !== '7' || $to !== '8' || !hash_equals($this->targetBackupHash, $targetBackupHash)) {
            throw new \RuntimeException('cutover_approval_schema_upgrade_changed');
        }
    }

    /** @param array<string,mixed> $data @param list<string> $expected */
    private static function assertExactKeys(array $data, array $expected): void
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) throw new \InvalidArgumentException('cutover_approval_manifest_shape_invalid');
    }

    private static function assertHash(mixed $hash): void
    {
        if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new \InvalidArgumentException('cutover_approval_manifest_value_invalid');
        }
    }

    private static function assertUtc(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d\TH:i:s\Z') !== $value
            || \DateTimeImmutable::getLastErrors() !== false) {
            throw new \InvalidArgumentException('cutover_approval_manifest_value_invalid');
        }
    }
}
