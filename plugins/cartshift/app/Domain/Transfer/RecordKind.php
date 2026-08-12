<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

/** Canonical package order. Enum declaration order is part of the wire format. */
enum RecordKind: string
{
    case TaxonomyGroup = 'taxonomy_group';
    case TaxonomyTerm = 'taxonomy_term';
    case MediaAsset = 'media_asset';
    case DownloadAsset = 'download_asset';
    case Product = 'product';
    case Customer = 'customer';
    case Order = 'order';
    case Subscription = 'subscription';
}
