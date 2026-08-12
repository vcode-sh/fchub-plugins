<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Transfer;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class TargetOwnershipContractTest extends InstalledContractTestCase
{
    public function testInstalledTargetInspectionIsStrictlyReadOnly(): void
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

    public function testDatabaseUniquenessChoosesOneExclusiveOwnerAcrossTwoConnections(): void
    {
        $result = $this->runRuntimeContract('target-claim-race');

        self::assertTrue($result['connections_distinct']);
        self::assertTrue($result['winner']);
        self::assertTrue($result['loser']);
        self::assertSame(1, $result['claim_count']);
        self::assertSame(0, $result['loser_map_count']);
        self::assertSame(0, $result['loser_outbox_count']);
        self::assertSame(2, $result['shared_links']);
    }
}
