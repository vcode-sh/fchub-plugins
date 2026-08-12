<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

enum TransferRunState: string
{
    case Exported = 'exported';
    case Validated = 'validated';
    case Prepared = 'prepared';
    case Staging = 'staging';
    case Staged = 'staged';
    case Reconciling = 'reconciling';
    case Reconciled = 'reconciled';
    case Promoted = 'promoted';
    case CatalogueActivating = 'catalogue_activating';
    case Completed = 'completed';
    case Interrupted = 'interrupted';
    case Failed = 'failed';
    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Exported => [self::Validated],
            self::Validated => [self::Prepared],
            self::Prepared => [self::Staging],
            self::Staging => [self::Staged, self::Interrupted, self::Failed],
            self::Staged => [self::Reconciling],
            self::Reconciling => [self::Reconciled, self::Interrupted, self::Failed],
            self::Reconciled => [self::Promoted],
            self::Promoted => [self::CatalogueActivating, self::Completed],
            self::CatalogueActivating => [self::Completed, self::Interrupted, self::Failed],
            self::Interrupted => [self::Staging, self::Reconciling, self::CatalogueActivating, self::Failed],
            self::Failed => [self::RollingBack],
            self::RollingBack => [self::RolledBack, self::Failed],
            self::Completed, self::RolledBack => [],
        }, true);
    }
}
