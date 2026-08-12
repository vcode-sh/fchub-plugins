<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\MutationSpy;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class ZeroWriteAuditTest extends InstalledContractTestCase
{
    public function testMutationSpyDetectsLegacyDryRunWrites(): void
    {
        $result = $this->runRuntimeContract('legacy-dry-run-negative-control');

        self::assertTrue($result['mutation_detected']);
        self::assertSame('Audit attempted mutating SQL.', $result['message']);
    }

    public function testTransferAuditLeavesEveryObservedSurfaceUnchanged(): void
    {
        $result = $this->runRuntimeContract('zero-write-audit');

        self::assertTrue($result['unchanged']);
        self::assertTrue($result['ready']);
        self::assertSame($result['before_fingerprint'], $result['after_fingerprint']);
        self::assertSame(
            [
                'action_scheduler' => 0,
                'events' => 0,
                'http' => 0,
                'mail' => 0,
                'payment' => 0,
                'stock' => 0,
            ],
            $result['outgoing'],
        );
    }
}
