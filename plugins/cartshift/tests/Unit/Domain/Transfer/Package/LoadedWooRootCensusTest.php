<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Audit\WooSourceApi;
use CartShift\Domain\Transfer\Package\LoadedWooRootCensus;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedWooRootCensusTest extends PluginTestCase
{
    public function testAllSelectionEnumeratesRootProductsAndEveryRegisteredUserInCanonicalKindOrder(): void
    {
        $source = new CensusWooSourceApi([41, 42], [71]);
        $census = new LoadedWooRootCensus(
            $source,
            static fn (): array => [12, 9],
            static fn (): array => [8, 3],
        );
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::all(),
        );

        self::assertSame([
            'shop-alpha:product:9',
            'shop-alpha:product:12',
            'shop-alpha:customer:3',
            'shop-alpha:customer:8',
            'shop-alpha:order:41',
            'shop-alpha:order:42',
            'shop-alpha:subscription:71',
        ], array_map(static fn ($identity): string => $identity->canonical(), iterator_to_array($census->identities($selection), false)));
    }

    public function testExplicitIdsRemainRootsEvenWhenTheCurrentCensusNoLongerContainsThem(): void
    {
        $census = new LoadedWooRootCensus(
            new CensusWooSourceApi([], []),
            static fn (): array => [],
            static fn (): array => [],
        );
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::ids([99]),
            SelectionClause::ids([88]),
            SelectionClause::ids([77]),
            SelectionClause::ids([66]),
        );

        self::assertSame([
            'shop-alpha:product:99',
            'shop-alpha:customer:88',
            'shop-alpha:order:77',
            'shop-alpha:subscription:66',
        ], array_map(static fn ($identity): string => $identity->canonical(), iterator_to_array($census->identities($selection), false)));
    }
}

final class CensusWooSourceApi implements WooSourceApi
{
    /** @param list<int> $orders @param list<int> $subscriptions */
    public function __construct(private readonly array $orders, private readonly array $subscriptions) {}
    public function productCensusPage(int $page, int $limit): array { return []; }
    public function semanticProductIds(): array { return []; }
    public function lookupProductIds(): array { return []; }
    public function product(int $id): ?array { return ['id' => $id, 'modified_gmt' => '2026-08-10T00:00:00Z']; }
    public function orderCensusPage(int $page, int $limit): array { return $page === 1 ? $this->orders : []; }
    public function order(int $id): ?array { return ['id' => $id, 'modified_gmt' => '2026-08-10T00:00:00Z']; }
    public function subscriptionCensusPage(int $page, int $limit): array { return $page === 1 ? $this->subscriptions : []; }
    public function subscription(int $id): ?array { return ['id' => $id, 'modified_gmt' => '2026-08-10T00:00:00Z']; }
}
