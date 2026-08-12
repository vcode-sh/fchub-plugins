<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http;

use CartShift\Http\GuidedPreflightPresentation;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/PreflightStubs.php';
require_once dirname(__DIR__, 2) . '/stubs/HttpCliStubs.php';

final class GuidedPreflightPresentationTest extends PluginTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_post_status_counts']);

        parent::tearDown();
    }

    public function testItAppliesGuidedPolicyAndReturnsOnlyListShapedFriendlyChecks(): void
    {
        $GLOBALS['_cartshift_test_post_status_counts']['shop_coupon'] = (object) [
            'publish' => 2,
            'draft' => 0,
            'pending' => 0,
            'private' => 0,
            'future' => 0,
        ];
        $preflight = [
            'ready' => true,
            'checks' => [
                'fc_data' => [
                    'severity' => 'warn',
                    'counts' => ['products' => 1],
                ],
                'wc_subscriptions' => [
                    'severity' => 'pass',
                    'active' => false,
                ],
            ],
        ];

        $payload = (new GuidedPreflightPresentation())->evaluate($preflight);

        self::assertSame(['ready', 'checks'], array_keys($payload));
        self::assertTrue($payload['ready']);
        self::assertTrue(array_is_list($payload['checks']));
        self::assertSame([
            [
                'label' => 'Existing FluentCart records',
                'severity' => 'warn',
                'message' => 'FluentCart already has products. CartShift will ask whether to use, create, or skip each possible match. '
                    . 'Existing products will not be overwritten.',
            ],
            [
                'label' => 'WooCommerce Subscriptions',
                'severity' => 'pass',
                'message' => 'Subscriptions are not active and will be skipped.',
            ],
            [
                'label' => 'Standalone coupons',
                'severity' => 'warn',
                'message' => '2 standalone WooCommerce coupons will not be migrated yet. '
                    . 'Applied coupon history stays on migrated orders.',
            ],
        ], $payload['checks']);
    }

    public function testExistingCommerceRecordsAreAReviewWarningInsteadOfAnAggregateBlocker(): void
    {
        $payload = (new GuidedPreflightPresentation())->evaluate([
            'ready' => true,
            'checks' => [
                'fc_data' => [
                    'severity' => 'warn',
                    'counts' => [
                        'products' => 2,
                        'customers' => 97,
                        'orders' => 34,
                        'subscriptions' => 4,
                        'coupons' => 8,
                    ],
                ],
            ],
        ]);

        self::assertTrue($payload['ready']);
        self::assertSame('warn', $payload['checks'][0]['severity']);
        self::assertSame(
            'FluentCart already has 97 customers, 34 orders and 4 subscriptions. '
                . 'CartShift will check for safe matches during review. Unrelated records will stay untouched, '
                . 'and existing records will not be overwritten.',
            $payload['checks'][0]['message'],
        );
        self::assertArrayNotHasKey('counts', $payload['checks'][0]);
    }
}
