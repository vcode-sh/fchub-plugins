<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Core;

use FChubMemberships\Core\FeatureFlags;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class FeatureFlagsTest extends PluginTestCase
{
    public function test_unknown_flags_default_to_enabled(): void
    {
        $flags = new FeatureFlags();

        self::assertTrue($flags->isEnabled('anything'));
    }

    public function test_configured_flags_are_respected(): void
    {
        $flags = new FeatureFlags([
            'admin' => false,
            'infrastructure' => true,
        ]);

        self::assertFalse($flags->isEnabled('admin'));
        self::assertTrue($flags->isEnabled('infrastructure'));
    }
}
