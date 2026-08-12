<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer;

use CartShift\Domain\Transfer\TransferLease;
use CartShift\Domain\Transfer\TransferLock;
use CartShift\Domain\Transfer\TransferRunGuard;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferLockTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LockDatabase::resetLocks();
    }

    public function testNullAdvisoryLockResultBlocksMutation(): void
    {
        $database = new LockDatabase('first');
        $database->nextResult = null;
        $this->expectExceptionMessage('transfer_lock_unavailable');

        (new TransferLock($database))->acquireTargetMutex($this->targetFingerprint());
    }

    public function testZeroSqlErrorMissingDatabaseAndConnectionLossAllBlock(): void
    {
        foreach (['zero', 'sql-error', 'connection-loss'] as $failure) {
            $database = new LockDatabase($failure);
            $database->failure = $failure;

            try {
                (new TransferLock($database))->acquireTargetMutex($this->targetFingerprint());
                self::fail($failure . ' was allowed to mutate.');
            } catch (\RuntimeException $exception) {
                self::assertSame('transfer_lock_unavailable', $exception->getMessage());
            }
        }
    }

    public function testDifferentSourcesAndRunIdsContendOnTheSameTarget(): void
    {
        $first = new TransferLock(new LockDatabase('connection-a'));
        $second = new TransferLock(new LockDatabase('connection-b'));
        $first->acquireTargetMutex($this->targetFingerprint());
        $this->expectExceptionMessage('transfer_lock_unavailable');

        $second->acquireTargetMutex($this->targetFingerprint());
    }

    public function testReleaseMustBeConfirmedAndLockNameIsTargetWideAndBounded(): void
    {
        $database = new LockDatabase('connection-a');
        $lock = new TransferLock($database);
        $lock->acquireTargetMutex($this->targetFingerprint());
        $database->failure = 'release-zero';

        try {
            $lock->release();
            self::fail('Unconfirmed release was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transfer_lock_release_failed', $exception->getMessage());
            self::assertTrue($lock->isHeld());
        }

        self::assertLessThanOrEqual(64, strlen(TransferLock::nameFor($this->targetFingerprint())));
        self::assertSame(
            TransferLock::nameFor($this->targetFingerprint()),
            TransferLock::nameFor($this->targetFingerprint()),
        );
    }

    public function testLeaseContentionRenewalExpiryBoundaryRecoveryAndOwnershipChecks(): void
    {
        $database = new LeaseDatabase();
        $now = new \DateTimeImmutable('2026-08-10 12:00:00', new \DateTimeZone('UTC'));
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $first = new TransferLease($database, $clock);
        $second = new TransferLease($database, $clock);
        $first->acquire($this->targetFingerprint(), 'run-a', $this->descriptorHash(), 10);

        try {
            $second->acquire($this->targetFingerprint(), 'run-b', $this->descriptorHash(), 10);
            self::fail('An active lease was stolen.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transfer_lease_unavailable', $exception->getMessage());
        }

        $now = $now->modify('+5 seconds');
        $first->renew('run-a', $this->descriptorHash(), 10);
        $now = $now->modify('+10 seconds');
        $second->recoverExpired('run-b', $this->descriptorHash(), str_repeat('e', 64), 20);
        $second->assertOwned('run-b', $this->descriptorHash());

        foreach ([['wrong-run', $this->descriptorHash()], ['run-b', str_repeat('f', 64)]] as [$holder, $descriptor]) {
            try {
                $second->release($holder, $descriptor);
                self::fail('Wrong lease ownership released the row.');
            } catch (\RuntimeException $exception) {
                self::assertSame('transfer_lease_release_conflict', $exception->getMessage());
            }
        }

        $second->release('run-b', $this->descriptorHash());
        self::assertSame([], $database->rows);
    }

    public function testRecoveryRejectsActiveLeaseChangedDescriptorAndMissingEvidence(): void
    {
        $database = new LeaseDatabase();
        $now = new \DateTimeImmutable('2026-08-10 12:00:00', new \DateTimeZone('UTC'));
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $owner = new TransferLease($database, $clock);
        $recovery = new TransferLease($database, $clock);
        $owner->acquire($this->targetFingerprint(), 'run-a', $this->descriptorHash(), 10);

        try {
            $recovery->acquire($this->targetFingerprint(), 'run-b', $this->descriptorHash(), 10);
        } catch (\RuntimeException) {
        }

        foreach ([[$this->descriptorHash(), str_repeat('e', 64)], [str_repeat('f', 64), str_repeat('e', 64)] ] as [$descriptor, $evidence]) {
            try {
                $recovery->recoverExpired('run-b', $descriptor, $evidence);
                self::fail('Unsafe active or changed-descriptor recovery succeeded.');
            } catch (\RuntimeException $exception) {
                self::assertSame('transfer_lease_recovery_conflict', $exception->getMessage());
            }
        }

        $now = $now->modify('+10 seconds');
        $this->expectException(\InvalidArgumentException::class);
        $recovery->recoverExpired('run-b', $this->descriptorHash(), 'not-evidence');
    }

    public function testRunGuardAlwaysReleasesMutexWhenLeaseOrMutationFails(): void
    {
        $lockDatabase = new LockDatabase('guard');
        $leaseDatabase = new LeaseDatabase();
        $guard = new TransferRunGuard(
            new TransferLock($lockDatabase),
            new TransferLease($leaseDatabase, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-08-10 12:00:00 UTC')),
        );

        try {
            $guard->acquire($this->targetFingerprint(), 'run-a', $this->descriptorHash(), 30);
            $guard->criticalSection(
                $this->targetFingerprint(),
                'run-a',
                $this->descriptorHash(),
                static fn (): never => throw new \RuntimeException('injected mutation failure'),
            );
        } catch (\RuntimeException $exception) {
            self::assertSame('injected mutation failure', $exception->getMessage());
        }

        self::assertSame(4, $lockDatabase->queries);
        self::assertSame([], LockDatabase::heldLocks());
    }

    private function targetFingerprint(): string
    {
        return str_repeat('a', 64);
    }

    private function descriptorHash(): string
    {
        return str_repeat('d', 64);
    }
}

