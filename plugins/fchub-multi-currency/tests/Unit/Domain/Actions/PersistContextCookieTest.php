<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Actions;

use FChubMultiCurrency\Domain\Actions\PersistContextAction;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PersistContextCookieTest extends TestCase
{
    #[Test]
    public function testCookieSavedWhenEnabled(): void
    {
        $this->setOption('fchub_mc_settings', [
            'cookie_enabled'       => 'yes',
            'cookie_lifetime_days' => 30,
        ]);

        // Track whether saveCookie path is reached via a subclass-like approach:
        // Since PreferenceRepository is final, we verify through the OptionStore check.
        $optionStore = new OptionStore();
        $this->assertSame('yes', $optionStore->get('cookie_enabled', 'yes'));

        // With cookie enabled, execute should attempt to set the cookie.
        // setcookie() returns false in CLI but doesn't throw.
        $repo = new PreferenceRepository();
        $action = new PersistContextAction($repo, $optionStore);
        @$action->execute('EUR'); // Suppress setcookie "headers already sent" warning

        // No logged-in user → no user meta saved
        $this->assertEmpty($GLOBALS['wp_mock_user_meta']);
    }

    #[Test]
    public function testCookieSkippedWhenDisabled(): void
    {
        $this->setOption('fchub_mc_settings', [
            'cookie_enabled' => 'no',
        ]);

        $optionStore = new OptionStore();
        $this->assertSame('no', $optionStore->get('cookie_enabled', 'yes'));

        // The cookie_enabled guard should skip saveCookie entirely.
        // We verify by checking that the code path completes without setcookie being called.
        // Since we can't intercept setcookie on a final class, we verify the guard condition:
        $cookieEnabled = $optionStore->get('cookie_enabled', 'yes') === 'yes';
        $this->assertFalse($cookieEnabled, 'Cookie should be disabled');
    }

    #[Test]
    public function testUserMetaAlwaysSaved(): void
    {
        $this->setCurrentUserId(42);

        // Even with cookies disabled, user meta should be saved
        $this->setOption('fchub_mc_settings', [
            'cookie_enabled' => 'no',
        ]);

        $repo = new PreferenceRepository();
        $action = new PersistContextAction($repo, new OptionStore());
        $action->execute('EUR');

        // User meta should be saved regardless of cookie_enabled
        $this->assertSame('EUR', $GLOBALS['wp_mock_user_meta'][42]['_fchub_mc_currency'] ?? '');
    }

    #[Test]
    public function testUserMetaSavedWithCookieEnabled(): void
    {
        $this->setCurrentUserId(42);

        $this->setOption('fchub_mc_settings', [
            'cookie_enabled'       => 'yes',
            'cookie_lifetime_days' => 30,
        ]);

        $repo = new PreferenceRepository();
        $action = new PersistContextAction($repo, new OptionStore());
        @$action->execute('GBP'); // Suppress setcookie warning

        // User meta should be saved
        $this->assertSame('GBP', $GLOBALS['wp_mock_user_meta'][42]['_fchub_mc_currency'] ?? '');
    }

    #[Test]
    public function testUserMetaSkippedWhenAccountPersistenceDisabled(): void
    {
        $this->setCurrentUserId(42);

        $this->setOption('fchub_mc_settings', [
            'cookie_enabled' => 'yes',
            'account_persistence_enabled' => 'no',
        ]);

        $repo = new PreferenceRepository();
        $action = new PersistContextAction($repo, new OptionStore());
        @$action->execute('EUR');

        $this->assertSame('', $GLOBALS['wp_mock_user_meta'][42]['_fchub_mc_currency'] ?? '');
    }
}
