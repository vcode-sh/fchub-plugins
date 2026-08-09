<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Source;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\CustomerRecord;
use CartShift\Domain\Subscription\DatasetManifest;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\OrderRecord;
use CartShift\Domain\Subscription\Package\SubscriptionPackageReader;
use CartShift\Domain\Subscription\ProductRecord;
use CartShift\Domain\Subscription\SubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionSelection;

/**
 * A package file, wearing the same interface the live source wears.
 *
 * That interchangeability is the point of the whole dataset contract: the
 * mapper, the closure validator and the writer must not be able to tell which
 * runtime the records came out of, because on Lapka the export happens in one
 * WordPress and everything afterwards happens in another.
 *
 * THE SELECTION IS NOT RE-APPLIED. It was applied at export, frozen into the
 * header's selection fingerprint, and the dependency closure was computed
 * around it. Narrowing it again on this side would drop customers, orders and
 * products the remaining subscriptions still need, and the closure validator
 * would then blame the exporter for a hole this class had just made. A caller
 * that wants a different selection re-exports; that is what the fingerprint is
 * for.
 */
final class PackageSubscriptionDatasetSource implements SubscriptionDatasetSource
{
    public function __construct(
        private readonly string $path,
        private readonly SubscriptionPackageReader $reader = new SubscriptionPackageReader(),
    ) {
    }

    #[\Override]
    public function manifest(): DatasetManifest
    {
        return $this->reader->manifest($this->path);
    }

    /**
     * @return iterable<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord>
     */
    #[\Override]
    public function records(SubscriptionSelection $selection): iterable
    {
        yield from $this->reader->records($this->path);
    }
}
