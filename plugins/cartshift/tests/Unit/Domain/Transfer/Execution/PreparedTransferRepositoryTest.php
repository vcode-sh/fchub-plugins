<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Tests\Unit\PluginTestCase;

final class PreparedTransferRepositoryTest extends PluginTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/cartshift-prepared-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        chmod($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $file) unlink($this->directory . '/' . $file);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testDescriptorIsImmutablePrivateAndRoundTripsWithoutWordPressWrites(): void
    {
        $repository = new PreparedTransferRepository($this->directory);
        $prepared = $this->prepared();

        $path = $repository->save($prepared);
        $loaded = $repository->get($prepared->runId);

        self::assertSame($prepared->toArray(), $loaded->toArray());
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame([], array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
        ));

        self::assertSame($path, $repository->save($prepared), 'Identical descriptor was not reused.');
    }

    public function testSameRunIdWithChangedSealedInputIsRejectedWithoutOverwrite(): void
    {
        $repository = new PreparedTransferRepository($this->directory);
        $prepared = $this->prepared();
        $path = $repository->save($prepared);
        $before = file_get_contents($path);
        $changed = new PreparedTransfer(
            $prepared->runId,
            $prepared->packagePath,
            $prepared->packageHash,
            $prepared->targetState->withTargetHash(str_repeat('f', 64)),
            $prepared->executionContext,
            [],
            false,
            $prepared->createdAtUtc,
            $prepared->sourceKey,
        );

        try {
            $repository->save($changed);
            self::fail('A descriptor with changed target state overwrote immutable evidence.');
        } catch (\RuntimeException $exception) {
            self::assertSame('prepared_transfer_immutable_conflict', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testRunIdCannotExceedTheLegacyMapStorageContract(): void
    {
        $this->expectExceptionMessage('Prepared run ID is invalid.');

        new PreparedTransfer(
            str_repeat('r', 37),
            '/srv/private/package',
            str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal',
            [],
            false,
            '2026-08-10T12:00:00Z',
            'shop-alpha',
        );
    }

    public function testGenerationIsSealedAndMustBePositive(): void
    {
        $prepared = new PreparedTransfer(
            'run-generation-22',
            '/srv/private/package',
            str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha', 2,
        );

        self::assertSame(2, PreparedTransfer::fromArray($prepared->toArray())->generation);

        $this->expectExceptionMessage('Prepared generation is invalid.');
        new PreparedTransfer(
            'run-generation-bad', '/srv/private/package', str_repeat('1', 64), $prepared->targetState,
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha', 0,
        );
    }

    private function prepared(): PreparedTransfer
    {
        return new PreparedTransfer(
            'run-repository-22',
            '/srv/private/package',
            str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal',
            [],
            false,
            '2026-08-10T12:00:00Z',
            'shop-alpha',
        );
    }
}
