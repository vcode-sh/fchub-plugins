<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\FilesystemSagaRepository;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class FilesystemSagaRepositoryTest extends PluginTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-filesystem-saga-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testPendingOperationSurvivesCrashAndCanOnlyReachOneVerifiedTerminalState(): void
    {
        $repository = new FilesystemSagaRepository($this->root);
        $target = $this->root . '/target.bin';
        $contents = "durable asset\n";
        $hash = hash('sha256', $contents);

        $operation = $repository->begin('run-saga-22', 1, 'download', $hash, strlen($contents), 'target.bin', $target);

        self::assertSame('pending', $repository->state('run-saga-22', $operation));
        self::assertSame($operation, $repository->begin('run-saga-22', 1, 'download', $hash, strlen($contents), 'target.bin', $target));

        file_put_contents($target, $contents);
        chmod($target, 0600);
        $repository->finalise('run-saga-22', $operation, $target);

        self::assertSame('final', $repository->state('run-saga-22', $operation));
        try {
            $repository->revert('run-saga-22', $operation, $target);
            self::fail('A final operation was also marked reverted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('filesystem_saga_revert_requires_pending', $exception->getMessage());
        }
    }

    public function testRevertRequiresTheOwnedTargetToBeAbsentAndNewGenerationGetsNewOperation(): void
    {
        $repository = new FilesystemSagaRepository($this->root);
        $target = $this->root . '/target.bin';
        $contents = 'pending';
        $hash = hash('sha256', $contents);
        $first = $repository->begin('run-saga-22', 1, 'media', $hash, strlen($contents), 'target.bin', $target);

        $repository->revert('run-saga-22', $first, $target);

        self::assertSame('reverted', $repository->state('run-saga-22', $first));
        $second = $repository->begin('run-saga-22', 2, 'media', $hash, strlen($contents), 'target.bin', $target);
        self::assertNotSame($first, $second);
        self::assertSame('pending', $repository->state('run-saga-22', $second));
        $this->expectExceptionMessage('filesystem_saga_generation_already_reverted');
        $repository->begin('run-saga-22', 1, 'media', $hash, strlen($contents), 'target.bin', $target);
    }

    public function testPendingOperationCanBeFinalisedByReceiptOperationIdAfterProcessLoss(): void
    {
        $repository = new FilesystemSagaRepository($this->root);
        $target = $this->root . '/recovered.bin';
        $contents = "committed before the process died\n";
        $operation = $repository->begin(
            'run-saga-22',
            1,
            'download',
            hash('sha256', $contents),
            strlen($contents),
            'recovered.bin',
            $target,
        );
        file_put_contents($target, $contents);

        $reopened = new FilesystemSagaRepository($this->root);
        $reopened->finalisePending('run-saga-22', $operation);

        self::assertSame('final', $reopened->state('run-saga-22', $operation));
    }

    public function testCommittedFileRollbackHasItsOwnTerminalEvidenceAndCannotBeRelabelledReverted(): void
    {
        $repository = new FilesystemSagaRepository($this->root);
        $target = $this->root . '/rolled-back.bin';
        $contents = 'committed asset';
        $hash = hash('sha256', $contents);
        $operation = $repository->begin('run-saga-22', 1, 'media', $hash, strlen($contents), 'rolled-back.bin', $target);
        file_put_contents($target, $contents);
        $repository->finalise('run-saga-22', $operation, $target);
        unlink($target);

        try {
            $repository->revert('run-saga-22', $operation, $target);
            self::fail('A committed operation was relabelled as an uncommitted revert.');
        } catch (\RuntimeException $exception) {
            self::assertSame('filesystem_saga_revert_requires_pending', $exception->getMessage());
        }

        $repository->markRolledBack('run-saga-22', $operation);
        $repository->markRolledBack('run-saga-22', $operation);

        self::assertSame('rolled_back', $repository->state('run-saga-22', $operation));
        try {
            $repository->finalisePending('run-saga-22', $operation);
            self::fail('A rolled-back generation was revived.');
        } catch (\RuntimeException $exception) {
            self::assertSame('filesystem_saga_generation_already_rolled_back', $exception->getMessage());
        }
        $next = $repository->begin('run-saga-22', 2, 'media', $hash, strlen($contents), 'rolled-back.bin', $target);
        self::assertNotSame($operation, $next);
    }

    public function testFinalFileRollbackIsQuarantinedAndRestoredWhenDatabaseTransactionRollsBack(): void
    {
        $repository = new FilesystemSagaRepository($this->root);
        $target = $this->root . '/transactional.bin';
        $contents = 'restore me exactly';
        $operation = $repository->begin(
            'run-saga-22',
            1,
            'download',
            hash('sha256', $contents),
            strlen($contents),
            'transactional.bin',
            $target,
        );
        file_put_contents($target, $contents);
        $repository->finalise('run-saga-22', $operation, $target);

        \CartShift\Support\DatabaseTransaction::begin();
        $repository->quarantineFinalisedTarget('run-saga-22', $operation);
        self::assertFileDoesNotExist($target);
        \CartShift\Support\DatabaseTransaction::rollback();

        self::assertFileExists($target);
        self::assertSame($contents, file_get_contents($target));
        self::assertSame('final', $repository->state('run-saga-22', $operation));
    }

    public function testFinalFileRollbackDeletesQuarantineOnlyAfterDatabaseCommit(): void
    {
        $repository = new FilesystemSagaRepository($this->root);
        $target = $this->root . '/committed.bin';
        $contents = 'remove me after commit';
        $operation = $repository->begin(
            'run-saga-22',
            1,
            'media',
            hash('sha256', $contents),
            strlen($contents),
            'committed.bin',
            $target,
        );
        file_put_contents($target, $contents);
        $repository->finalise('run-saga-22', $operation, $target);

        \CartShift\Support\DatabaseTransaction::begin();
        $repository->quarantineFinalisedTarget('run-saga-22', $operation);
        \CartShift\Support\DatabaseTransaction::commit();

        self::assertFileDoesNotExist($target);
        self::assertSame('rolled_back', $repository->state('run-saga-22', $operation));
    }

    public function testCommittedRollbackPrunesOnlyEmptyParentDirectoriesOwnedByTheSaga(): void
    {
        $repository = new FilesystemSagaRepository($this->root);
        $uploads = $this->root . '/uploads';
        mkdir($uploads, 0700);
        $target = $uploads . '/cartshift-staging/run-saga-22/asset.bin';
        $contents = 'remove owned directories';
        $operation = $repository->begin(
            'run-saga-22',
            1,
            'media',
            hash('sha256', $contents),
            strlen($contents),
            'cartshift-staging/run-saga-22/asset.bin',
            $target,
        );
        mkdir(dirname($target), 0700, true);
        file_put_contents($target, $contents);
        $repository->finalise('run-saga-22', $operation, $target);

        DatabaseTransaction::begin();
        $repository->quarantineFinalisedTarget('run-saga-22', $operation);
        DatabaseTransaction::commit();
        $repository->completeQuarantinedRollback('run-saga-22', $operation);

        self::assertDirectoryDoesNotExist($uploads . '/cartshift-staging/run-saga-22');
        self::assertDirectoryDoesNotExist($uploads . '/cartshift-staging');
        self::assertDirectoryExists($uploads);

        $preExisting = $uploads . '/fluent-cart';
        mkdir($preExisting, 0700);
        $target = $preExisting . '/manual.pdf';
        $contents = 'preserve pre-existing directory';
        $operation = $repository->begin(
            'run-saga-23',
            1,
            'download',
            hash('sha256', $contents),
            strlen($contents),
            'manual.pdf',
            $target,
        );
        file_put_contents($target, $contents);
        $repository->finalise('run-saga-23', $operation, $target);

        DatabaseTransaction::begin();
        $repository->quarantineFinalisedTarget('run-saga-23', $operation);
        DatabaseTransaction::commit();
        $repository->completeQuarantinedRollback('run-saga-23', $operation);

        self::assertDirectoryExists($preExisting);
    }

    public function testRollbackQuarantineRefusesDriftBeforeMovingAnyBytes(): void
    {
        $repository = new FilesystemSagaRepository($this->root);
        $target = $this->root . '/drifted.bin';
        $contents = 'approved';
        $operation = $repository->begin(
            'run-saga-22',
            1,
            'media',
            hash('sha256', $contents),
            strlen($contents),
            'drifted.bin',
            $target,
        );
        file_put_contents($target, $contents);
        $repository->finalise('run-saga-22', $operation, $target);
        file_put_contents($target, 'operator replacement');

        \CartShift\Support\DatabaseTransaction::begin();
        try {
            $repository->quarantineFinalisedTarget('run-saga-22', $operation);
            self::fail('A drifted file was moved out of the live uploads tree.');
        } catch (\RuntimeException $exception) {
            self::assertSame('filesystem_saga_final_target_mismatch', $exception->getMessage());
        } finally {
            \CartShift\Support\DatabaseTransaction::rollback();
        }

        self::assertSame('operator replacement', file_get_contents($target));
        self::assertSame('final', $repository->state('run-saga-22', $operation));
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
