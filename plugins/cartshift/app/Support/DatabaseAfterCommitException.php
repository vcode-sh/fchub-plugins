<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

/**
 * SQL COMMIT succeeded, but one or more durable post-commit actions did not.
 *
 * This is deliberately distinct from a transaction failure: the database can
 * no longer be rolled back and callers must resume the outstanding actions.
 */
final class DatabaseAfterCommitException extends \RuntimeException
{
    public function __construct(int $failureCount, \Throwable $firstFailure)
    {
        parent::__construct('database_after_commit_callbacks_failed:' . $failureCount, 0, $firstFailure);
    }
}
