<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class ConcurrentRunTest extends InstalledContractTestCase
{
    public function testOnlyOneRealWpCliProcessOwnsTheTargetAndTakeoverNeedsExpiryDescriptorAndEvidence(): void
    {
        $command = $this->wpCliProcessCommand([
            'eval-file',
            '/cartshift-source/tests/Integration/Contract/runtime-contract.php',
            'concurrent-lease-worker',
            'acquire',
            'worker-a',
            '2',
            '30',
        ]);
        $pipes = [];
        $winner = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($winner, 'The first WP-CLI process did not start.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $stdout = '';

        try {
            $deadline = microtime(true) + 10;
            while (!str_contains($stdout, 'CARTSHIFT_LEASE_ACQUIRED:worker-a') && microtime(true) < $deadline) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                usleep(50_000);
            }
            self::assertStringContainsString(
                'CARTSHIFT_LEASE_ACQUIRED:worker-a',
                $stdout,
                'The first process never proved lease ownership.',
            );

            $contender = $this->runRuntimeContractWithArguments('concurrent-lease-worker', [
                'acquire', 'worker-b', '2', '0',
            ]);
            self::assertFalse($contender['acquired']);
            self::assertSame('transfer_lease_unavailable', $contender['reason']);

            proc_terminate($winner, 9);
            proc_close($winner);
            $winner = null;

            sleep(3);
            $wrongDescriptor = $this->runRuntimeContractWithArguments('concurrent-lease-worker', [
                'recover-wrong', 'worker-b', '10', '0',
            ]);
            self::assertFalse($wrongDescriptor['acquired']);
            self::assertSame('transfer_lease_recovery_conflict', $wrongDescriptor['reason']);

            $recovered = $this->runRuntimeContractWithArguments('concurrent-lease-worker', [
                'recover', 'worker-b', '10', '0',
            ]);
            self::assertTrue($recovered['acquired']);
            self::assertNull($recovered['reason']);

            $afterRelease = $this->runRuntimeContractWithArguments('concurrent-lease-worker', [
                'acquire', 'worker-c', '10', '0',
            ]);
            self::assertTrue($afterRelease['acquired']);
        } finally {
            if (is_resource($winner)) {
                proc_terminate($winner, 9);
                proc_close($winner);
            }
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    fclose($pipes[$index]);
                }
            }
        }
    }
}
