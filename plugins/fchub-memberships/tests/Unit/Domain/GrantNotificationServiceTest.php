<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantNotificationServiceTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Alice Example';
        $user->user_email = 'alice@example.com';
        $user->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $user;
        $GLOBALS['_fchub_test_options']['admin_email'] = 'admin@example.com';
    }

    public function test_grant_notification_service_sends_expected_emails_for_all_events(): void
    {
        $plans = new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return $id === 5 ? ['id' => 5, 'title' => 'Gold Plan', 'slug' => 'gold-plan'] : null;
            }
        };

        $service = new GrantNotificationService($plans);

        $service->sendGranted(21, 5, [
            ['title' => 'Members Post', 'url' => 'https://example.com/post', 'drip_type' => 'immediate'],
            ['title' => 'Future Post', 'available_date' => '2026-03-20', 'drip_type' => 'delayed'],
        ]);
        $service->sendRevoked(21, 5, 'Canceled');
        $service->sendPaused(['user_id' => 21, 'plan_id' => 5]);
        $service->sendResumed(['user_id' => 21, 'plan_id' => 5, 'expires_at' => '2026-04-01']);

        self::assertCount(4, $GLOBALS['_fchub_test_mails']);
        self::assertStringContainsString('Welcome to Gold Plan!', $GLOBALS['_fchub_test_mails'][0][1]);
        self::assertStringContainsString('Gold Plan', $GLOBALS['_fchub_test_mails'][1][2]);
        self::assertStringContainsString('paused', strtolower($GLOBALS['_fchub_test_mails'][2][1]));
        self::assertStringContainsString('active again', strtolower($GLOBALS['_fchub_test_mails'][3][1]));
    }

    public function test_grant_notification_service_skips_when_disabled_or_plan_missing(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_access_granted' => 'no',
            'email_access_revoked' => 'no',
        ];

        $plans = new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        };

        $service = new GrantNotificationService($plans);
        $service->sendGranted(21, 5, []);
        $service->sendRevoked(21, 5, 'Canceled');
        $service->sendPaused(['user_id' => 21, 'plan_id' => 5]);
        $service->sendResumed(['user_id' => 21, 'plan_id' => 5]);

        self::assertCount(0, $GLOBALS['_fchub_test_mails']);
    }
}
