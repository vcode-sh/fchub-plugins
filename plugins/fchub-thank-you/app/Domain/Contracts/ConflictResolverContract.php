<?php

declare(strict_types=1);

namespace FchubThankYou\Domain\Contracts;

interface ConflictResolverContract
{
    /** @param list<int> $productIds */
    public function resolve(array $productIds): ?string;
}
