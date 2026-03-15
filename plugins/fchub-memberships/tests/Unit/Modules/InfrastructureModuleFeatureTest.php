<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Modules;

use FChubMemberships\Modules\Infrastructure\InfrastructureModule;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class InfrastructureModuleFeatureTest extends PluginTestCase
{
    public function test_infrastructure_module_covers_schedules_dispatchers_and_notice_rendering(): void
    {
        $module = new InfrastructureModule();

        $schedules = $module->registerCronSchedules([]);
        self::assertSame(300, $schedules['five_minutes']['interval']);
        self::assertSame($schedules, $module->registerCronSchedules($schedules));

        $module->sendEmail('alice@example.com', 'Subject', '<p>Body</p>', ['Content-Type: text/html']);
        self::assertCount(1, $GLOBALS['_fchub_test_mails']);

        $GLOBALS['_fchub_test_remote_post_result'] = new \WP_Error('failed', 'Boom');
        $module->dispatchWebhook('https://example.com/hook', '{"x":1}', ['Content-Type' => 'application/json']);
        self::assertCount(1, $GLOBALS['_fchub_test_fc_error_logs']);

        $GLOBALS['_fchub_test_remote_post_result'] = ['response' => ['code' => 200]];
        $module->dispatchWebhook('https://example.com/hook-success', '{"ok":1}', ['Content-Type' => 'application/json']);
        self::assertCount(2, $GLOBALS['_fchub_test_remote_posts']);

        ob_start();
        $module->renderFluentCartNotice();
        $notice = ob_get_clean();
        if (defined('FLUENTCART_VERSION')) {
            self::assertSame('', $notice);
        } else {
            self::assertStringContainsString('requires FluentCart', $notice);
        }
    }
}
