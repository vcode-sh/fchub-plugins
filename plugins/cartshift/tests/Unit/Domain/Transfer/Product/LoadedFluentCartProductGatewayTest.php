<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedFluentCartProductGatewayTest extends PluginTestCase
{
    public function testSharingOneMediaIdentityAcrossProductsDoesNotMultiplyOwnershipMetadata(): void
    {
        $ownershipRows = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use (&$ownershipRows): array {
            if (!str_contains($query, "meta_key = '_cartshift_source_identity'")) {
                return [];
            }
            return array_values(array_map(
                static fn (string $value): array => ['meta_value' => $value],
                $ownershipRows,
            ));
        };
        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use (&$ownershipRows): int {
            if (($data['meta_key'] ?? null) === '_cartshift_source_identity') {
                $ownershipRows[] = (string) $data['meta_value'];
            }
            return 1;
        };
        $media = [[
            'target_id' => 99,
            'source_identity' => 'shop-alpha:media_asset:7',
            'owner_identity' => 'shop-alpha:product:1',
            'role' => 'featured',
            'provenance' => 'own',
            'sha256' => str_repeat('a', 64),
        ]];
        $gateway = new LoadedFluentCartProductGateway();

        $gateway->attachMedia(1, [], $media);
        $media[0]['owner_identity'] = 'shop-alpha:product:2';
        $gateway->attachMedia(2, [], $media);

        self::assertSame(
            ['shop-alpha:media_asset:7'],
            $ownershipRows,
            'A shared attachment must retain one immutable source-identity claim.',
        );
    }

    public function testSharedAttachmentCannotBeClaimedByADifferentSourceIdentity(): void
    {
        $ownershipRows = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use (&$ownershipRows): array {
            if (!str_contains($query, "meta_key = '_cartshift_source_identity'")) {
                return [];
            }
            return array_values(array_map(
                static fn (string $value): array => ['meta_value' => $value],
                $ownershipRows,
            ));
        };
        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use (&$ownershipRows): int {
            if (($data['meta_key'] ?? null) === '_cartshift_source_identity') {
                $ownershipRows[] = (string) $data['meta_value'];
            }
            return 1;
        };
        $gateway = new LoadedFluentCartProductGateway();
        $media = [[
            'target_id' => 99,
            'source_identity' => 'shop-alpha:media_asset:7',
            'owner_identity' => 'shop-alpha:product:1',
            'role' => 'featured',
            'provenance' => 'own',
            'sha256' => str_repeat('a', 64),
        ]];
        $gateway->attachMedia(1, [], $media);
        $media[0]['source_identity'] = 'shop-alpha:media_asset:8';
        $media[0]['owner_identity'] = 'shop-alpha:product:2';

        $this->expectExceptionMessage('Target post metadata ownership changed.');
        $gateway->attachMedia(2, [], $media);
    }
}
