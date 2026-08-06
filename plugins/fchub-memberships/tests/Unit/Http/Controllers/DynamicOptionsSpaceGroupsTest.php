<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\DynamicOptionsController;
use FChubMemberships\Integration\Community\FluentCommunitySpaceGroupResolver;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class DynamicOptionsSpaceGroupsTest extends PluginTestCase
{
    public function test_registers_and_serves_the_admin_space_group_options_route(): void
    {
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            define('FLUENT_COMMUNITY_PLUGIN_VERSION', '2.7.5');
        }

        DynamicOptionsController::registerRoutes();

        self::assertArrayHasKey(
            'fchub-memberships/v1/admin/fc-space-groups',
            $GLOBALS['_fchub_test_routes']
        );

        $resolver = new FluentCommunitySpaceGroupResolver(
            static fn(string $query, int $limit): array => [[
                'id' => 7,
                'title' => 'Members',
                'spaces' => [['id' => 31, 'title' => 'Members Lounge', 'type' => 'community']],
            ]]
        );

        $response = DynamicOptionsController::fcSpaceGroups(
            new \WP_REST_Request('GET', '/groups', ['search' => 'members']),
            $resolver
        );

        self::assertSame([
            [
                'id' => '7',
                'label' => 'Members',
                'spaces' => [['id' => '31', 'label' => 'Members Lounge']],
            ],
        ], $response->get_data()['data']);
    }
}
