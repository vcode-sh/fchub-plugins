<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

defined('ABSPATH') || exit;

/** Public WooCommerce/WordPress read boundary used by the transfer census. */
interface WooSourceApi
{
    /** @return list<int> */
    public function productCensusPage(int $page, int $limit): array;

    /** @return list<int> */
    public function semanticProductIds(): array;

    /** @return list<int> */
    public function lookupProductIds(): array;

    /** @return array<string, mixed>|null */
    public function product(int $id): ?array;

    /** @return list<int> */
    public function orderCensusPage(int $page, int $limit): array;

    /** @return array<string, mixed>|null */
    public function order(int $id): ?array;

    /** @return list<int> */
    public function subscriptionCensusPage(int $page, int $limit): array;

    /** @return array<string, mixed>|null */
    public function subscription(int $id): ?array;
}
