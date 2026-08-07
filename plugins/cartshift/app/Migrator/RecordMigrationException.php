<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\Contracts\HasErrorCode;
use CartShift\Support\Enums\MigrationErrorCode;

/**
 * A record failed for a reason the migrator already knows the name of.
 *
 * Thrown rather than logged because the caller still has to abandon the record —
 * the orchestrator's transaction has to roll back, and only the orchestrator can
 * do that. Carrying the code out with the exception means the log row it writes
 * says "could not create product" instead of "unexpected error".
 *
 * @see HasErrorCode
 */
final class RecordMigrationException extends \RuntimeException implements HasErrorCode
{
    public function __construct(
        string $message,
        private readonly MigrationErrorCode $errorCode = MigrationErrorCode::UnexpectedException,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    #[\Override]
    public function errorCode(): MigrationErrorCode
    {
        return $this->errorCode;
    }
}
