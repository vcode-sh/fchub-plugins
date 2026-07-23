<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Modules;

use FChubMemberships\Core\Container;
use FChubMemberships\Integration\WebhookQueue;
use FChubMemberships\Modules\Infrastructure\InfrastructureModule;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class InfrastructureModuleFeatureTest extends PluginTestCase
{
    public function test_infrastructure_module_covers_schedules_worker_and_notice_rendering(): void
    {
        $worker = new class {
            public array $handled = [];
            public function handle(int $deliveryId): void { $this->handled[] = $deliveryId; }
        };
        $module = new InfrastructureModule(null, null, null, null, null, $worker);
        $module->register(new Container());

        $schedules = $module->registerCronSchedules([]);
        self::assertSame(300, $schedules['five_minutes']['interval']);
        self::assertSame($schedules, $module->registerCronSchedules($schedules));

        $module->sendEmail('alice@example.com', 'Subject', '<p>Body</p>', ['Content-Type: text/html']);
        self::assertCount(1, $GLOBALS['_fchub_test_mails']);

        do_action(WebhookQueue::HOOK, 71);
        self::assertSame([71], $worker->handled);
        self::assertArrayHasKey('fchub_memberships_webhook_reconcile', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('fchub_memberships_webhook_cleanup', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('init', $GLOBALS['_fchub_test_actions']);
        self::assertSame(4, $GLOBALS['_fchub_test_action_registrations']['init'][0]['priority']);

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
