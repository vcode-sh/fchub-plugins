<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

enum ProductFieldDisposition: string
{
    case Migrate = 'migrate';
    case PreserveProvenance = 'preserve_provenance';
    case ExcludeByPolicy = 'exclude_by_policy';
    case Block = 'block';
}
