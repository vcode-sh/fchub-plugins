<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

interface SubscriptionSourceCutoverGateway
{
    /** @return array{source_fingerprint:string,release_comparison_fingerprint:string,renewal_fingerprint:string,requires_manual_renewal:bool} */
    public function inspect(SourceIdentity $identity): array;

    /** @return array{source_fingerprint:string,release_comparison_fingerprint:string,renewal_fingerprint:string,requires_manual_renewal:bool,previous_requires_manual_renewal:bool} */
    public function release(SourceIdentity $identity): array;
}
