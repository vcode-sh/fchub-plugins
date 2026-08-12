<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\LoadedCatalogueActivator;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Domain\Transfer\Product\ProductTargetGateway;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedCatalogueActivatorTest extends PluginTestCase
{
    public function testSharedDependencyClaimOwnedByEarlierProductDoesNotLookLikeActivationDrift(): void
    {
        $snapshot = [
            'product' => ['post_status' => 'draft', 'post_title' => 'Second product'],
            'detail' => ['stock_availability' => 'in-stock'],
            'variations' => [['id' => 202, 'source_identity' => 'shop-alpha:product:42:variation:42']],
            'taxonomies' => [],
            'taxonomy_rows' => [],
            'media' => [['source_identity' => 'shop-alpha:media_asset:71', 'target_id' => 701]],
            'downloads' => [],
        ];
        $sourceMap = [
            'shop-alpha:media_asset:71' => 701,
            'shop-alpha:product:42' => 902,
            'shop-alpha:product:42:variation:42' => 202,
        ];
        $after = (new ProductTargetFingerprint())->fingerprint($snapshot, $sourceMap);
        $receipt = new TransferReceipt(
            'run-catalogue-22',
            'product',
            'shop-alpha:product:42',
            1,
            str_repeat('a', 64),
            'created',
            ['primary' => 902, 'variation_0' => 202, 'media_0' => 701] + $sourceMap,
            null,
            $after,
            2,
            '2026-08-10T12:00:00Z',
            '2026-08-10T12:00:01Z',
        );

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($after): array {
            $rows = [
                ['entity_type' => 'media_asset', 'wc_id' => '71', 'fc_id' => '701', 'target_fingerprint' => str_repeat('b', 64), 'record_state' => 'reconciled'],
                ['entity_type' => 'product', 'wc_id' => '42', 'fc_id' => '902', 'target_fingerprint' => $after, 'record_state' => 'reconciled'],
                ['entity_type' => 'product', 'wc_id' => '42:variation:42', 'fc_id' => '202', 'target_fingerprint' => $after, 'record_state' => 'reconciled'],
            ];
            return str_contains($query, 'target_fingerprint =') ? array_slice($rows, 1) : $rows;
        };
        $status = 'draft';
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$status): string {
            return str_contains($query, 'post_status') ? $status : '';
        };
        $GLOBALS['_cartshift_test_update_callback'] = static function (string $table, array $data) use (&$status): int {
            if ($table === 'wp_posts' && isset($data['post_status'])) {
                $status = (string) $data['post_status'];
            }
            return 1;
        };

        $change = (new LoadedCatalogueActivator('shop-alpha', new CatalogueSnapshotGateway($snapshot)))
            ->activate($receipt, 'publish');

        self::assertSame('publish', $change->afterStatus);
        self::assertSame('publish', $status);
    }
}

final class CatalogueSnapshotGateway implements ProductTargetGateway
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(private array $snapshot) {}
    public function createTaxonomyTerm(array $plan, ?int $parentTargetId): int { throw new \LogicException(); }
    public function createDraftProduct(array $fields): int { throw new \LogicException(); }
    public function createProductDetail(int $productId, array $fields): int { throw new \LogicException(); }
    public function createVariation(int $productId, array $fields): int { throw new \LogicException(); }
    public function finishProductDetail(int $productId, int $defaultVariationId, int $minPrice, int $maxPrice): void { throw new \LogicException(); }
    public function assignTaxonomies(int $productId, array $targetTermIds): void { throw new \LogicException(); }
    public function attachMedia(int $productId, array $variationIds, array $stagedMedia): array { throw new \LogicException(); }
    public function createDownload(int $productId, array $variationIds, array $fields): int { throw new \LogicException(); }
    public function exists(int $productId): bool { return true; }
    public function snapshot(int $productId): array { return $this->snapshot; }
    public function behaviour(int $productId, array $variationIds): array { throw new \LogicException(); }
}
