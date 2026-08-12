<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Transfer;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class SchemaUpgradeContractTest extends InstalledContractTestCase
{
    public function testV8IsExplicitAndEveryInstalledPostconditionPasses(): void
    {
        $result = $this->runRuntimeContract('schema-upgrade-v8');

        self::assertSame('7', $result['before']);
        self::assertSame('8', $result['after']);
        self::assertTrue($result['upgraded']);
        self::assertTrue($result['target_ready']);
        self::assertSame([], $result['target_errors']);
    }
}
