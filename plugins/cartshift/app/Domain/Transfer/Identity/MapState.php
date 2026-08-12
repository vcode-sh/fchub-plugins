<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

defined('ABSPATH') || exit;

enum MapState: string
{
    case Legacy = 'legacy';
    case Claimed = 'claimed';
    case Staged = 'staged';
    case Reconciled = 'reconciled';
    case Promoted = 'promoted';
    case RolledBack = 'rolled_back';
}
