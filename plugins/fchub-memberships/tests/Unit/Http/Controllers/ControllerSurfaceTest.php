<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

if (!defined('LEARNDASH_VERSION')) {
    define('LEARNDASH_VERSION', '4.0.0');
}
if (!defined('FLUENTCRM')) {
    define('FLUENTCRM', 'fluentcrm');
}
if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
    define('FLUENT_COMMUNITY_PLUGIN_VERSION', '2.7.0');
}

use FChubMemberships\Http\AccessApiCredential;
use FChubMemberships\Http\AccessCheckController;
use FChubMemberships\Http\AccountController;
use FChubMemberships\Http\DynamicOptionsController;
use FChubMemberships\Http\Controllers\ContentController;
use FChubMemberships\Http\Controllers\ImportController;
use FChubMemberships\Adapters\FluentCommunityAdapter;
use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ControllerSurfaceTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \FChubMemberships\Domain\AccessEvaluator::clearCache();
        \FChubMemberships\Storage\GrantRepository::clearRequestCache();
        \FChubMemberships\Storage\PlanRepository::clearCache();
        \FChubMemberships\Storage\ProtectionRuleRepository::clearCache();
        \FChubMemberships\Support\ResourceTypeRegistry::reset();

        $GLOBALS['_fchub_test_users'][21] = (object) [
            'ID' => 21,
            'display_name' => 'Alice Example',
            'user_email' => 'alice@example.com',
        ];
        $GLOBALS['_fchub_test_users_by_email']['alice@example.com'] = $GLOBALS['_fchub_test_users'][21];

        $post = new \WP_Post();
        $post->ID = 55;
        $post->post_type = 'post';
        $post->post_title = 'Members Post';
        $GLOBALS['_fchub_test_posts'][55] = $post;
        $GLOBALS['_fchub_test_post_types'] = ['post', 'page'];
    }

    public function test_access_check_and_account_controllers_cover_permissions_and_empty_states(): void
    {
        AccessCheckController::registerRoutes();
        AccountController::registerRoutes();

        self::assertArrayHasKey('fchub-memberships/v1/check-access', $GLOBALS['_fchub_test_routes']);
        self::assertArrayHasKey('fchub-memberships/v1/my-access', $GLOBALS['_fchub_test_routes']);

        $GLOBALS['_fchub_test_current_user_can'] = false;
        $GLOBALS['_fchub_test_current_user_id'] = 21;
        $GLOBALS['_fchub_test_current_user'] = (object) ['ID' => 21, 'user_email' => 'alice@example.com'];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = AccessApiCredential::migratePlaintext([
            'api_key' => 'secret',
        ]);

        $apiKeyRequest = new \WP_REST_Request('GET', '/check-access', ['api_key' => 'secret', 'user_id' => 999]);
        $headerRequest = new \WP_REST_Request('GET', '/check-access', ['user_id' => 999]);
        $headerRequest->set_header('X-API-Key', 'secret');
        $selfRequest = new \WP_REST_Request('GET', '/check-access', []);
        $foreignRequest = new \WP_REST_Request('GET', '/check-access', ['user_id' => 999]);

        self::assertFalse(AccessCheckController::checkPermission($apiKeyRequest));
        self::assertTrue(AccessCheckController::checkPermission($headerRequest));
        self::assertTrue(AccessCheckController::checkPermission($selfRequest));
        self::assertFalse(AccessCheckController::checkPermission($foreignRequest));

        $missing = AccessCheckController::check(new \WP_REST_Request('GET', '/check-access', []));
        $invalid = AccessCheckController::check(new \WP_REST_Request('GET', '/check-access', ['email' => 'alice@example.com']));

        self::assertSame(422, $invalid->get_status());
        self::assertSame(422, $missing->get_status());

        $account = AccountController::myAccess(new \WP_REST_Request('GET', '/my-access'))->get_data();
        self::assertSame([], $account['plans']);
        self::assertSame([], $account['history']);
    }

    public function test_dynamic_options_and_import_controller_cover_empty_and_validation_paths(): void
    {
        DynamicOptionsController::registerRoutes();
        ImportController::registerRoutes();

        self::assertArrayHasKey('fchub-memberships/v1/admin/providers', $GLOBALS['_fchub_test_routes']);
        self::assertArrayHasKey('fchub-memberships/v1/admin/import/parse', $GLOBALS['_fchub_test_routes']);

        $providers = DynamicOptionsController::providers(new \WP_REST_Request('GET', '/providers'))->get_data();
        $resourceTypes = DynamicOptionsController::resourceTypes(new \WP_REST_Request('GET', '/resource-types', ['provider' => 'missing']))->get_data();
        $crmTags = DynamicOptionsController::fluentcrmTags(new \WP_REST_Request('GET', '/crm-tags'))->get_data();
        $crmLists = DynamicOptionsController::fluentcrmLists(new \WP_REST_Request('GET', '/crm-lists'))->get_data();
        $communityCapabilities = new CommunityCapabilityRegistry(
            static fn(): array => [
                'core_active' => true,
                'core_version' => '2.7.0',
                'pro_active' => false,
                'pro_version' => null,
                'pro_certified' => false,
            ],
            static fn(string $feature): bool => $feature === 'course_module',
            static fn(string $capability): bool => in_array($capability, [
                'spaces',
                'courses',
                'profile_verification_read',
            ], true)
        );
        $spaces = DynamicOptionsController::fcSpaces(
            new \WP_REST_Request('GET', '/spaces'),
            new FluentCommunityAdapter($communityCapabilities)
        )->get_data();
        $badges = DynamicOptionsController::fcBadges(new \WP_REST_Request('GET', '/badges'))->get_data();

        self::assertSame([
            ['value' => 'wordpress_core', 'label' => 'WordPress Core'],
            ['value' => 'learndash', 'label' => 'LearnDash'],
            ['value' => 'fluentcrm', 'label' => 'FluentCRM'],
            ['value' => 'fluent_community', 'label' => 'FluentCommunity'],
        ], array_map(
            static fn(array $provider): array => [
                'value' => $provider['value'],
                'label' => $provider['label'],
            ],
            $providers['data']
        ));
        foreach ($providers['data'] as $provider) {
            self::assertArrayHasKey('status', $provider);
            self::assertArrayHasKey('capabilities', $provider);
            self::assertArrayHasKey('pending_operations', $provider);
            self::assertArrayHasKey('failed_operations', $provider);
        }
        self::assertSame([], $resourceTypes['data']);
        self::assertSame([
            ['id' => '11', 'label' => 'Gold Members'],
            ['id' => '12', 'label' => 'Silver Members'],
        ], $crmTags['data']);
        self::assertSame([
            ['id' => '21', 'label' => 'Premium List'],
            ['id' => '22', 'label' => 'Community Updates'],
        ], $crmLists['data']);
        self::assertSame([
            ['id' => '31', 'label' => 'VIP Space'],
            ['id' => '32', 'label' => 'General Space'],
        ], $spaces['data']);
        self::assertSame([], $badges['data']);

        self::assertSame(422, ImportController::parse(new \WP_REST_Request('POST', '/parse', []))->get_status());
        self::assertSame(422, ImportController::prepare(new \WP_REST_Request('POST', '/prepare', []))->get_status());
        self::assertSame(422, ImportController::execute(new \WP_REST_Request('POST', '/execute', ['members' => [], 'mappings' => []]))->get_status());
    }

    public function test_content_controller_covers_validation_and_search_helpers(): void
    {
        ContentController::registerRoutes();
        self::assertArrayHasKey('fchub-memberships/v1/admin/content', $GLOBALS['_fchub_test_routes']);
        self::assertArrayHasKey('fchub-memberships/v1/admin/content/protect', $GLOBALS['_fchub_test_routes']);

        $missing = ContentController::protect(new \WP_REST_Request('POST', '/protect', []));
        $invalidType = ContentController::protect(new \WP_REST_Request('POST', '/protect', [
            'resource_type' => 'missing',
            'resource_id' => '55',
        ]));
        $bulkMissing = ContentController::bulkProtect(new \WP_REST_Request('POST', '/bulk-protect', []));
        $bulkInvalid = ContentController::bulkProtect(new \WP_REST_Request('POST', '/bulk-protect', [
            'resource_type' => 'missing',
            'resource_ids' => [55],
        ]));
        $searchSpecial = ContentController::searchResources(new \WP_REST_Request('GET', '/search-resources', [
            'type' => 'special_page',
        ]))->get_data();

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => str_contains($query, "resource_type = 'url_pattern'")
            ? [['resource_id' => '/members-area']]
            : [];

        $searchUrlPattern = ContentController::searchResources(new \WP_REST_Request('GET', '/search-resources', [
            'type' => 'url_pattern',
        ]))->get_data();
        $resourceTypes = ContentController::resourceTypes(new \WP_REST_Request('GET', '/resource-types'))->get_data();

        self::assertSame(422, $missing->get_status());
        self::assertSame(422, $invalidType->get_status());
        self::assertSame(422, $bulkMissing->get_status());
        self::assertSame(422, $bulkInvalid->get_status());
        self::assertCount(6, $searchSpecial['data']);
        self::assertSame('/members-area', $searchUrlPattern['data'][0]['label']);
        self::assertNotEmpty($resourceTypes['data']);
        self::assertArrayHasKey('select_options', $resourceTypes);
    }
}
