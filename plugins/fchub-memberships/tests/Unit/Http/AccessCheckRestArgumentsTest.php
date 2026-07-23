<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http;

use FChubMemberships\Http\AccessCheckController;
use FChubMemberships\Http\AccessCheckRestArguments;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AccessCheckRestArgumentsTest extends PluginTestCase
{
    public function test_schema_declares_exact_public_arguments_types_and_bounds(): void
    {
        $args = AccessCheckRestArguments::all();

        self::assertSame([
            'user_id',
            'email',
            'plan',
            'resource_type',
            'resource_id',
            'provider',
        ], array_keys($args));
        self::assertSame(['type' => 'integer', 'minimum' => 1], array_intersect_key(
            $args['user_id'],
            array_flip(['type', 'minimum'])
        ));
        self::assertSame('email', $args['email']['format']);
        self::assertSame(254, $args['email']['maxLength']);
        self::assertSame(100, $args['plan']['maxLength']);
        self::assertSame(50, $args['resource_type']['maxLength']);
        self::assertSame(100, $args['resource_id']['maxLength']);
        self::assertSame(['wordpress_core'], $args['provider']['enum']);
        self::assertArrayNotHasKey('default', $args['provider']);

        AccessCheckController::registerRoutes();
        $route = $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/check-access'];
        self::assertSame(array_keys($args), array_keys($route['args']));
        self::assertContains(
            [AccessCheckController::class, 'addRateLimitHeaders'],
            $GLOBALS['_fchub_test_filters']['rest_post_dispatch']
        );
    }

    public function test_request_validation_requires_exact_user_and_resource_selectors(): void
    {
        self::assertTrue(AccessCheckRestArguments::validateRequest(new \WP_REST_Request('GET', '/check-access', [
            'user_id' => 21,
            'plan' => 'gold',
        ])));
        self::assertTrue(AccessCheckRestArguments::validateRequest(new \WP_REST_Request('GET', '/check-access', [
            'email' => 'alice@example.com',
            'resource_type' => 'post',
            'resource_id' => '55',
            'provider' => 'wordpress_core',
        ])));

        foreach ([
            ['user_id' => 21, 'email' => 'alice@example.com', 'plan' => 'gold'],
            ['user_id' => 21],
            ['user_id' => 21, 'resource_type' => 'post'],
            ['user_id' => 21, 'resource_id' => '55'],
            ['user_id' => 21, 'provider' => 'wordpress_core'],
            ['user_id' => 21, 'plan' => 'gold', 'resource_type' => 'post', 'resource_id' => '55'],
        ] as $params) {
            $result = AccessCheckRestArguments::validateRequest(new \WP_REST_Request('GET', '/check-access', $params));
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame(422, $result->get_error_data()['status']);
        }
    }

    public function test_authenticated_self_check_may_omit_user_but_not_resource_selector(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 21;

        self::assertTrue(AccessCheckRestArguments::validateRequest(new \WP_REST_Request('GET', '/check-access', [
            'plan' => 'gold',
        ])));
        self::assertInstanceOf(\WP_Error::class, AccessCheckRestArguments::validateRequest(
            new \WP_REST_Request('GET', '/check-access')
        ));
    }
}
