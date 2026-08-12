<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

enum ProductTransferAction: string
{
    case Create = 'create';
    case Link = 'link';
    case Exclude = 'exclude';
    case Block = 'block';
}
