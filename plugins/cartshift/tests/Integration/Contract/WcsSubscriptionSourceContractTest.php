<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Contract;

require_once __DIR__ . '/InstalledContractTestCase.php';

final class WcsSubscriptionSourceContractTest extends InstalledContractTestCase
{
    public function testInstalledWcsKeepsTypedRelationshipsAndAbsentScheduleDates(): void
    {
        $result = $this->runRuntimeContract('wcs-subscription-source-contract');

        self::assertTrue($result['source_is_wc_subscription']);
        self::assertMatchesRegularExpression('/\Acontract-wcs:subscription:[1-9][0-9]*\z/D', $result['identity']);
        self::assertSame(['parent', 'renewal', 'switch', 'resubscribe'], $result['relationship_keys']);
        self::assertSame([$result['expected_parent_id']], $result['parent_ids']);
        self::assertSame([$result['expected_renewal_id']], $result['renewal_ids']);
        self::assertSame([], $result['switch_ids']);
        self::assertSame([], $result['resubscribe_ids']);
        self::assertSame([
            ['identity' => 'contract-wcs:order:' . $result['expected_parent_id'], 'relationship' => 'parent'],
            ['identity' => 'contract-wcs:order:' . $result['expected_renewal_id'], 'relationship' => 'renewal'],
        ], $result['canonical_relationships']);
        self::assertNull($result['next_payment_utc']);
        self::assertNull($result['end_utc']);
        self::assertSame('month', $result['period']);
        self::assertSame(1, $result['multiplier']);
        self::assertSame('PLN', $result['currency']);
        self::assertSame(1, $result['item_count']);
        self::assertContains($result['product_dependency'], $result['dependencies']);
        self::assertTrue($result['product_is_wc_subscription']);
        self::assertSame([], $result['product_data_has_subscription_fields']);
        $expectedConfiguration = [
            'subscription_length' => '5',
            'subscription_period' => 'month',
            'subscription_period_interval' => '1',
            'subscription_price' => '20.00',
            'subscription_sign_up_fee' => '0',
            'subscription_trial_length' => '0',
            'subscription_trial_period' => 'day',
        ];
        self::assertSame($expectedConfiguration, $result['product_configuration']);
        self::assertSame($expectedConfiguration, $result['synthetic_variation_configuration']);
    }
}
