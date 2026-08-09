<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

/**
 * One transaction boundary per record, however many layers ask for one.
 *
 * THE DEFECT THIS REPLACES. `MigrationOrchestrator` opened a transaction per
 * record and `SubscriptionWriter::stage()` opened a second one inside it. MySQL
 * has no nested transactions: a second `START TRANSACTION` IMPLICITLY COMMITS
 * the first. So the writer's own `COMMIT` ended the only transaction there was,
 * and `SubscriptionMigrator::linkHistory()` — which creates orders, order items
 * and transactions — then ran with no transaction at all. A throw in there left
 * that history committed while the orchestrator's `ROLLBACK` undid nothing,
 * because there was nothing left to undo. Half a subscriber's billing history,
 * committed, with the record reported as failed.
 *
 * WHAT THIS DOES INSTEAD. It counts. The outermost `begin()` issues the SQL and
 * every inner one only increments; `commit()` unwinds and issues `COMMIT` only
 * when the count returns to zero. The transaction boundary therefore belongs to
 * whoever opened it first, which is the caller that also owns the error handling
 * — and a record that throws anywhere in its own processing leaves no rows
 * behind.
 *
 * `rollback()` is deliberately NOT symmetric. A rollback aborts the whole
 * transaction whatever depth asked for it, because there is no such thing as
 * undoing half of one; the counter is reset to zero so the outer handler's own
 * `rollback()` does not then issue a second, stray `ROLLBACK` against no
 * transaction.
 *
 * Static state, and it is the right shape here: the fact being tracked is a
 * property of the database connection, not of any object. `$wpdb` is a global
 * for the same reason.
 */
final class DatabaseTransaction
{
    private static int $depth = 0;

    /**
     * Open a transaction, or join the one that is already open.
     */
    public static function begin(): void
    {
        if (self::$depth === 0) {
            global $wpdb;

            $wpdb->query('START TRANSACTION');
        }

        self::$depth++;
    }

    /**
     * Leave this layer, committing only when the outermost one leaves.
     *
     * A `commit()` at depth zero is a no-op rather than an error: an inner
     * `rollback()` has already ended the transaction, and the honest answer to
     * "commit what?" is nothing.
     */
    public static function commit(): void
    {
        if (self::$depth === 0) {
            return;
        }

        self::$depth--;

        if (self::$depth === 0) {
            global $wpdb;

            $wpdb->query('COMMIT');
        }
    }

    /**
     * Abandon the whole transaction, from any depth.
     */
    public static function rollback(): void
    {
        if (self::$depth === 0) {
            return;
        }

        self::$depth = 0;

        global $wpdb;

        $wpdb->query('ROLLBACK');
    }

    /**
     * How many layers are currently inside the transaction.
     */
    public static function depth(): int
    {
        return self::$depth;
    }

    /**
     * Forget any depth left over from a test that threw.
     *
     * Test-only housekeeping. Production never needs it: every `begin()` is
     * paired with a `commit()` or a `rollback()` in a `finally`-equivalent
     * position, and a fatal ends the request anyway.
     */
    public static function reset(): void
    {
        self::$depth = 0;
    }
}
