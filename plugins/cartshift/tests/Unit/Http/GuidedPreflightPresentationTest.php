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
        self::assertFalse($payload['ready']);
        self::assertTrue(array_is_list($payload['checks']));
        self::assertSame([
            [
                'label' => 'Existing FluentCart records',
                'severity' => 'fail',
                'message' => 'FluentCart already contains records, so continuing could create duplicates. '
                    . 'If they are test records, remove them in FluentCart and reload this screen. '
                    . 'If they must be kept, stop here; CartShift will not overwrite them.',
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
}
