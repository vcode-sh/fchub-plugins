<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantNotificationServiceTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ResourceTypeRegistry::reset();

        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Alice Example';
        $user->user_email = 'alice@example.com';
        $user->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $user;
        $GLOBALS['_fchub_test_options']['admin_email'] = 'admin@example.com';
        $GLOBALS['_fchub_test_options']['date_format'] = 'Y-m-d';
    }

    protected function tearDown(): void
    {
        ResourceTypeRegistry::reset();

        parent::tearDown();
    }

    /**
     * A plan_rules row as the database actually hands it back.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function rule(array $overrides = []): array
    {
        return array_merge([
            'id'              => 1,
            'plan_id'         => 5,
            'provider'        => 'wordpress_core',
            'resource_type'   => 'post',
            'resource_id'     => '0',
            'drip_delay_days' => 0,
            'drip_type'       => 'immediate',
            'drip_date'       => null,
            'sort_order'      => 0,
            'meta'            => [],
            'created_at'      => '2026-03-13 22:00:00',
            'updated_at'      => '2026-03-13 22:00:00',
        ], $overrides);
    }

    private function goldPlanRepository(): PlanRepository
    {
        return new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return $id === 5 ? ['id' => 5, 'title' => 'Gold Plan', 'slug' => 'gold-plan'] : null;
            }
        };
    }

    private function registerPost(int $id, string $title, string $status = 'publish'): void
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = 'post';
        $post->post_title = $title;
        $post->post_status = $status;
        $GLOBALS['_fchub_test_posts'][$id] = $post;
    }

    public function test_grant_notification_service_sends_expected_emails_for_all_events(): void
    {
        $this->registerPost(101, 'Members Only Guide');
        $this->registerPost(202, 'Advanced Workshop');
        $this->registerPost(203, 'Live Studio Session');
        $GLOBALS['_fchub_test_terms_by_taxonomy']['category'][9] = (object) [
            'term_id' => 9,
            'name'    => 'Member Library',
            'taxonomy' => 'category',
        ];

        $service = new GrantNotificationService($this->goldPlanRepository());

        $service->sendGranted(21, 5, [
            $this->rule(['id' => 1, 'resource_type' => 'post', 'resource_id' => '101']),
            $this->rule(['id' => 2, 'resource_type' => 'category', 'resource_id' => '9', 'sort_order' => 1]),
            $this->rule(['id' => 3, 'resource_type' => 'page', 'resource_id' => '*', 'sort_order' => 2]),
            $this->rule([
                'id'              => 4,
                'resource_type'   => 'post',
                'resource_id'     => '202',
                'drip_type'       => 'delayed',
                'drip_delay_days' => 3,
                'sort_order'      => 3,
            ]),
            $this->rule([
                'id'            => 5,
                'resource_type' => 'post',
                'resource_id'   => '203',
                'drip_type'     => 'fixed_date',
                'drip_date'     => '2026-05-01 12:00:00',
                'sort_order'    => 4,
            ]),
        ]);
        $service->sendRevoked(21, 5, 'Canceled');
        $service->sendPaused(['user_id' => 21, 'plan_id' => 5]);
        $service->sendResumed(['user_id' => 21, 'plan_id' => 5, 'expires_at' => '2026-04-01']);

        self::assertCount(4, $GLOBALS['_fchub_test_mails']);
        self::assertStringContainsString('Welcome to Gold Plan!', $GLOBALS['_fchub_test_mails'][0][1]);

        $granted = $GLOBALS['_fchub_test_mails'][0][2];

        // Immediate resources carry a real title and a real link.
        self::assertStringContainsString(
            '<li><a href="https://example.com/?p=101">Members Only Guide</a></li>',
            $granted
        );
        self::assertStringContainsString(
            '<li><a href="https://example.com/category/9">Member Library</a></li>',
            $granted
        );

        // A wildcard rule is described, not linked to nowhere.
        self::assertStringContainsString('<li>All Pages</li>', $granted);
        self::assertStringNotContainsString('href="#"', $granted);
        self::assertStringNotContainsString('<li></li>', $granted);

        // The lead-in sentence only appears because there is something to list.
        self::assertStringContainsString('You have immediate access to the following resources:', $granted);

        // Drip items carry a title and an honest availability description.
        self::assertStringContainsString('Coming Soon', $granted);
        self::assertStringContainsString('Advanced Workshop &mdash; 3 days after joining', $granted);
        self::assertStringContainsString('Live Studio Session &mdash; 2026-05-01', $granted);

        self::assertStringContainsString('Gold Plan', $GLOBALS['_fchub_test_mails'][1][2]);
        self::assertStringContainsString('paused', strtolower($GLOBALS['_fchub_test_mails'][2][1]));
        self::assertStringContainsString('active again', strtolower($GLOBALS['_fchub_test_mails'][3][1]));
    }

    public function test_granted_email_drops_rules_that_point_at_nothing_a_member_can_open(): void
    {
        $this->registerPost(101, 'Members Only Guide');

        $service = new GrantNotificationService($this->goldPlanRepository());

        $service->sendGranted(21, 5, [
            $this->rule(['id' => 1, 'resource_type' => 'post', 'resource_id' => '101']),
            // Deleted post: the rule survives, the content does not.
            $this->rule(['id' => 2, 'resource_type' => 'post', 'resource_id' => '404', 'sort_order' => 1]),
            // Missing term.
            $this->rule(['id' => 3, 'resource_type' => 'category', 'resource_id' => '77', 'sort_order' => 2]),
            // Protection plumbing, not content.
            $this->rule(['id' => 4, 'resource_type' => 'url_pattern', 'resource_id' => '/members/*', 'sort_order' => 3]),
            // CRM segmentation, not content.
            $this->rule([
                'id'            => 5,
                'provider'      => 'fluentcrm',
                'resource_type' => 'fluentcrm_tag',
                'resource_id'   => '12',
                'sort_order'    => 4,
            ]),
        ]);

        $granted = $GLOBALS['_fchub_test_mails'][0][2];

        self::assertSame(1, substr_count($granted, '<li>'));
        self::assertStringContainsString('Members Only Guide', $granted);
        self::assertStringNotContainsString('/members/*', $granted);
        self::assertStringNotContainsString('#404', $granted);
        self::assertStringNotContainsString('Coming Soon', $granted);
    }

    public function test_granted_email_omits_both_sections_when_nothing_resolves(): void
    {
        $service = new GrantNotificationService($this->goldPlanRepository());

        $service->sendGranted(21, 5, [
            $this->rule(['id' => 1, 'resource_type' => 'post', 'resource_id' => '404']),
            $this->rule([
                'id'              => 2,
                'resource_type'   => 'post',
                'resource_id'     => '405',
                'drip_type'       => 'delayed',
                'drip_delay_days' => 5,
                'sort_order'      => 1,
            ]),
        ]);

        self::assertCount(1, $GLOBALS['_fchub_test_mails']);
        $granted = $GLOBALS['_fchub_test_mails'][0][2];

        self::assertStringNotContainsString('You have immediate access to the following resources:', $granted);
        self::assertStringNotContainsString('Coming Soon', $granted);
        self::assertStringNotContainsString('<ul>', $granted);
        self::assertStringNotContainsString('<li>', $granted);
        self::assertStringContainsString('Welcome to Gold Plan', $granted);
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
