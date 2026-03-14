<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Support\Migrations;
use CartShift\Tests\Unit\PluginTestCase;

final class MigrationsTest extends PluginTestCase
{
    public function testNeedsUpgradeWhenNoVersionStored(): void
    {
        // No option stored means version '0', which is < '1'.
        unset($GLOBALS['_cartshift_test_options']['cartshift_db_version']);

        $this->assertTrue(Migrations::needsUpgrade());
    }

    public function testNeedsUpgradeWhenVersionIsOld(): void
    {
        // Explicitly stored '0' should still need upgrade.
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '0';

        $this->assertTrue(Migrations::needsUpgrade());
    }

    public function testNoUpgradeNeededWhenCurrent(): void
    {
        // Version '1' matches CURRENT_VERSION, no upgrade needed.
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '1';

        $this->assertFalse(Migrations::needsUpgrade());
    }
}
