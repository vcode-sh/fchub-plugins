<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Execution\PrivateTransferFile;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class RehearsalSourceProof
{
    public static function assertAndResolve(
        string $path,
        string $privateDirectory,
        string $descriptor,
        string $expectedProductionFingerprint,
        string $actualIsolatedFingerprint,
    ): string {
        if (!defined('CARTSHIFT_REHEARSAL_ISOLATED') || CARTSHIFT_REHEARSAL_ISOLATED !== true) {
            throw new \RuntimeException('rehearsal_source_proof_requires_isolated_runtime');
        }
        $private = PrivateTransferFile::directory($privateDirectory);
        $canonical = realpath($path);
        if ($path === '' || $path[0] !== '/' || is_link($path) || $canonical === false
            || !is_file($canonical) || dirname($canonical) !== $private || (fileperms($canonical) & 0077) !== 0) {
            throw new \RuntimeException('rehearsal_source_proof_not_private');
        }
        $bytes = file_get_contents($canonical);
        if (!is_string($bytes)) throw new \RuntimeException('rehearsal_source_proof_unreadable');
        $proof = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $keys = is_array($proof) ? array_keys($proof) : [];
        sort($keys, SORT_STRING);
        $expectedKeys = [
            'descriptor', 'isolated_source_instance_fingerprint', 'production_source_instance_fingerprint',
            'project', 'restore_report', 'restore_report_sha256', 'source_backup_sha256', 'version',
        ];
        if (!is_array($proof) || $keys !== $expectedKeys || ($proof['version'] ?? null) !== 1
            || preg_match('/\Acartshift-lapka-(empty|populated|repeat|rollback)-[a-z0-9][a-z0-9-]{5,47}\z/D', (string) ($proof['project'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($proof['restore_report_sha256'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($proof['source_backup_sha256'] ?? '')) !== 1) {
            throw new \RuntimeException('rehearsal_source_proof_invalid');
        }
        if (!hash_equals($descriptor, (string) $proof['descriptor'])
            || !hash_equals($expectedProductionFingerprint, (string) $proof['production_source_instance_fingerprint'])
            || !hash_equals($actualIsolatedFingerprint, (string) $proof['isolated_source_instance_fingerprint'])) {
            throw new \RuntimeException('rehearsal_source_proof_identity_changed');
        }
        $reportName = (string) $proof['restore_report'];
        if (basename($reportName) !== $reportName || preg_match('/\A[a-z0-9-]+-restore\.json\z/D', $reportName) !== 1) {
            throw new \RuntimeException('rehearsal_source_proof_restore_report_invalid');
        }
        $reportPath = $private . '/' . $reportName;
        if (is_link($reportPath) || !is_file($reportPath) || (fileperms($reportPath) & 0077) !== 0) {
            throw new \RuntimeException('rehearsal_source_proof_restore_report_invalid');
        }
        $reportBytes = file_get_contents($reportPath);
        if (!is_string($reportBytes) || !hash_equals(hash('sha256', $reportBytes), (string) $proof['restore_report_sha256'])) {
            throw new \RuntimeException('rehearsal_source_proof_restore_report_changed');
        }
        $report = json_decode($reportBytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($report) || ($report['status'] ?? null) !== 'restored'
            || !hash_equals((string) $proof['project'], (string) ($report['project'] ?? ''))
            || !hash_equals((string) $proof['source_backup_sha256'], (string) ($report['source_backup_sha256'] ?? ''))
            || ($report['cron'] ?? null) !== false || ($report['outbound_network'] ?? null) !== false
            || ($report['mail'] ?? null) !== false || ($report['payment_gateways'] ?? null) !== false) {
            throw new \RuntimeException('rehearsal_source_proof_restore_report_invalid');
        }
        $canonicalProof = CanonicalJson::encode($proof) . "\n";
        if (!hash_equals($canonicalProof, $bytes)) throw new \RuntimeException('rehearsal_source_proof_not_canonical');
        return $expectedProductionFingerprint;
    }
}
