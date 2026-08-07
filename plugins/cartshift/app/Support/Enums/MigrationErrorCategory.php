<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') || exit;

/**
 * Which part of the migration a reason belongs to.
 *
 * Used for grouping in the UI. Roughly the entity type, but not identical to it:
 * a "customer not found" reason is raised while migrating orders and
 * subscriptions, and belongs under Customer, because that is where the fix is.
 */
enum MigrationErrorCategory: string
{
    case Customer = 'customer';
    case Product = 'product';
    case Coupon = 'coupon';
    case Order = 'order';
    case Subscription = 'subscription';
    case Taxonomy = 'taxonomy';
    case System = 'system';
}
