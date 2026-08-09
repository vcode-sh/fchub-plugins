<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\SourceTopology;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Which runtime owns the source data, and what that costs.
 *
 * The whole point of this enum is that it cannot see the database. Woo's data
 * stores are bound to the booted site and prefix, so two WordPress
 * installations sharing one MariaDB are still two runtimes — a fact the
 * decision has to reach without ever being told the database host or name.
 */
final class SourceTopologyTest extends PluginTestCase
{
    public function testAllThreeSubsystemsBootedHereIsSameRuntime(): void
    {
        $this->assertSame(
            SourceTopology::SameRuntime,
            SourceTopology::decide(
                wooCommerceBooted: true,
                wooSubscriptionsBooted: true,
                fluentCartBooted: true,
            ),
        );
    }

    public function testFluentCartAloneIsCrossRuntime(): void
    {
        $this->assertSame(
            SourceTopology::CrossRuntime,
            SourceTopology::decide(
                wooCommerceBooted: false,
                wooSubscriptionsBooted: false,
                fluentCartBooted: true,
            ),
        );
    }

    public function testWooAndSubscriptionsWithoutFluentCartIsCrossRuntime(): void
    {
        $this->assertSame(
            SourceTopology::CrossRuntime,
            SourceTopology::decide(
                wooCommerceBooted: true,
                wooSubscriptionsBooted: true,
                fluentCartBooted: false,
            ),
        );
    }

    /**
     * WooCommerce without the Subscriptions add-on cannot serve subscription
     * records at all, so it is not a same-runtime source however much
     * FluentCart is sitting next to it.
     */
    public function testWooWithoutSubscriptionsIsCrossRuntime(): void
    {
        $this->assertSame(
            SourceTopology::CrossRuntime,
            SourceTopology::decide(
                wooCommerceBooted: true,
                wooSubscriptionsBooted: false,
                fluentCartBooted: true,
            ),
        );
    }

    /**
     * The decision takes booted subsystems and nothing else. If a database
     * identity could reach it, a shared MariaDB would eventually be mistaken
     * for a shared WordPress runtime — the exact trap the plan forbids.
     */
    public function testTheDecisionCannotBeToldAboutTheDatabase(): void
    {
        $parameters = (new \ReflectionMethod(SourceTopology::class, 'decide'))->getParameters();

        $names = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        );

        $this->assertSame(
            ['wooCommerceBooted', 'wooSubscriptionsBooted', 'fluentCartBooted'],
            $names,
        );
    }

    public function testBackingValuesAreTheDocumentedStrings(): void
    {
        $this->assertSame('same_runtime', SourceTopology::SameRuntime->value);
        $this->assertSame('cross_runtime', SourceTopology::CrossRuntime->value);
    }

    public function testCrossRuntimeRequiresThePackageRoute(): void
    {
        $this->assertTrue(SourceTopology::CrossRuntime->requiresPackage());
        $this->assertFalse(SourceTopology::SameRuntime->requiresPackage());
    }
}
