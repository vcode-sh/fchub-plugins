<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class MutationSpyAuditTest extends InstalledContractTestCase
{
    public function testV2SourceAuditLeavesWordPressAndEveryOutgoingSinkByteIdentical(): void
    {
        $result = $this->runRuntimeContract('zero-write-audit');

        self::assertTrue($result['unchanged']);
        self::assertSame($result['before_fingerprint'], $result['after_fingerprint']);
        self::assertSame([
            'action_scheduler' => 0,
            'events' => 0,
            'http' => 0,
            'mail' => 0,
            'payment' => 0,
            'stock' => 0,
        ], $result['outgoing']);
    }

    public function testV2TargetInspectionLeavesWordPressAndEveryOutgoingSinkByteIdentical(): void
    {
        $result = $this->runRuntimeContract('target-inspection-zero-write');

        self::assertTrue($result['unchanged']);
        self::assertSame($result['before_fingerprint'], $result['after_fingerprint']);
        self::assertSame([
            'action_scheduler' => 0,
            'events' => 0,
            'http' => 0,
            'mail' => 0,
            'payment' => 0,
            'stock' => 0,
        ], $result['outgoing']);
    }

    public function testCompatibilityExportValidationInspectionAndPrepareWriteOnlyPrivateEvidence(): void
    {
        $result = $this->runRuntimeContract('zero-write-preparation-matrix');

        self::assertTrue($result['unchanged']);
        self::assertSame($result['before_fingerprint'], $result['after_fingerprint']);
        self::assertTrue($result['source_ready']);
        self::assertTrue($result['target_ready']);
        self::assertTrue($result['audit_ready']);
        self::assertTrue($result['package_validated']);
        self::assertTrue($result['inspection_fingerprinted']);
        self::assertSame('prepared', $result['prepared_state']);
        self::assertTrue($result['selection_fingerprint_matches']);
        self::assertGreaterThanOrEqual(7, $result['external_file_count']);
        self::assertSame([
            'action_scheduler' => 0,
            'events' => 0,
            'http' => 0,
            'mail' => 0,
            'payment' => 0,
            'stock' => 0,
        ], $result['outgoing']);
    }

    public function testRetainedLegacyDryRunIsARealMutationSpyNegativeControl(): void
    {
        $result = $this->runRuntimeContract('legacy-dry-run-negative-control');

        self::assertTrue($result['mutation_detected']);
        self::assertSame('Audit attempted mutating SQL.', $result['message']);
    }
}
