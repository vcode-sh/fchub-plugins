<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') || exit;

/**
 * How much the user should care about a logged reason.
 *
 * Deliberately not the same thing as the log row's `status`. A row can be
 * written with status 'skipped' and still be an Error-severity reason: an order
 * skipped because its customer was never migrated is data the shop has lost,
 * whereas an order skipped because it was already migrated is housekeeping.
 * The status says what the migrator did; the severity says whether the user has
 * a problem.
 */
enum MigrationErrorSeverity: string
{
    /** Nothing is wrong. Recorded so the run is auditable, not so it is acted on. */
    case Info = 'info';

    /** Migrated, but with a compromise the user should review by hand. */
    case Warning = 'warning';

    /** Not migrated. Data is missing from FluentCart until the user acts. */
    case Error = 'error';
}
