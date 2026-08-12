<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Domain\Transfer\Execution\FilesystemSagaRepository;
use CartShift\Domain\Transfer\Execution\TransferCoordinator;
use CartShift\Domain\Transfer\Execution\TransferRunState;
use CartShift\Domain\Transfer\Package\AssetExporter;
use CartShift\Domain\Transfer\Package\AuthenticatedAssetSourceAdapter;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordWriter;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\StageResult;
use CartShift\Support\DatabaseTransaction;
use PHPUnit\Framework\Attributes\DataProvider;

final class FilesystemFailureTest extends FailureTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-failure-matrix-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testAssetStreamFailureRemovesEveryTemporaryAndPartialFile(): void
    {
        if (!in_array('cartshift-failing-stream', stream_get_wrappers(), true)) {
            stream_wrapper_register('cartshift-failing-stream', FailingAssetStream::class);
        }
        $package = $this->root . '/package';
        $uploads = $this->root . '/uploads';
        mkdir($package, 0700);
        mkdir($uploads, 0700);
        $adapter = new class implements AuthenticatedAssetSourceAdapter {
            public function open(string $locator): mixed
            {
                return fopen('cartshift-failing-stream://fixture', 'rb');
            }

            public function originalName(string $locator): string
            {
                return 'contract.pdf';
            }
        };

        try {
            (new AssetExporter($package, $uploads, ['authenticated' => $adapter]))
                ->export('private-object', sourceKind: 'authenticated');
            self::fail('A source stream failure produced a package asset.');
        } catch (SourceRecordException $exception) {
            self::assertSame('asset_missing', $exception->reasonCode);
        }

        self::assertSame([], $this->temporaryFiles($package));
        self::assertSame([], array_values(array_filter(
            scandir($package . '/assets') ?: [],
            static fn (string $name): bool => !in_array($name, ['.', '..'], true),
        )));
    }

    /** @return array<string, array{string}> */
    public static function finalisationBoundaries(): array
    {
        return [
            'before filesystem finalisation' => ['before_filesystem_finalisation'],
            'after filesystem finalisation' => ['after_filesystem_finalisation'],
        ];
    }

    #[DataProvider('finalisationBoundaries')]
    public function testPostCommitFilesystemFailureIsResumableWithoutDuplicateDatabaseWrites(string $failurePoint): void
    {
        $prepared = $this->prepared();
        $record = $this->record();
        $graph = new FailureGraph();
        $journal = new FailureJournal();
        $exporter = new FailureExporter();
        $saga = new FilesystemSagaRepository($this->root);
        $target = $this->root . '/committed-download.bin';
        $writer = new FilesystemCrashWriter($graph, $saga, $target, $failurePoint);
        $reconciler = new FailureReconciler();
        $coordinator = new TransferCoordinator(
            $journal,
            $exporter,
            new FailureBoundary(),
            new FixedFailureTargetState($prepared->targetState),
            ['product' => $writer],
            ['product' => $reconciler],
        );

        try {
            $coordinator->stage($this->plan($prepared, [$record]), $this->context($saga), 'worker-a', 300);
            self::fail('The injected post-commit filesystem crash was ignored.');
        } catch (\RuntimeException $exception) {
            self::assertSame('filesystem_finalisation_interrupted', $exception->getMessage());
        }

        $receipt = $journal->receipts($prepared->runId)[0];
        $operation = $receipt->filesystemOperationIds[0];
        self::assertSame(1, $graph->targetRows);
        self::assertSame(1, $graph->maps);
        self::assertCount(1, $journal->receipts($prepared->runId));
        self::assertSame(TransferRunState::Interrupted, $journal->state($prepared->runId));
        self::assertContains($saga->state($prepared->runId, $operation), ['pending', 'final']);
        self::assertSame(0, $exporter->files, 'Receipt evidence was exported before the filesystem saga became durable.');

        $coordinator->stage($this->plan($prepared, [$record]), $this->context($saga), 'worker-a', 300);

        self::assertSame(1, $graph->writerCalls, 'Resume duplicated a committed target graph.');
        self::assertSame(1, $graph->targetRows);
        self::assertSame(1, $graph->maps);
        self::assertCount(1, $journal->receipts($prepared->runId));
        self::assertSame('final', $saga->state($prepared->runId, $operation));
        self::assertSame(1, $exporter->files);
        self::assertSame(1, $reconciler->calls);
        self::assertSame(TransferRunState::Staged, $journal->state($prepared->runId));
        self::assertSame([], $this->temporaryFiles($this->root));
    }

    /** @return list<string> */
    private function temporaryFiles(string $directory): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if (str_starts_with($name, '.') || str_ends_with($name, '.partial') || str_ends_with($name, '.tmp')) {
                $found[] = $file->getPathname();
            }
        }
        sort($found, SORT_STRING);
        return $found;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
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

final class FilesystemCrashWriter implements RecordWriter
{
    public function __construct(
        private readonly FailureGraph $graph,
        private readonly FilesystemSagaRepository $saga,
        private readonly string $target,
        private readonly string $failurePoint,
    ) {
    }

    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        ++$this->graph->writerCalls;
        ++$this->graph->targetRows;
        ++$this->graph->maps;
        DatabaseTransaction::afterRollback(function (): void {
            --$this->graph->targetRows;
            --$this->graph->maps;
        });
        $bytes = "committed target bytes\n";
        $operation = $this->saga->begin(
            $context->migrationId,
            $context->generation,
            'download',
            hash('sha256', $bytes),
            strlen($bytes),
            basename($this->target),
            $this->target,
        );
        file_put_contents($this->target, $bytes);
        DatabaseTransaction::afterCommit(function () use ($context, $operation): void {
            if ($this->failurePoint === 'before_filesystem_finalisation') {
                throw new \RuntimeException('injected before filesystem finalisation');
            }
            $this->saga->finalise($context->migrationId, $operation, $this->target);
            throw new \RuntimeException('injected after filesystem finalisation');
        });

        return new StageResult(901, [], [], [], str_repeat('a', 64), false, [$operation]);
    }
}

final class FailingAssetStream
{
    public mixed $context = null;
    private int $read = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->read++ > 0) {
            throw new SourceRecordException('asset_missing', 'Injected stream failure.');
        }
        return str_repeat('x', min($count, 1024));
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }
}
