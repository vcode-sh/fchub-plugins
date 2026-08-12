<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

enum SelectionMode: string
{
    case None = 'none';
    case All = 'all';
    case Ids = 'ids';
    case Since = 'since';
}
