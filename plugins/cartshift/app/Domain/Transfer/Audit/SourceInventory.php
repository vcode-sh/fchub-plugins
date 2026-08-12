<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

defined('ABSPATH') || exit;

final readonly class SourceInventory
{
    /**
     * @param list<int> $productIds
     * @param list<int> $orderIds
     * @param list<int> $subscriptionIds
     */
    public function __construct(
        public array $productIds,
        public array $orderIds,
        public array $subscriptionIds,
    ) {}
}
