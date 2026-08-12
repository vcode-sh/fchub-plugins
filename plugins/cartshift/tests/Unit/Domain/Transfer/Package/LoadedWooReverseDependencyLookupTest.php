<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Package\LoadedWooReverseDependencyLookup;
use CartShift\Domain\Transfer\Package\SourceRootCensus;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedWooReverseDependencyLookupTest extends PluginTestCase
{
    public function testReverseOrderIndexUsesCanonicalDomainReferencesAndIgnoresUnrelatedOrders(): void
    {
        $product = new SourceIdentity('shop-alpha', 'product', '9');
        $customer = new SourceIdentity('shop-alpha', 'customer', '7');
        $matching = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'order', '41'), [
            'identity' => 'shop-alpha:order:41',
            'dependencies' => [],
            'customer' => $customer->canonical(),
            'parent_order' => null,
            'product_lines' => [[
                'product' => $product->canonical(),
                'variation' => 'shop-alpha:product:9:variation:13',
            ]],
        ]);
        $unrelated = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'order', '42'), [
            'identity' => 'shop-alpha:order:42',
            'dependencies' => [],
            'customer' => 'shop-alpha:customer:8',
            'parent_order' => null,
            'product_lines' => [],
        ]);
        $records = [
            $matching->identity->canonical() => $matching,
            $unrelated->identity->canonical() => $unrelated,
        ];
        $census = new class([$matching->identity, $unrelated->identity]) implements SourceRootCensus {
            public function __construct(private readonly array $identities) {}
            public function identities(TransferSelection $selection): iterable { yield from $this->identities; }
        };
        $lookup = new LoadedWooReverseDependencyLookup(
            'shop-alpha',
            $census,
            static fn (SourceIdentity $identity): ?RecordEnvelope => $records[$identity->canonical()] ?? null,
        );

        self::assertSame(
            ['shop-alpha:order:41'],
            array_map(static fn (RecordEnvelope $record): string => $record->identity->canonical(), iterator_to_array($lookup->records(
                RecordEnvelope::forPayload(2, $product, ['identity' => $product->canonical(), 'dependencies' => []]),
                'order',
            ), false)),
        );
        self::assertSame(
            ['shop-alpha:order:41'],
            array_map(static fn (RecordEnvelope $record): string => $record->identity->canonical(), iterator_to_array($lookup->records(
                RecordEnvelope::forPayload(2, $customer, ['identity' => $customer->canonical(), 'dependencies' => []]),
                'order',
            ), false)),
        );
    }
}
