<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Graph;

use CartShift\Domain\Transfer\Graph\SourceClosureResolver;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class SourceClosureResolverTest extends PluginTestCase
{
    public function testResolvesTransitiveClosureAndCanonicalisesHostileEnumerationOrder(): void
    {
        $customer = $this->record('customer', '7');
        $product = $this->record('product', '9');
        $order = $this->record('order', '41', [$customer->identity, $product->identity]);
        $subscription = $this->record('subscription', '5', [$order->identity]);
        $available = array_column([$subscription, $order, $product, $customer], null, 'structuralFingerprint');
        $byIdentity = [];
        foreach ([$subscription, $order, $product, $customer] as $record) $byIdentity[$record->identity->canonical()] = $record;

        $result = (new SourceClosureResolver())->resolve(
            $this->selection(),
            [$subscription],
            static fn (SourceIdentity $identity): ?RecordEnvelope => $byIdentity[$identity->canonical()] ?? null,
        );

        self::assertSame(
            ['shop-alpha:product:9', 'shop-alpha:customer:7', 'shop-alpha:order:41', 'shop-alpha:subscription:5'],
            array_map(static fn (RecordEnvelope $record): string => $record->identity->canonical(), $result->records),
        );
        self::assertCount(4, $available, 'The fixture really was supplied in reverse topological order.');
    }

    public function testMissingOrCrossSourceDependencyBlocksWithoutPartialClosure(): void
    {
        $missing = new SourceIdentity('shop-alpha', 'customer', '7');
        $root = $this->record('order', '41', [$missing]);

        try {
            (new SourceClosureResolver())->resolve($this->selection(), [$root], static fn (): null => null);
            self::fail('A package closure with a missing customer was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame('dependency_missing', $exception->reasonCode);
            self::assertStringNotContainsString('customer:7', $exception->getMessage());
        }
    }

    public function testReverseDependencyOptInChangesRootAndMaterializedFingerprints(): void
    {
        $product = $this->record('product', '9');
        $order = $this->record('order', '41', [$product->identity]);
        $resolver = new SourceClosureResolver();
        $lookup = static fn (SourceIdentity $identity): ?RecordEnvelope => $identity->canonical() === $product->identity->canonical() ? $product : null;

        $plain = $resolver->resolve($this->selection(), [$product], $lookup);
        $withReverseSelection = new TransferSelection('shop-alpha', SelectionClause::ids([9]), SelectionClause::none(), SelectionClause::none(), SelectionClause::none(), ['order']);
        $withReverse = $resolver->resolve(
            $withReverseSelection,
            [$product],
            $lookup,
            static fn (RecordEnvelope $record, string $kind): array => $kind === 'order' ? [$order] : [],
        );

        self::assertNotSame($plain->rootSelectionFingerprint, $withReverse->rootSelectionFingerprint);
        self::assertNotSame($plain->materializedClosureFingerprint, $withReverse->materializedClosureFingerprint);
        self::assertCount(2, $withReverse->records);
    }

    public function testProductEmbeddedVariationTaxonomyAndAssetDependenciesMaterialiseWithoutLiveFallback(): void
    {
        $productIdentity = new SourceIdentity('shop-alpha', 'product', '9');
        $term = new SourceIdentity('shop-alpha', 'taxonomy_term', '3:product-cat');
        $media = new SourceIdentity('shop-alpha', 'media_asset', '77');
        $download = new SourceIdentity('shop-alpha', 'download_asset', '9:download:manual');
        $product = RecordEnvelope::forPayload(2, $productIdentity, [
            'dependencies' => [],
            'taxonomies' => [['term_identity' => $term->canonical(), 'parent' => null, 'name' => 'Tea']],
            'media' => [],
            'downloads' => [],
            'variations' => [[
                'identity' => 'shop-alpha:product:9:variation:91',
                'media' => [['identity' => $media->canonical(), 'locator' => 'https://example.test/a.jpg']],
                'downloads' => [['identity' => $download->canonical(), 'locator' => 'private://manual']],
            ]],
        ]);
        $order = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'order', '41'), [
            'dependencies' => [],
            'customer' => null,
            'parent_order' => null,
            'product_lines' => [[
                'product' => $productIdentity->canonical(),
                'variation' => 'shop-alpha:product:9:variation:91',
            ]],
        ]);
        $lookups = 0;
        $result = (new SourceClosureResolver())->resolve(
            $this->selection(),
            [$order],
            static function (SourceIdentity $identity) use ($product, &$lookups): ?RecordEnvelope {
                ++$lookups;
                return $identity->canonical() === $product->identity->canonical() ? $product : null;
            },
        );

        self::assertSame(
            ['taxonomy_term', 'media_asset', 'download_asset', 'product', 'order'],
            array_map(static fn (RecordEnvelope $record): string => $record->identity->entityType, $result->records),
        );
        self::assertSame(2, $lookups, 'The variation resolves through its parent product; embedded dependencies never hit live source lookup.');
    }

    public function testSharedMediaAndTaxonomyMaterialiseOnceDespiteOwnerSpecificRelationshipMetadata(): void
    {
        $media = new SourceIdentity('shop-alpha', 'media_asset', '77');
        $term = new SourceIdentity('shop-alpha', 'taxonomy_term', '3:product-cat');
        $product = static function (string $id, string $role, bool $assigned) use ($media, $term): RecordEnvelope {
            $identity = new SourceIdentity('shop-alpha', 'product', $id);
            return RecordEnvelope::forPayload(2, $identity, [
                'identity' => $identity->canonical(),
                'dependencies' => [],
                'taxonomies' => [[
                    'term_identity' => $term->canonical(), 'parent' => null,
                    'taxonomy' => 'product_cat', 'name' => 'Tea', 'slug' => 'tea',
                    'description' => '', 'serial' => 0, 'action' => 'assign', 'assigned' => $assigned,
                ]],
                'media' => [[
                    'identity' => $media->canonical(),
                    'locator' => 'https://example.test/a.jpg',
                    'role' => $role,
                    'mime_type' => 'image/jpeg',
                    'size' => 12,
                    'owner' => $identity->canonical(),
                    'provenance' => 'own',
                    'expected_sha256' => str_repeat('a', 64),
                ]],
                'downloads' => [], 'upsell_products' => [], 'cross_sell_products' => [],
            ]);
        };

        $result = (new SourceClosureResolver())->resolve(
            $this->selection(),
            [$product('9', 'featured', true), $product('10', 'gallery', false)],
            static fn (): null => null,
        );

        self::assertSame(1, count(array_filter($result->records, static fn (RecordEnvelope $record): bool => $record->identity->entityType === 'media_asset')));
        self::assertSame(1, count(array_filter($result->records, static fn (RecordEnvelope $record): bool => $record->identity->entityType === 'taxonomy_term')));
    }

    public function testMalformedVariationAssetCollectionsBlockInsteadOfShrinkingTheClosure(): void
    {
        $identity = new SourceIdentity('shop-alpha', 'product', '9');
        $product = RecordEnvelope::forPayload(2, $identity, [
            'dependencies' => [],
            'taxonomies' => [],
            'media' => [],
            'downloads' => [],
            'variations' => [[
                'identity' => 'shop-alpha:product:9:variation:91',
                'media' => 'not-a-list',
                'downloads' => [],
            ]],
        ]);

        try {
            (new SourceClosureResolver())->resolve(
                $this->selection(),
                [$product],
                static fn (): null => null,
            );
            self::fail('A malformed variation asset list silently shrank the package closure.');
        } catch (SourceRecordException $exception) {
            self::assertSame('dependency_shape_invalid', $exception->reasonCode);
        }
    }

    private function selection(): TransferSelection
    {
        return new TransferSelection('shop-alpha', SelectionClause::ids([9]), SelectionClause::none(), SelectionClause::none(), SelectionClause::ids([5]));
    }

    /** @param list<SourceIdentity> $dependencies */
    private function record(string $kind, string $id, array $dependencies = []): RecordEnvelope
    {
        return RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', $kind, $id), [
            'dependencies' => array_map(static fn (SourceIdentity $identity): string => $identity->canonical(), $dependencies),
        ]);
    }
}
