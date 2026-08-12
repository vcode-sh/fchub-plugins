<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

enum AssessmentOutcome: string
{
    case Ready = 'ready';
    case Linked = 'linked';
    case ExcludedByPolicy = 'excluded_by_policy';
    case Blocked = 'blocked';
}
