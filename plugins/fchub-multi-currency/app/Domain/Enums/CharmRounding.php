<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Enums;

defined('ABSPATH') || exit;

/**
 * The one vocabulary of charm-rounding rules. The settings sanitizer derives
 * its allowlist from these cases and RoundingPolicy::charm() matches over
 * them exhaustively, so adding a rule forces both surfaces at once — the
 * same contract RoundingMode carries for its sibling setting.
 */
enum CharmRounding: string
{
    case None      = 'none';
    case Whole     = 'whole';
    case Ending99  = 'ending_99';
    case Ending95  = 'ending_95';
    case Nearest5  = 'nearest_5';
    case Nearest10 = 'nearest_10';
}
