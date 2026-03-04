<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Providers;

defined('ABSPATH') || exit;

interface ProviderContract
{
    /**
     * @return array<string, string> Map of currency code => rate as string
     */
    public function fetchRates(string $baseCurrency): array;

    public function name(): string;
}
