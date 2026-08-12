<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\Product\ParentStockExceptionReport;
use CartShift\Tests\Unit\PluginTestCase;

require_once __DIR__ . '/FluentCartProductWriterTest.php';

final class ParentStockExceptionReportTest extends PluginTestCase
{
    public function testOnlyReceiptOwnedVariationEvidenceCanConfirmThePostMigrationReport(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $expected = $this->expected();
        $gateway->products[100] = ['ID' => 100];
        $gateway->variations[101] = $this->variation(100, 101, $expected);
        $gateway->variations[999] = $this->variation(998, 999, $expected);
        $receipt = new TransferReceipt(
            'report-run',
            'product',
            'lapka-web:product:42',
            1,
            str_repeat('a', 64),
            'created',
            ['primary' => 100, $expected['source_variation'] => 101],
            null,
            str_repeat('b', 64),
            1,
            '2026-08-12T12:00:00Z',
            '2026-08-12T12:00:01Z',
        );
        $reporter = new ParentStockExceptionReport($gateway);

        $confirmed = $reporter->confirm([$receipt], [$expected]);

        self::assertCount(1, $confirmed);
        self::assertTrue($confirmed[0]['target_verified']);

        $gateway->variations[101]['available'] = 1;
        $drifted = $reporter->confirm([$receipt], [$expected]);
        self::assertFalse($drifted[0]['target_verified']);

        $gateway->variations[101] = $this->variation(100, 101, $expected);
        $gateway->variations[101]['other_info']['stock_migration_exception']['source_stock']['low_stock_threshold'] = 9;
        self::assertFalse($reporter->confirm([$receipt], [$expected])[0]['target_verified']);

        $unowned = $reporter->confirm([], [$expected]);
        self::assertNull($unowned[0]['target_verified']);
    }

    public function testNonStockFollowUpItemsSurviveStockConfirmationUnchanged(): void
    {
        $skipped = [
            'kind' => 'skipped_product',
            'title' => 'Store membership',
            'dependent_orders' => 1,
            'dependent_subscriptions' => 2,
        ];

        $report = (new ParentStockExceptionReport(new InMemoryProductTargetGateway()))
            ->confirm([], [$skipped, $this->expected()]);

        self::assertSame($skipped, $report[0]);
        self::assertNull($report[1]['target_verified']);
    }

    /** @return array<string,mixed> */
    private function expected(): array
    {
        return [
            'kind' => 'shared_parent_stock',
            'product_name' => 'Trail harness',
            'variation_name' => 'Harness size: Large',
            'sku' => 'HARNESS-L',
            'source_variation' => 'lapka-web:product:42:variation:101',
            'source_owner' => 'lapka-web:product:42',
            'source_quantity' => 11,
            'source_status' => 'instock',
            'source_backorders' => 'yes',
            'source_stock' => [
                'ownership' => 'parent',
                'owner' => 'lapka-web:product:42',
                'quantity' => 11,
                'status' => 'instock',
                'backorders' => 'yes',
                'sold_individually' => false,
                'low_stock_threshold' => 2,
            ],
        ];
    }

    /** @param array<string,mixed> $expected @return array<string,mixed> */
    private function variation(int $productId, int $variationId, array $expected): array
    {
        $projection = [
            'manage_stock' => 1,
            'total_stock' => 0,
            'available' => 0,
            'committed' => 0,
            'on_hold' => 0,
            'stock_status' => 'out-of-stock',
            'backorders' => 0,
        ];

        return [
            'id' => $variationId,
            'post_id' => $productId,
            ...$projection,
            'other_info' => [
                'stock_migration_exception' => [
                    'version' => 1,
                    'type' => 'shared_parent_stock',
                    'source_variation' => $expected['source_variation'],
                    'source_stock' => $expected['source_stock'],
                    'target_projection' => $projection,
                    'requires_manual_resolution' => true,
                ],
            ],
        ];
    }
}
