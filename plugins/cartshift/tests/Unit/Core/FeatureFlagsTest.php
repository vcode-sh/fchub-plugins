<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Core;

use CartShift\Core\FeatureFlags;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Feature flags.
 *
 * The class used to declare four "pro" constants — detailed_reporting, multi_source,
 * download_files, attribute_mapping — that nothing read, gating features nobody wrote.
 * They are gone. The runtime mechanism that would let a real flag be added later is not.
 */
final class FeatureFlagsTest extends PluginTestCase
{
    public function testCoreModulesAreAlwaysEnabled(): void
    {
        $flags = new FeatureFlags();

        $this->assertTrue($flags->isEnabled(FeatureFlags::INFRASTRUCTURE));
        $this->assertTrue($flags->isEnabled(FeatureFlags::ADMIN));
        $this->assertTrue($flags->isEnabled(FeatureFlags::MIGRATION));
    }

    public function testFreeFeaturesAreAlwaysEnabled(): void
    {
        $flags = new FeatureFlags();

        $this->assertTrue($flags->isEnabled(FeatureFlags::SUBSCRIPTIONS));
        $this->assertTrue($flags->isEnabled(FeatureFlags::BACKGROUND_PROCESSING));
        $this->assertTrue($flags->isEnabled(FeatureFlags::WP_CLI));
    }

    public function testUnknownFlagsAreOff(): void
    {
        $flags = new FeatureFlags();

        $this->assertFalse($flags->isEnabled('something_nobody_wrote'));
    }

    /**
     * No constant should name a feature that does not exist. If a future flag is added,
     * it needs code behind it — and this list needs updating deliberately.
     */
    public function testNoPhantomProConstantsRemain(): void
    {
        $declared = (new \ReflectionClass(FeatureFlags::class))->getConstants();

        foreach (['DETAILED_REPORTING', 'MULTI_SOURCE', 'DOWNLOAD_FILES', 'ATTRIBUTE_MAPPING'] as $phantom) {
            $this->assertArrayNotHasKey($phantom, $declared, "{$phantom} gates a feature that does not exist.");
        }
    }

    public function testStoredOptionEnablesAFlag(): void
    {
        update_option('cartshift_feature_flags', ['experimental' => true]);

        $flags = FeatureFlags::fromWordPress();

        $this->assertTrue($flags->isEnabled('experimental'));
        $this->assertSame(['experimental' => true], $flags->all());
    }

    public function testFilterCanOverrideStoredFlags(): void
    {
        update_option('cartshift_feature_flags', ['experimental' => false]);

        add_filter('cartshift/feature_flags', static fn(array $flags): array => ['experimental' => true]);

        $this->assertTrue(FeatureFlags::fromWordPress()->isEnabled('experimental'));
    }

    public function testGarbageStoredValueIsIgnored(): void
    {
        update_option('cartshift_feature_flags', 'not an array');

        $this->assertSame([], FeatureFlags::fromWordPress()->all());
    }

    /**
     * Values arrive from wp_options, so they may be strings. Coerce, do not trust.
     */
    public function testValuesAreNormalisedToBooleans(): void
    {
        update_option('cartshift_feature_flags', ['a' => '1', 'b' => '', 'c' => 0]);

        $this->assertSame(['a' => true, 'b' => false, 'c' => false], FeatureFlags::fromWordPress()->all());
    }
}
