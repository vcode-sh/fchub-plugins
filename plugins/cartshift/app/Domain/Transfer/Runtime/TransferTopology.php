<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Runtime;

defined('ABSPATH') || exit;

/**
 * Where the shop and its destination are, relative to each other.
 *
 * The subscription plan's topology table has three rows; they collapse to two
 * questions a route has to answer differently, so this has two cases.
 *
 * `SameSite` is one booted WordPress holding WooCommerce and FluentCart at
 * once — the common case, and the one whose "direct assessment and stage" route
 * shipped without a writer. Nothing has to move between machines, so every
 * absolute path, private directory and operator-supplied slug the cross-runtime
 * verbs demand is a question CartShift can answer for itself.
 *
 * `CrossRuntime` covers the other two rows — separate installs, separate
 * prefixes, separate databases. There, a package genuinely has to be carried
 * from one runtime to another, and the operator is the one carrying it.
 */
enum TransferTopology: string
{
    case SameSite = 'same_site';

    case CrossRuntime = 'cross_runtime';
}
