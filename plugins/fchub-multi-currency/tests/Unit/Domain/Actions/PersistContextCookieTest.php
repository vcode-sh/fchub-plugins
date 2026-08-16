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

        $optionStore = new OptionStore();
        $result = (new PersistContextAction(new PreferenceRepository(), $optionStore))->execute('EUR');

        $this->assertCount(1, $GLOBALS['fchub_mc_setcookie_calls']);
        $this->assertEmpty($GLOBALS['wp_mock_user_meta']);
        $this->assertTrue($result->cookieStored);
        $this->assertFalse($result->userMetaStored);
        $this->assertTrue($result->persisted());
    }

    #[Test]
    public function testCurrencyCookieIsReadableByTheStorefrontRecoveryScript(): void
    {
        $this->setOption('fchub_mc_settings', [
            'cookie_enabled' => 'yes',
            'cookie_lifetime_days' => 30,
        ]);

        (new PersistContextAction(new PreferenceRepository(), new OptionStore()))->execute('eur');

        $this->assertCount(1, $GLOBALS['fchub_mc_setcookie_calls']);
        $call = $GLOBALS['fchub_mc_setcookie_calls'][0];

        $this->assertSame('fchub_mc_currency', $call['name']);
        $this->assertSame('EUR', $call['value']);
        $this->assertSame('/', $call['options']['path']);
        $this->assertSame('Lax', $call['options']['samesite']);
        $this->assertFalse($call['options']['httponly']);
    }

    #[Test]
    public function testCookieSkippedWhenDisabled(): void
    {
        $this->setOption('fchub_mc_settings', [
            'cookie_enabled' => 'no',
        ]);

        $result = (new PersistContextAction(new PreferenceRepository(), new OptionStore()))->execute('EUR');

        $this->assertFalse($result->cookieStored, 'Cookie should be disabled');
        $this->assertCount(0, $GLOBALS['fchub_mc_setcookie_calls']);
    }

    #[Test]
    public function testGuestWithCookiesDisabledPersistsNothing(): void
    {
        // The reported bug: a logged-out visitor has no persistence channel at all.
        $this->setOption('fchub_mc_settings', [
            'cookie_enabled' => 'no',
        ]);

        $action = new PersistContextAction(new PreferenceRepository(), new OptionStore());
        $result = $action->execute('EUR');

        $this->assertFalse($result->cookieStored);
        $this->assertFalse($result->userMetaStored);
        $this->assertFalse($result->persisted(), 'Guest preference cannot survive without cookies');
        $this->assertEmpty($GLOBALS['wp_mock_user_meta']);
    }

    #[Test]
    public function testFailedCookieHeaderIsNotReportedAsPersisted(): void
    {
        $this->setOption('fchub_mc_settings', ['cookie_enabled' => 'yes']);
        $GLOBALS['fchub_mc_setcookie_result'] = false;

        $result = (new PersistContextAction(new PreferenceRepository(), new OptionStore()))->execute('EUR');

        $this->assertFalse($result->cookieStored);
        $this->assertFalse($result->persisted());
    }

    #[Test]
    public function testFailedUserMetaWriteIsNotReportedAsPersisted(): void
    {
        $this->setCurrentUserId(7);
        $this->setOption('fchub_mc_settings', ['cookie_enabled' => 'no']);
        $GLOBALS['wp_mock_update_user_meta_result'] = false;

        $result = (new PersistContextAction(new PreferenceRepository(), new OptionStore()))->execute('EUR');

        $this->assertFalse($result->userMetaStored);
        $this->assertFalse($result->persisted());
    }

    #[Test]
    public function testAlreadyMatchingUserMetaCountsAsPersistedWhenWordPressReturnsFalse(): void
    {
        $this->setCurrentUserId(7);
        $this->setOption('fchub_mc_settings', ['cookie_enabled' => 'no']);
        $GLOBALS['wp_mock_user_meta'][7]['_fchub_mc_currency'] = 'EUR';
        $GLOBALS['wp_mock_update_user_meta_result'] = false;

        $result = (new PersistContextAction(new PreferenceRepository(), new OptionStore()))->execute('EUR');

        $this->assertTrue($result->userMetaStored);
        $this->assertTrue($result->persisted());
    }

    #[Test]
    public function testLoggedInVisitorPersistsThroughUserMetaWhenCookiesAreDisabled(): void
    {
        $this->setCurrentUserId(7);
        $this->setOption('fchub_mc_settings', [
            'cookie_enabled' => 'no',
        ]);

        $result = (new PersistContextAction(new PreferenceRepository(), new OptionStore()))->execute('EUR');

        $this->assertFalse($result->cookieStored);
        $this->assertTrue($result->userMetaStored);
        $this->assertTrue($result->persisted());
    }

    #[Test]
    public function testBothChannelsDisabledPersistsNothingEvenWhenLoggedIn(): void
    {
        $this->setCurrentUserId(7);
        $this->setOption('fchub_mc_settings', [
            'cookie_enabled'              => 'no',
            'account_persistence_enabled' => 'no',
        ]);

        $result = (new PersistContextAction(new PreferenceRepository(), new OptionStore()))->execute('EUR');

        $this->assertFalse($result->persisted());
    }

    #[Test]
    public function testUserMetaSavedWhenCookiesAreDisabled(): void
    {
        $this->setCurrentUserId(42);
        $this->setOption('fchub_mc_settings', [
            'cookie_enabled' => 'no',
        ]);

        $repo = new PreferenceRepository();
        $action = new PersistContextAction($repo, new OptionStore());
        $action->execute('EUR');

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
        $action->execute('GBP');

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
        $action->execute('EUR');

        $this->assertSame('', $GLOBALS['wp_mock_user_meta'][42]['_fchub_mc_currency'] ?? '');
    }
}
