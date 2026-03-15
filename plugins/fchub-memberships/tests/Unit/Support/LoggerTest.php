<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\Logger;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class LoggerTest extends PluginTestCase
{
    public function test_order_log_log_error_and_debug_cover_all_public_methods(): void
    {
        $order = new class {
            public array $entries = [];

            public function addLog(string $title, string $description, string $type, string $module): void
            {
                $this->entries[] = [$title, $description, $type, $module];
            }
        };

        Logger::orderLog($order, 'Title', 'Description', 'warning');
        Logger::orderLog(null, 'Ignored', 'Description', 'info');

        Logger::log('Sync', 'Completed', ['id' => 1]);
        Logger::error('Sync', 'Failed', ['id' => 2]);

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['debug_mode' => 'no'];
        Logger::debug('Debug', 'Disabled');
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['debug_mode' => 'yes'];
        Logger::debug('Debug', 'Enabled', ['flag' => true]);

        self::assertSame([['Title', 'Description', 'warning', 'Membership']], $order->entries);
        self::assertCount(2, $GLOBALS['_fchub_test_fc_logs']);
        self::assertSame('Sync', $GLOBALS['_fchub_test_fc_logs'][0][0]);
        self::assertSame('[DEBUG] Debug', $GLOBALS['_fchub_test_fc_logs'][1][0]);
        self::assertSame('Failed', $GLOBALS['_fchub_test_fc_error_logs'][0][1]);
    }
}
