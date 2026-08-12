<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Product\TaxonomyAssignment;
use CartShift\Domain\Transfer\Product\TaxonomyPlanner;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;

final class TaxonomyPlannerTest extends PluginTestCase
{
    public function testCategoriesPreserveHierarchyAndBrandsUseInstalledTaxonomy(): void
    {
        $parent = $this->assignment('product_cat', '10:product-cat', 'Training', 'training', null, 2);
        $child = $this->assignment('product_cat', '11:product-cat', 'Puppies', 'puppies', $parent->termIdentity, 1);
        $brand = $this->assignment('product_brand', '12:product-brand', 'DogCo', 'dogco');

        $plans = (new TaxonomyPlanner())->plan([$child, $brand, $parent], []);

        self::assertSame(
            ['10:product-cat', '11:product-cat', '12:product-brand'],
            array_map(static fn (array $plan): string => $plan['source_id'], $plans),
        );
        self::assertSame('product-categories', $plans[0]['target_taxonomy']);
        self::assertNull($plans[0]['parent_source']);
        self::assertSame($parent->termIdentity->canonical(), $plans[1]['parent_source']);
        self::assertSame('product-brands', $plans[2]['target_taxonomy']);
        self::assertSame(['Training', 'Puppies', 'DogCo'], array_column($plans, 'name'));
    }

    public function testTagsAndUnsupportedCustomTaxonomiesAreProvenanceNotInventedTerms(): void
    {
        $plans = (new TaxonomyPlanner())->plan([
            $this->assignment('product_tag', '20:product-tag', 'Popular', 'popular'),
            $this->assignment('pa_dog_level', '21:pa-dog-level', 'Advanced', 'advanced'),
        ], []);

        self::assertSame(['provenance', 'provenance'], array_column($plans, 'action'));
        self::assertSame([null, null], array_column($plans, 'target_taxonomy'));
    }

    public function testSameTargetSlugWithDifferentMeaningOrParentBlocksLinking(): void
    {
        $parent = $this->assignment('product_cat', '10:product-cat', 'Training', 'training');
        $source = $this->assignment('product_cat', '11:product-cat', 'Puppies', 'puppies', $parent->termIdentity);

        foreach ([
            [['taxonomy' => 'product-categories', 'slug' => 'puppies', 'name' => 'Adults', 'parent_source' => $source->parent?->canonical(), 'target_id' => 91]],
            [['taxonomy' => 'product-categories', 'slug' => 'puppies', 'name' => 'Puppies', 'parent_source' => null, 'target_id' => 91]],
        ] as $targets) {
            try {
                (new TaxonomyPlanner())->plan([$parent, $source], $targets);
                self::fail('Ambiguous taxonomy collision was accepted.');
            } catch (SourceRecordException $exception) {
                self::assertSame('taxonomy_target_collision', $exception->reasonCode);
            }
        }
    }

    public function testMissingParentAndDuplicateSourceIdentityBothBlock(): void
    {
        $orphan = $this->assignment('product_cat', '11:product-cat', 'Puppies', 'puppies', $this->identity('10:product-cat'));
        foreach ([[$orphan], [$orphan, $orphan]] as $assignments) {
            try {
                (new TaxonomyPlanner())->plan($assignments, []);
                self::fail('Invalid taxonomy graph was accepted.');
            } catch (SourceRecordException $exception) {
                self::assertContains($exception->reasonCode, ['taxonomy_parent_missing', 'taxonomy_source_duplicate']);
            }
        }
    }

    private function assignment(
        string $taxonomy,
        string $id,
        string $name,
        string $slug,
        ?SourceIdentity $parent = null,
        int $order = 0,
    ): TaxonomyAssignment {
        return new TaxonomyAssignment(
            $taxonomy,
            $this->identity($id),
            $name,
            $slug,
            $name . ' description',
            $parent,
            $order,
            'assign',
        );
    }

    private function identity(string $id): SourceIdentity
    {
        return new SourceIdentity('lapka-web', RecordKind::TaxonomyTerm->value, $id);
    }
}
