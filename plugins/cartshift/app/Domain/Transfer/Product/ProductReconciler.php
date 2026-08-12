<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\ReconciliationResult;

defined('ABSPATH') || exit;

final class ProductReconciler
{
    public function __construct(
        private readonly ProductTargetGateway $gateway,
        private readonly CheckedMappingStore $maps,
        private readonly ProductTargetFingerprint $fingerprint = new ProductTargetFingerprint(),
    ) {
    }

    public function reconcile(
        ProductStagePlan $plan,
        int $targetId,
        string $expectedFingerprint,
        ?string $approvedPostStatus = null,
    ): ReconciliationResult {
        if (!in_array($approvedPostStatus, [null, 'publish'], true)) {
            throw new \InvalidArgumentException('Approved product status is invalid.');
        }
        $failures = [];
        if (!$this->gateway->exists($targetId)) {
            $empty = $this->fingerprint->fingerprint([], []);
            return new ReconciliationResult(false, $empty, ['target_product_missing']);
        }

        $sourceMap = [];
        foreach ($plan->sourceIdentities() as $identity) {
            $mapping = $this->maps->get($identity);
            if ($mapping === null) {
                $failures[] = 'source_map_missing';
                continue;
            }
            $sourceMap[$identity->canonical()] = $mapping->targetId;
        }

        $snapshot = $this->gateway->snapshot($targetId);
        $receiptSnapshot = $snapshot;
        if ($approvedPostStatus !== null) {
            $actualStatus = $snapshot['product']['post_status'] ?? null;
            if ($actualStatus !== $approvedPostStatus) {
                $failures[] = 'approved_product_status_mismatch';
            } else {
                $receiptSnapshot['product']['post_status'] = $plan->productFields['post_status'];
            }
        }
        $actualFingerprint = $this->fingerprint->fingerprint($receiptSnapshot, $sourceMap);
        if (!hash_equals($expectedFingerprint, $actualFingerprint)) {
            $failures[] = 'target_fingerprint_mismatch';
        }

        $actualProduct = (array) ($snapshot['product'] ?? []);
        if ($approvedPostStatus !== null && ($actualProduct['post_status'] ?? null) === $approvedPostStatus) {
            $actualProduct['post_status'] = $plan->productFields['post_status'];
        }
        if (!$this->sameFields($actualProduct, $plan->productFields)) {
            $failures[] = 'product_field_mismatch';
        }

        $expectedDetail = $plan->detailFields;
        $mappedVariationIds = [];
        foreach ($plan->variations as $variation) {
            $mapped = $sourceMap[$variation->sourceVariation->canonical()] ?? null;
            if (is_int($mapped)) {
                $mappedVariationIds[] = $mapped;
            }
        }
        $prices = array_map(static fn (SimpleVariationPlan $variation): int => (int) $variation->targetFields['item_price'], $plan->variations);
        $expectedDetail['default_variation_id'] = $mappedVariationIds[0] ?? 0;
        $expectedDetail['min_price'] = min($prices);
        $expectedDetail['max_price'] = max($prices);
        if (!$this->sameFields((array) ($snapshot['detail'] ?? []), $expectedDetail)) {
            $failures[] = 'product_detail_mismatch';
        }

        $targetVariations = (array) ($snapshot['variations'] ?? []);
        $expectedSources = array_map(
            static fn (SimpleVariationPlan $variation): string => $variation->sourceVariation->canonical(),
            $plan->variations,
        );
        sort($expectedSources);
        $actualSources = array_map(
            static fn (mixed $variation): string => is_array($variation)
                ? (string) ($variation['other_info']['source_identity'] ?? '')
                : '',
            $targetVariations,
        );
        sort($actualSources);
        if ($actualSources !== $expectedSources || count($actualSources) !== count(array_unique($actualSources))) {
            $failures[] = 'variation_source_cardinality_mismatch';
        }
        $actualBySource = [];
        foreach ($targetVariations as $targetVariation) {
            if (is_array($targetVariation)) {
                $actualBySource[(string) ($targetVariation['other_info']['source_identity'] ?? '')] = $targetVariation;
            }
        }
        foreach ($plan->variations as $variation) {
            $actual = $actualBySource[$variation->sourceVariation->canonical()] ?? [];
            $expected = $variation->targetFields;
            unset($expected['variation_type']);
            if (!$this->sameFields($actual, $expected)) {
                $failures[] = 'variation_field_mismatch';
                break;
            }
        }

        $expectedTaxonomies = [];
        foreach ($plan->taxonomyPlans as $taxonomy) {
            if ((int) ($taxonomy['assigned'] ?? 0) !== 1 || $taxonomy['action'] === 'provenance') {
                continue;
            }
            $identity = (string) $taxonomy['source_identity'];
            if (isset($sourceMap[$identity])) {
                $expectedTaxonomies[] = [
                    'term_id' => $sourceMap[$identity],
                    'term_order' => (int) $taxonomy['order'],
                ];
            }
        }
        $actualTaxonomies = [];
        foreach ((array) ($snapshot['taxonomy_rows'] ?? []) as $row) {
            if (is_array($row)) {
                $actualTaxonomies[] = [
                    'term_id' => (int) ($row['term_id'] ?? 0),
                    'term_order' => (int) ($row['term_order'] ?? -1),
                ];
            }
        }
        usort($expectedTaxonomies, static fn (array $left, array $right): int => $left['term_id'] <=> $right['term_id']);
        usort($actualTaxonomies, static fn (array $left, array $right): int => $left['term_id'] <=> $right['term_id']);
        if ($actualTaxonomies !== $expectedTaxonomies) {
            $failures[] = 'taxonomy_relation_mismatch';
        }
        $taxonomyRows = [];
        foreach ((array) ($snapshot['taxonomy_rows'] ?? []) as $row) {
            if (is_array($row) && (int) ($row['term_id'] ?? 0) > 0) {
                $taxonomyRows[(int) $row['term_id']] = $row;
            }
        }
        foreach ($plan->taxonomyPlans as $taxonomy) {
            if ((int) ($taxonomy['assigned'] ?? 0) !== 1 || $taxonomy['action'] === 'provenance') continue;
            $targetTermId = $sourceMap[(string) $taxonomy['source_identity']] ?? null;
            $parentTargetId = $taxonomy['parent_source'] === null
                ? 0
                : ($sourceMap[(string) $taxonomy['parent_source']] ?? null);
            $actual = is_int($targetTermId) ? ($taxonomyRows[$targetTermId] ?? []) : [];
            $expected = [
                'taxonomy' => $taxonomy['target_taxonomy'],
                'name' => $taxonomy['name'],
                'slug' => $taxonomy['slug'],
                'description' => $taxonomy['description'],
                'parent' => $parentTargetId,
            ];
            if (!$this->sameFields($actual, $expected)) {
                $failures[] = 'taxonomy_field_mismatch';
                break;
            }
        }

        $expectedMediaRelations = [];
        foreach ($plan->media as $item) {
            $reference = $item['reference'];
            $expectedMediaRelations[] = [
                'source_identity' => $reference->identity->canonical(),
                'owner_identity' => $reference->owner->canonical(),
                'role' => $reference->role,
                'provenance' => $reference->provenance,
                'sha256' => $item['asset']->sha256,
                'target_id' => (int) ($sourceMap[$reference->identity->canonical()] ?? 0),
            ];
        }
        $actualMediaRelations = [];
        foreach ((array) ($snapshot['media'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $actualMediaRelations[] = [
                'source_identity' => (string) ($item['source_identity'] ?? ''),
                'owner_identity' => (string) ($item['owner_identity'] ?? ''),
                'role' => (string) ($item['role'] ?? ''),
                'provenance' => (string) ($item['provenance'] ?? ''),
                'sha256' => (string) ($item['sha256'] ?? ''),
                'target_id' => (int) ($item['target_id'] ?? 0),
            ];
        }
        $sortRelations = static function (array &$relations): void {
            usort($relations, static fn (array $left, array $right): int =>
                \CartShift\Support\CanonicalJson::encode($left)
                    <=> \CartShift\Support\CanonicalJson::encode($right));
        };
        $sortRelations($expectedMediaRelations);
        $sortRelations($actualMediaRelations);
        if ($actualMediaRelations !== $expectedMediaRelations) {
            $failures[] = 'media_relation_mismatch';
        }

        $variationIds = [];
        $cartableVariationIds = [];
        foreach ($plan->variations as $variation) {
            $targetVariationId = $sourceMap[$variation->sourceVariation->canonical()] ?? null;
            if (is_int($targetVariationId)) {
                $variationIds[] = $targetVariationId;
                if (!isset($variation->targetOtherInfo['stock_migration_exception'])) {
                    $cartableVariationIds[] = $targetVariationId;
                }
            }
        }
        sort($variationIds);
        sort($cartableVariationIds);
        $behaviour = $this->gateway->behaviour($targetId, $variationIds);
        if (!$plan->historicalPlaceholder && ($behaviour['buy_section_rendered'] ?? false) !== true) {
            $failures[] = 'buy_section_missing';
        }
        if ($plan->historicalPlaceholder && ($behaviour['buy_section_rendered'] ?? false) === true) {
            $failures[] = 'historical_placeholder_purchasable';
        }

        $cartable = array_values(array_map('intval', (array) ($behaviour['cartable_variation_ids'] ?? [])));
        $checkout = array_values(array_map('intval', (array) ($behaviour['checkout_object_ids'] ?? [])));
        sort($cartable);
        sort($checkout);
        if (!$plan->historicalPlaceholder
            && ($cartable !== $cartableVariationIds || $checkout !== $cartableVariationIds)) {
            $failures[] = 'variation_not_cartable_exactly_once';
        }
        if ($plan->historicalPlaceholder && ($cartable !== [] || $checkout !== [])) {
            $failures[] = 'historical_placeholder_purchasable';
        }

        $expectedMedia = array_map(static fn (array $item): string => $item['asset']->sha256, $plan->media);
        $renderedMedia = array_values(array_filter((array) ($behaviour['rendered_media_hashes'] ?? []), 'is_string'));
        sort($expectedMedia);
        sort($renderedMedia);
        if ($renderedMedia !== $expectedMedia) {
            $failures[] = 'media_render_mismatch';
        }

        $expectedDownloads = array_map(static fn (array $item): string => $item['asset']->sha256, $plan->downloads);
        $readableDownloads = array_values(array_filter((array) ($behaviour['readable_download_hashes'] ?? []), 'is_string'));
        sort($expectedDownloads);
        sort($readableDownloads);
        if ($readableDownloads !== $expectedDownloads) {
            $failures[] = 'download_delivery_mismatch';
        }

        $failures = array_values(array_unique($failures));
        sort($failures);
        return new ReconciliationResult($failures === [], $actualFingerprint, $failures);
    }

    /** @param array<string, mixed> $actual @param array<string, mixed> $expected */
    private function sameFields(array $actual, array $expected): bool
    {
        $projected = [];
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                return false;
            }
            $projected[$key] = $actual[$key];
        }
        return \CartShift\Support\CanonicalJson::encode($projected)
            === \CartShift\Support\CanonicalJson::encode($expected);
    }
}
