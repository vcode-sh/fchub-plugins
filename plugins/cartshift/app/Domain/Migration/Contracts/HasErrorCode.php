<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration\Contracts;

defined('ABSPATH') || exit;

use CartShift\Support\Enums\MigrationErrorCode;

/**
 * An exception that already knows why it happened.
 *
 * A migrator that throws loses its log line: the orchestrator wraps every record
 * in a transaction and rolls it back before writing the failure, so anything the
 * migrator wrote on the way out is gone. The orchestrator's own catch block is
 * therefore the only writer, and without this interface it can only ever say
 * "unexpected error".
 *
 * Implement it on an exception the migrator throws deliberately — a refused
 * wp_insert_post(), say — and the orchestrator writes that code instead.
 */
interface HasErrorCode
{
    /**
     * The machine-readable reason this exception was thrown.
     */
    public function errorCode(): MigrationErrorCode;
}
