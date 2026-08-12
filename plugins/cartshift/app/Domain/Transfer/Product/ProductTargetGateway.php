<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

interface ProductTargetGateway
{
    /** @param array<string, int|string|null> $plan */
    public function createTaxonomyTerm(array $plan, ?int $parentTargetId): int;

    /** @param array<string, mixed> $fields */
    public function createDraftProduct(array $fields): int;

    /** @param array<string, mixed> $fields */
    public function createProductDetail(int $productId, array $fields): int;

    /** @param array<string, mixed> $fields */
    public function createVariation(int $productId, array $fields): int;

    public function finishProductDetail(int $productId, int $defaultVariationId, int $minPrice, int $maxPrice): void;

    /** @param list<array{target_id: int, term_order: int}> $relations */
    public function assignTaxonomies(int $productId, array $relations): void;

    /**
     * @param array<string, int> $variationIds keyed by canonical source identity
     * @param list<array<string, mixed>> $stagedMedia
     * @return list<int>
     */
    public function attachMedia(int $productId, array $variationIds, array $stagedMedia): array;

    /** @param list<int> $variationIds @param array<string, mixed> $fields */
    public function createDownload(int $productId, array $variationIds, array $fields): int;

    public function exists(int $productId): bool;

    /** @return array<string, mixed> */
    public function snapshot(int $productId): array;

    /** @param list<int> $variationIds @return array<string, mixed> */
    public function behaviour(int $productId, array $variationIds): array;
}
