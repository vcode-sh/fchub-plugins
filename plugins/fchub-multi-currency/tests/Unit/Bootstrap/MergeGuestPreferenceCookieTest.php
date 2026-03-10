<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MergeGuestPreferenceCookieTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset cached resolver chain
        $ref = new \ReflectionClass(ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    #[Test]
    public function testMergeSkippedWhenCookieDisabled(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'        => 'yes',
            'cookie_enabled' => 'no',
        ]);

        $_COOKIE['fchub_mc_currency'] = 'EUR';

        $user = new \WP_User();
        $user->ID = 42;

        ContextModule::mergeGuestPreference('testuser', $user);

        // User meta should NOT be written when cookies are disabled
        $this->assertSame('', get_user_meta(42, '_fchub_mc_currency', true));
    }

    #[Test]
    public function testMergeWorksWhenCookieEnabled(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'             => 'yes',
            'cookie_enabled'      => 'yes',
            'base_currency'       => 'USD',
            'display_currencies'  => [['code' => 'EUR'], ['code' => 'GBP']],
        ]);

        $_COOKIE['fchub_mc_currency'] = 'EUR';

        $user = new \WP_User();
        $user->ID = 42;

        ContextModule::mergeGuestPreference('testuser', $user);

        // User meta should be written
        $this->assertSame('EUR', get_user_meta(42, '_fchub_mc_currency', true));
    }

    #[Test]
    public function testMergeSkippedWhenAccountPersistenceDisabled(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled' => 'yes',
            'cookie_enabled' => 'yes',
            'account_persistence_enabled' => 'no',
        ]);

        $_COOKIE['fchub_mc_currency'] = 'EUR';

        $user = new \WP_User();
        $user->ID = 42;

        ContextModule::mergeGuestPreference('testuser', $user);

        $this->assertSame('', get_user_meta(42, '_fchub_mc_currency', true));
    }
}
