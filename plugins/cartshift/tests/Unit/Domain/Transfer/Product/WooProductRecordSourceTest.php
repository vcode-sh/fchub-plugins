<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Product\ProductRecordFactory;
use CartShift\Domain\Transfer\Product\WooProductRecordSource;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class WooProductRecordSourceTest extends PluginTestCase
{
    public function testVariationObjectCannotBeExportedAsASecondRootProduct(): void
    {
        $variation = new class {
            public function get_id(): int { return 13; }
            public function get_parent_id(): int { return 12; }
        };
        $source = new WooProductRecordSource(new ProductRecordFactory(), static fn (): array => [$variation]);
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::ids([13]),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
        );

        try {
            iterator_to_array($source->records($selection), false);
            self::fail('A Woo variation became an independent package product.');
        } catch (SourceRecordException $exception) {
            self::assertSame('product_root_expected', $exception->reasonCode);
        }
    }
}
