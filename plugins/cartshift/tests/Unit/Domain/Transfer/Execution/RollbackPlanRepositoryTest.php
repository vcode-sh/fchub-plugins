<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\RollbackPlan;
use CartShift\Domain\Transfer\Execution\RollbackPlanRepository;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Tests\Unit\PluginTestCase;

final class RollbackPlanRepositoryTest extends PluginTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/cartshift-rollback-plan-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        chmod($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $file) {
            unlink($this->directory . '/' . $file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testPlanIsPrivateCanonicalImmutableAndFingerprintCheckedOnLoad(): void
    {
        $receipt = new TransferReceipt(
            'run-task-22',
            'product',
            'shop-alpha:product:10',
            1,
            str_repeat('1', 64),
            'created',
            ['primary' => 700],
            null,
            str_repeat('2', 64),
            1,
            '2026-08-10T12:00:00Z',
            '2026-08-10T12:00:01Z',
        );
        $reused = new TransferReceipt(
            'run-task-22',
            'customer',
            'shop-alpha:customer:11',
            1,
            str_repeat('3', 64),
            'reused',
            ['primary' => 701],
            str_repeat('4', 64),
            str_repeat('4', 64),
            2,
            '2026-08-10T12:00:02Z',
            '2026-08-10T12:00:03Z',
        );
        $plan = new RollbackPlan('run-task-22', 1, [[
            'source_identity' => $receipt->sourceIdentity,
            'receipt' => $receipt,
        ]], [], true, [[
            'source_identity' => $reused->sourceIdentity,
            'receipt' => $reused,
        ]]);
        $repository = new RollbackPlanRepository($this->directory);

        $path = $repository->save($plan);

        self::assertSame($plan->fingerprint(), $repository->get($path)->fingerprint());
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame($path, $repository->save($plan));

        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        file_put_contents($path, str_replace($plan->fingerprint(), str_repeat('f', 64), $bytes));

        $this->expectExceptionMessage('rollback_plan_fingerprint_mismatch');
        $repository->get($path);
    }
}
