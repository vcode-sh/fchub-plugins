<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final class TaxonomyPlanner
{
    private const array TARGET_TAXONOMIES = [
        'product_cat' => 'product-categories',
        'product_brand' => 'product-brands',
    ];

    /**
     * @param list<TaxonomyAssignment> $assignments
     * @param list<array{taxonomy: string, slug: string, name: string, parent_source: ?string, target_id: int}> $targetTerms
     * @return list<array<string, int|string|null>>
     */
    public function plan(array $assignments, array $targetTerms): array
    {
        if (!array_is_list($assignments) || !array_is_list($targetTerms)) {
            throw new \InvalidArgumentException('Taxonomy planner inputs must be lists.');
        }

        $source = [];
        foreach ($assignments as $assignment) {
            if (!$assignment instanceof TaxonomyAssignment) {
                throw new \InvalidArgumentException('Taxonomy planner received an invalid assignment.');
            }

            $key = $assignment->termIdentity->canonical();
            if (isset($source[$key])) {
                throw new SourceRecordException('taxonomy_source_duplicate', 'A source taxonomy identity occurs more than once.');
            }
            $source[$key] = $assignment;
        }

        foreach ($source as $assignment) {
            if ($assignment->parent !== null && !isset($source[$assignment->parent->canonical()])) {
                throw new SourceRecordException('taxonomy_parent_missing', 'A selected taxonomy term is missing its source parent.');
            }
        }

        $targets = [];
        foreach ($targetTerms as $term) {
            if (!isset($term['taxonomy'], $term['slug'], $term['name'], $term['target_id'])
                || !array_key_exists('parent_source', $term)
                || !is_string($term['taxonomy'])
                || !is_string($term['slug'])
                || !is_string($term['name'])
                || (!is_string($term['parent_source']) && $term['parent_source'] !== null)
                || !is_int($term['target_id'])
                || $term['target_id'] <= 0) {
                throw new \InvalidArgumentException('Target taxonomy state is invalid.');
            }

            $key = $term['taxonomy'] . ':' . $term['slug'];
            if (isset($targets[$key])) {
                throw new SourceRecordException('taxonomy_target_collision', 'A target taxonomy slug is not unique.');
            }
            $targets[$key] = $term;
        }

        $ordered = array_values($source);
        usort($ordered, fn (TaxonomyAssignment $left, TaxonomyAssignment $right): int =>
            [$this->taxonomyOrder($left->taxonomy), $this->depth($left, $source), $left->order, $left->termIdentity->canonical()]
            <=> [$this->taxonomyOrder($right->taxonomy), $this->depth($right, $source), $right->order, $right->termIdentity->canonical()]
        );

        $plans = [];
        foreach ($ordered as $assignment) {
            if ($assignment->targetDisposition === 'block') {
                throw new SourceRecordException('taxonomy_policy_blocked', 'A taxonomy assignment is explicitly blocked.');
            }

            $targetTaxonomy = self::TARGET_TAXONOMIES[$assignment->taxonomy] ?? null;
            if ($targetTaxonomy === null || $assignment->targetDisposition === 'provenance') {
                $plans[] = $this->row($assignment, null, 'provenance', null);
                continue;
            }

            $target = $targets[$targetTaxonomy . ':' . $assignment->slug] ?? null;
            if ($target !== null && (
                $target['name'] !== $assignment->name
                || $target['parent_source'] !== $assignment->parent?->canonical()
            )) {
                throw new SourceRecordException('taxonomy_target_collision', 'A target taxonomy slug has different meaning or ancestry.');
            }

            $plans[] = $this->row(
                $assignment,
                $targetTaxonomy,
                $target === null ? 'create' : 'link',
                $target['target_id'] ?? null,
            );
        }

        return $plans;
    }

    /** @param array<string, TaxonomyAssignment> $source */
    private function depth(TaxonomyAssignment $assignment, array $source): int
    {
        $depth = 0;
        $seen = [];
        $parent = $assignment->parent;
        while ($parent !== null) {
            $key = $parent->canonical();
            if (isset($seen[$key])) {
                throw new SourceRecordException('taxonomy_parent_cycle', 'The source taxonomy hierarchy contains a cycle.');
            }
            $seen[$key] = true;
            $depth++;
            $parent = $source[$key]->parent ?? null;
        }
        return $depth;
    }

    private function taxonomyOrder(string $taxonomy): int
    {
        return match ($taxonomy) {
            'product_cat' => 0,
            'product_brand' => 1,
            default => 2,
        };
    }

    /** @return array<string, int|string|null> */
    private function row(TaxonomyAssignment $assignment, ?string $targetTaxonomy, string $action, ?int $targetId): array
    {
        return [
            'source_identity' => $assignment->termIdentity->canonical(),
            'source_id' => $assignment->termIdentity->sourceId,
            'source_taxonomy' => $assignment->taxonomy,
            'target_taxonomy' => $targetTaxonomy,
            'action' => $action,
            'target_id' => $targetId,
            'name' => $assignment->name,
            'slug' => $assignment->slug,
            'description' => $assignment->description,
            'parent_source' => $assignment->parent?->canonical(),
            'order' => $assignment->order,
            'assigned' => $assignment->assigned ? 1 : 0,
        ];
    }
}
