<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\CLI;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class TransferCommandTest extends InstalledContractTestCase
{
    public function testInstalledMariaDbJournalCommitsRecordAndOutboxAsOneRecoverableFact(): void
    {
        $result = $this->runRuntimeContract('transfer-execution-journal-contract');

        self::assertSame('failed', $result['state']);
        self::assertSame(1, $result['attempt']);
        self::assertTrue($result['receipt_round_trip']);
        self::assertSame(1, $result['journal_rows']);
        self::assertSame(1, $result['outbox_rows']);
        self::assertSame(0, $result['pending_after_export']);
        self::assertFalse($result['payload_leaks_private_fixture']);
    }

    public function testInstalledWpCliHelpExposesTheSealedV2GrammarWithoutOverrides(): void
    {
        foreach ([
            'prepare' => ['--package=<absolute-path>', '--decision-set=<absolute-path>', '--private-dir=<absolute-path>', '--execution-context=<rehearsal|cutover>'],
            'stage' => ['--package=<absolute-path>', '--descriptor=<id>', '--confirm=<selection-fingerprint>', '--execution-context=<rehearsal|cutover>', '--batch-size=<positive-int>', '--lease-recovery=<sha256>'],
            'reconcile' => ['--lease-recovery=<sha256>'],
            'activate-catalogue' => ['--lease-recovery=<sha256>'],
            'rollback' => ['--rollback-plan=<absolute-path>', '--cutover-approval=<sha256>'],
            'status' => ['--descriptor=<id>'],
        ] as $command => $expected) {
            $result = $this->runWpCliCommand(['help', 'cartshift', 'transfer', $command]);
            self::assertSame(0, $result['status'], $result['stdout'] . "\n" . $result['stderr']);
            foreach ($expected as $option) self::assertStringContainsString($option, $result['stdout'], $command);
            self::assertStringNotContainsString('--force', $result['stdout']);
            self::assertStringNotContainsString('--ignore-errors', $result['stdout']);
            self::assertStringNotContainsString('--skip-reconcile', $result['stdout']);
        }
    }
}
