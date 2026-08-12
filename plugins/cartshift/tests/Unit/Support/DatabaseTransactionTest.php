<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Support\DatabaseAfterCommitException;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class DatabaseTransactionTest extends PluginTestCase
{
    public function testBeginFailureThrowsWithoutIncreasingDepth(): void
    {
        $this->failStatement('START TRANSACTION');

        try {
            DatabaseTransaction::begin();
            self::fail('Expected begin failure.');
        } catch (\RuntimeException) {
            self::assertSame(0, DatabaseTransaction::depth());
        }
    }

    public function testNestedBoundaryIssuesOneBeginAndOneCommit(): void
    {
        DatabaseTransaction::begin();
        DatabaseTransaction::begin();
        DatabaseTransaction::commit();
        DatabaseTransaction::commit();

        self::assertSame(['START TRANSACTION', 'COMMIT'], $this->transactionQueries());
        self::assertSame(0, DatabaseTransaction::depth());
    }

    public function testCommitFailureThrowsAndKeepsTransactionBlocked(): void
    {
        DatabaseTransaction::begin();
        $this->failStatement('COMMIT');

        try {
            DatabaseTransaction::commit();
            self::fail('Expected commit failure.');
        } catch (\RuntimeException) {
            self::assertSame(1, DatabaseTransaction::depth());
        }
    }

    public function testRollbackFailureCarriesOriginalExceptionAndKeepsBlockedDepth(): void
    {
        $original = new \DomainException('writer failed');
        DatabaseTransaction::begin();
        $this->failStatement('ROLLBACK');

        try {
            DatabaseTransaction::rollback($original);
            self::fail('Expected rollback failure.');
        } catch (\RuntimeException $failure) {
            self::assertSame($original, $failure->getPrevious());
            self::assertSame(1, DatabaseTransaction::depth());
        }
    }

    public function testSuccessfulRollbackEndsAllNestedDepth(): void
    {
        DatabaseTransaction::begin();
        DatabaseTransaction::begin();
        DatabaseTransaction::rollback(new \DomainException('writer failed'));

        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $this->transactionQueries());
        self::assertSame(0, DatabaseTransaction::depth());
    }

    public function testRollbackInvalidationRunsOnFailedRollbackButNotCommit(): void
    {
        $events = [];
        DatabaseTransaction::begin();
        DatabaseTransaction::afterRollback(static function () use (&$events): void {
            $events[] = 'committed-callback';
        });
        DatabaseTransaction::commit();
        self::assertSame([], $events);

        DatabaseTransaction::begin();
        DatabaseTransaction::afterRollback(static function () use (&$events): void {
            $events[] = 'failed-rollback-callback';
        });
        $this->failStatement('ROLLBACK');

        try {
            DatabaseTransaction::rollback(new \RuntimeException('original'));
        } catch (\RuntimeException) {
            self::assertSame(['failed-rollback-callback'], $events);
        }
    }

    public function testAfterCommitRunsOnlyAfterTheOutermostCommit(): void
    {
        $events = [];
        DatabaseTransaction::begin();
        DatabaseTransaction::begin();
        DatabaseTransaction::afterCommit(static function () use (&$events): void {
            $events[] = 'committed';
        });

        DatabaseTransaction::commit();
        self::assertSame([], $events);

        DatabaseTransaction::commit();
        self::assertSame(['committed'], $events);
    }

    public function testAfterCommitIsDiscardedByRollback(): void
    {
        $events = [];
        DatabaseTransaction::begin();
        DatabaseTransaction::afterCommit(static function () use (&$events): void {
            $events[] = 'must-not-run';
        });

        DatabaseTransaction::rollback(new \RuntimeException('writer failed'));

        self::assertSame([], $events);
    }

    public function testAfterCommitDoesNotRunWhenCommitFails(): void
    {
        $events = [];
        DatabaseTransaction::begin();
        DatabaseTransaction::afterCommit(static function () use (&$events): void {
            $events[] = 'must-not-run';
        });
        $this->failStatement('COMMIT');

        try {
            DatabaseTransaction::commit();
            self::fail('Expected commit failure.');
        } catch (\RuntimeException) {
            self::assertSame([], $events);
            self::assertSame(1, DatabaseTransaction::depth());
        }
    }

    public function testAfterCommitFailureDoesNotHideLaterCallbacksOrReopenCommittedTransaction(): void
    {
        $events = [];
        DatabaseTransaction::begin();
        DatabaseTransaction::afterCommit(static function () use (&$events): void {
            $events[] = 'first';
            throw new \RuntimeException('injected finalisation failure');
        });
        DatabaseTransaction::afterCommit(static function () use (&$events): void {
            $events[] = 'second';
        });

        try {
            DatabaseTransaction::commit();
            self::fail('A failed post-commit action was hidden.');
        } catch (DatabaseAfterCommitException $exception) {
            self::assertSame('database_after_commit_callbacks_failed:1', $exception->getMessage());
            self::assertSame('injected finalisation failure', $exception->getPrevious()?->getMessage());
        }

        self::assertSame(['first', 'second'], $events, 'One callback failure suppressed later durable finalisation work.');
        self::assertSame(['START TRANSACTION', 'COMMIT'], $this->transactionQueries());
        self::assertSame(0, DatabaseTransaction::depth(), 'A committed transaction was presented as rollback-capable.');
        DatabaseTransaction::rollback(new \RuntimeException('must be a no-op'));
        self::assertSame(['START TRANSACTION', 'COMMIT'], $this->transactionQueries());
    }

    private function failStatement(string $statement): void
    {
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $query): string =>
            $query === $statement ? 'injected database failure' : '';
    }

    /** @return list<string> */
    private function transactionQueries(): array
    {
        return array_values(array_map(
            static fn (array $query): string => (string) $query[1],
            array_filter(
                $GLOBALS['_cartshift_test_queries'] ?? [],
                static fn (array $query): bool => ($query[0] ?? '') === 'query'
                    && in_array($query[1] ?? '', ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true),
            ),
        ));
    }
}