final class LockDatabase
{
    public string $last_error = '';
    public ?int $nextResult = 1;
    public ?string $failure = null;
    public int $queries = 0;
    /** @var array<string, string> */
    private static array $locks = [];

    public function __construct(private readonly string $connection)
    {
    }

    public static function resetLocks(): void
    {
        self::$locks = [];
    }

    /** @return array<string, string> */
    public static function heldLocks(): array
    {
        return self::$locks;
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        foreach ($arguments as $argument) {
            $query = preg_replace('/%s/', "'" . (string) $argument . "'", $query, 1) ?? $query;
        }

        return $query;
    }

    public function get_var(string $query): int|string|null
    {
        $this->queries++;
        preg_match("/'([^']+)'/", $query, $matches);
        $name = $matches[1] ?? '';

        if (str_contains($query, 'RELEASE_LOCK')) {
            if ($this->failure === 'release-zero' || (self::$locks[$name] ?? null) !== $this->connection) {
                return 0;
            }

            unset(self::$locks[$name]);
            return 1;
        }

        if ($this->failure === 'zero') {
            return 0;
        }

        if ($this->failure === 'sql-error' || $this->failure === 'connection-loss') {
            $this->last_error = $this->failure;
            return null;
        }

        if ($this->nextResult === null) {
            return null;
        }

        if (isset(self::$locks[$name]) && self::$locks[$name] !== $this->connection) {
            return 0;
        }

        self::$locks[$name] = $this->connection;
        return $this->nextResult;
    }
}

final class LeaseDatabase extends \wpdb
{
    /** @var array<string, object> */
    public array $rows = [];

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $target = (string) $data['target_fingerprint'];

        if (isset($this->rows[$target])) {
            return false;
        }

        $this->rows[$target] = (object) $data;
        return 1;
    }

    public function get_results(string $query, string $output = OBJECT): array
    {
        preg_match("/target_fingerprint = '([^']+)'/", $query, $matches);
        $row = $this->rows[$matches[1] ?? ''] ?? null;

        return $row !== null ? [clone $row] : [];
    }

    public function query(string $query): int|false
    {
        preg_match_all("/'([^']*)'/", $query, $matches);
        $values = $matches[1];

        if (str_starts_with(trim($query), 'DELETE')) {
            [$target, $holder, $descriptor] = $values;
            $row = $this->rows[$target] ?? null;

            if ($row === null || $row->holder_id !== $holder || $row->descriptor_hash !== $descriptor) {
                return 0;
            }

            unset($this->rows[$target]);
            return 1;
        }

        if (str_contains($query, 'SET holder_id')) {
            [$newHolder, $expires, $heartbeat, $target, $oldHolder, $descriptor, $oldExpiry] = $values;
            $row = $this->rows[$target] ?? null;

            if ($row === null || $row->holder_id !== $oldHolder || $row->descriptor_hash !== $descriptor || $row->expires_at !== $oldExpiry) {
                return 0;
            }

            $row->holder_id = $newHolder;
            $row->expires_at = $expires;
            $row->heartbeat_at = $heartbeat;
            return 1;
        }

        [$expires, $heartbeat, $target, $holder, $descriptor, $now] = $values;
        $row = $this->rows[$target] ?? null;

        if ($row === null || $row->holder_id !== $holder || $row->descriptor_hash !== $descriptor || $row->expires_at <= $now) {
            return 0;
        }

        $row->expires_at = $expires;
        $row->heartbeat_at = $heartbeat;
        return 1;
    }
}
