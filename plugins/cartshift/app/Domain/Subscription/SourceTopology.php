<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * Whether the Woo source and the FluentCart target share one runtime.
 *
 * This changes the source adapter and where the commands are run. It changes
 * nothing about the domain records, the mapping rules, the payment rules or the
 * target writer.
 *
 * The decision takes booted subsystems and nothing else, and that omission is
 * the point. WooCommerce's data stores are bound to the booted site and its
 * table prefix, so two WordPress installations sharing one MariaDB are two
 * runtimes — $wpdb can see the other site's tables, and WC()->…->get_orders()
 * still cannot. Lapka is exactly that shape. Give this method a database host
 * or name and somebody will eventually use it, so it is not given one.
 */
enum SourceTopology: string
{
    case SameRuntime = 'same_runtime';
    case CrossRuntime = 'cross_runtime';

    /**
     * Same runtime only when all three are booted here, together.
     *
     * WooCommerce without the Subscriptions add-on cannot serve a subscription
     * record at all, so it is not a source however close FluentCart happens to
     * be sitting.
     */
    public static function decide(
        bool $wooCommerceBooted,
        bool $wooSubscriptionsBooted,
        bool $fluentCartBooted,
    ): self {
        if ($wooCommerceBooted && $wooSubscriptionsBooted && $fluentCartBooted) {
            return self::SameRuntime;
        }

        return self::CrossRuntime;
    }

    /**
     * Whether the run has to go through the private NDJSON package.
     */
    public function requiresPackage(): bool
    {
        return $this === self::CrossRuntime;
    }
}
