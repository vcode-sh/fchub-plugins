<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Support;

use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\RateSchedule;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RateScheduleTest extends TestCase
{
    #[Test]
    public function emptySettingsDoNotScheduleRemoteRefreshes(): void
    {
        RateSchedule::sync(new OptionStore());

        self::assertFalse(wp_next_scheduled('fchub_mc_refresh_rates'));
        self::assertSame([], $GLOBALS['wp_remote_requests']);
    }

    #[Test]
    public function manualSettingsClearAnExistingRemoteRefresh(): void
    {
        wp_schedule_event(100, 'fchub_mc_rate_interval', 'fchub_mc_refresh_rates');
        $this->setOption('fchub_mc_settings', ['rate_provider' => 'manual']);

        RateSchedule::sync(new OptionStore());

        self::assertFalse(wp_next_scheduled('fchub_mc_refresh_rates'));
        self::assertSame([], $GLOBALS['wp_remote_requests']);
    }

    #[Test]
    public function remoteSettingsScheduleExactlyOneRecurringRefresh(): void
    {
        $this->setOption('fchub_mc_settings', ['rate_provider' => 'ecb']);

        RateSchedule::sync(new OptionStore());
        $first = $GLOBALS['wp_scheduled_events']['fchub_mc_refresh_rates'] ?? null;
        RateSchedule::sync(new OptionStore());

        self::assertIsArray($first);
        self::assertSame('fchub_mc_rate_interval', $first['recurrence']);
        self::assertCount(1, $GLOBALS['wp_scheduled_events']);
        self::assertSame([], $GLOBALS['wp_remote_requests']);
    }

    #[Test]
    public function keyedProviderWithoutAKeyDoesNotScheduleRefreshes(): void
    {
        $this->setOption('fchub_mc_settings', [
            'rate_provider' => 'exchange_rate_api',
            'rate_provider_api_key' => '',
        ]);

        RateSchedule::sync(new OptionStore());

        self::assertFalse(wp_next_scheduled('fchub_mc_refresh_rates'));
    }
}
