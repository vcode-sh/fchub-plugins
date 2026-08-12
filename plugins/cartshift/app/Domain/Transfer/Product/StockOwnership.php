<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

enum StockOwnership: string
{
    case None = 'none';
    case Self = 'self';
    case Parent = 'parent';
}
