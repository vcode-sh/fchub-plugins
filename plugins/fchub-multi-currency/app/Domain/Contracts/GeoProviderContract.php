<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Contracts;

defined('ABSPATH') || exit;

interface GeoProviderContract
{
    public function detectCurrency(): ?string;
}
