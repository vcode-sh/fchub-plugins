<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Contract;

require_once __DIR__ . '/InstalledContractTestCase.php';

final class CliSynopsisContractTest extends InstalledContractTestCase
{
    public function testFixedRoleCommandsAreAcceptedByTheRealWpCliParser(): void
    {
        $audit = $this->runWpCliCommand([
            'cartshift', 'transfer', 'audit',
            '--role=source',
            '--source-key=contract-source',
            '--products=none',
            '--customers=none',
            '--orders=none',
            '--subscriptions=none',
            '--format=json',
        ]);

        self::assertSame(0, $audit['status'], $audit['stderr']);
        self::assertStringNotContainsString('invalid synopsis', $audit['stderr']);
        self::assertStringNotContainsString('unknown --role parameter', $audit['stderr']);
        self::assertSame('contract-source', json_decode($audit['stdout'], true, flags: JSON_THROW_ON_ERROR)['source_key']);

        $inspection = $this->runWpCliCommand([
            'cartshift', 'transfer', 'inspect-target',
            '--role=target',
            '--source-key=contract-source',
            '--format=json',
        ]);

        self::assertSame(0, $inspection['status'], $inspection['stderr']);
        self::assertStringNotContainsString('invalid synopsis', $inspection['stderr']);
        self::assertStringNotContainsString('unknown --role parameter', $inspection['stderr']);
        self::assertSame('contract-source', json_decode($inspection['stdout'], true, flags: JSON_THROW_ON_ERROR)['source_key']);
    }
}
