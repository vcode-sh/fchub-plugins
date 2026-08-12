<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class ProductTargetFingerprint
{
    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, int> $sourceMap canonical source identity => target ID
     */
    public function fingerprint(array $snapshot, array $sourceMap): string
    {
        return CanonicalJson::fingerprint($this->canonical($snapshot, $sourceMap));
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, int> $sourceMap
     * @return array<string, mixed>
     */
    public function canonical(array $snapshot, array $sourceMap): array
    {
        $variations = $this->rows((array) ($snapshot['variations'] ?? []), 'source_identity');
        $media = $this->rows((array) ($snapshot['media'] ?? []), 'source_identity');
        $downloads = $this->rows((array) ($snapshot['downloads'] ?? []), 'source_identity');
        $taxonomyRows = array_values(array_filter((array) ($snapshot['taxonomy_rows'] ?? []), 'is_array'));
        foreach ($taxonomyRows as &$taxonomyRow) {
            // WordPress maintains this aggregate across every product related to
            // the term. A later product in the same package legitimately changes
            // it, so it cannot be part of one product's immutable receipt.
            unset($taxonomyRow['count']);
        }
        unset($taxonomyRow);
        $taxonomies = array_values(array_map('intval', (array) ($snapshot['taxonomies'] ?? [])));
        sort($taxonomies, SORT_NUMERIC);
        ksort($sourceMap);

        return [
            'product' => $snapshot['product'] ?? null,
            'detail' => $snapshot['detail'] ?? null,
            'variations' => $variations,
            'taxonomies' => $taxonomies,
            'taxonomy_rows' => $this->rows($taxonomyRows, 'term_id'),
            'media' => $media,
            'downloads' => $downloads,
            'source_maps' => $sourceMap,
        ];
    }

    /** @param array<int|string, mixed> $rows @return list<array<string, mixed>> */
    private function rows(array $rows, string $identityKey): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        usort($rows, static function (array $left, array $right) use ($identityKey): int {
            $leftIdentity = (string) ($left[$identityKey] ?? $left['other_info'][$identityKey] ?? '');
            $rightIdentity = (string) ($right[$identityKey] ?? $right['other_info'][$identityKey] ?? '');
            return [$leftIdentity, (int) ($left['id'] ?? 0)] <=> [$rightIdentity, (int) ($right['id'] ?? 0)];
        });
        return $rows;
    }
}
