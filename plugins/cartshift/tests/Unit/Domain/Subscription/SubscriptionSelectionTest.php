<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionSelectionTest extends PluginTestCase
{
    public function testExcludedSubscriptionsNeverEnterTheSelectedCohort(): void
    {
        $selection = new SubscriptionSelection(
            'lapka-klub',
            [30, 10, 10, 0, -2],
            [' ACTIVE ', 'active', 'ON-HOLD'],
            [20, 20, 0, -1],
        );

        $this->assertSame([10, 30], $selection->subscriptionIds);
        $this->assertSame([20], $selection->excludedSubscriptionIds);
        $this->assertSame(['active', 'on-hold'], $selection->statuses);
        $this->assertTrue($selection->includes(10, 'active'));
        $this->assertFalse($selection->includes(20, 'active'));
        $this->assertFalse($selection->includes(30, 'cancelled'));
    }

    public function testTheCanonicalDefinitionRoundTripsWithoutChangingItsFingerprint(): void
    {
        $selection = new SubscriptionSelection(
            'lapka-klub',
            [10, 30],
            ['on-hold'],
            [20],
        );

        $definition = [
            'source_key'                => 'lapka-klub',
            'statuses'                  => ['on-hold'],
            'subscription_ids'          => [10, 30],
            'excluded_subscription_ids' => [20],
        ];

        $this->assertSame($definition, $selection->toArray());

        $roundTrip = SubscriptionSelection::fromArray($definition);

        $this->assertSame($definition, $roundTrip->toArray());
        $this->assertSame($selection->fingerprint(), $roundTrip->fingerprint());
    }

    public function testAnOldAllSubscriptionsDefinitionKeepsItsOriginalCanonicalShape(): void
    {
        $selection = SubscriptionSelection::fromArray([
            'source_key'       => 'lapka-klub',
            'statuses'         => [],
            'subscription_ids' => [],
        ]);

        $this->assertSame([
            'source_key'       => 'lapka-klub',
            'statuses'         => [],
            'subscription_ids' => [],
        ], $selection->toArray());
    }
}
