<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\Reconciliation\Contracts;

use FChubMemberships\Domain\Reconciliation\ProviderHealthCapability;
use FChubMemberships\Domain\Reconciliation\ProviderHealthObservation;
use FChubMemberships\Domain\Reconciliation\ProviderResource;

defined('ABSPATH') || exit;

interface ProviderHealthExtensionInterface
{
    public function capability(): ProviderHealthCapability;

    public function observe(ProviderResource $resource): ProviderHealthObservation;
}
