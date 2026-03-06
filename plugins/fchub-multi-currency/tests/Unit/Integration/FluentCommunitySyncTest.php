<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\FluentCommunitySync;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FluentCommunitySyncTest extends TestCase
{
    #[Test]
    public function testOnContextSwitchedWritesUserMeta(): void
    {
        FluentCommunitySync::onContextSwitched('eur', 42);

        $this->assertSame('EUR', $GLOBALS['wp_mock_user_meta'][42]['_fcom_preferred_currency']);
    }

    #[Test]
    public function testOnContextSwitchedFiresActionHook(): void
    {
        FluentCommunitySync::onContextSwitched('eur', 42);

        $this->assertHookFired('fchub_mc/community_currency_updated');
    }

    #[Test]
    public function testOnContextSwitchedSkipsForUserIdZero(): void
    {
        FluentCommunitySync::onContextSwitched('eur', 0);

        $this->assertEmpty($GLOBALS['wp_mock_user_meta']);
        $this->assertHookNotFired('fchub_mc/community_currency_updated');
    }

    #[Test]
    public function testOnContextSwitchedSkipsWhenDisabled(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, ['fluentcommunity_enabled' => 'no']);

        FluentCommunitySync::onContextSwitched('eur', 42);

        $this->assertEmpty($GLOBALS['wp_mock_user_meta']);
        $this->assertHookNotFired('fchub_mc/community_currency_updated');
    }
}
