<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * One dataset contract, whichever runtime the data came out of.
 *
 * The mapper and the writer must never touch a live `WC_*` object. Same-site
 * runs read WooCommerce directly and cross-site runs read a package file, and
 * the only way that stays true is if both hand back the same stream of the same
 * five record types — with the same canonical fingerprints, so a record that
 * crossed a site boundary is recognisably the same record it was before.
 *
 * `records()` may stream. It may not omit payloads and leave a later phase to
 * find them: a subscription reference is not an order, and
 * `DatasetClosureValidator` exists to say so out loud.
 */
interface SubscriptionDatasetSource
{
    public function manifest(): DatasetManifest;

    /**
     * @return iterable<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord>
     */
    public function records(SubscriptionSelection $selection): iterable;
}
