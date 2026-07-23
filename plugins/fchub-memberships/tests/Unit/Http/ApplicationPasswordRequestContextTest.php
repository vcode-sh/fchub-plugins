<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http;

use FChubMemberships\Http\ApplicationPasswordRequestContext;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ApplicationPasswordRequestContextTest extends PluginTestCase
{
    protected function tearDown(): void
    {
        ApplicationPasswordRequestContext::clear();
        parent::tearDown();
    }

    public function test_records_only_the_successfully_authenticated_user_and_mode(): void
    {
        $user = new \WP_User();
        $user->ID = 73;

        ApplicationPasswordRequestContext::authenticated($user, [
            'uuid' => 'must-not-be-retained',
            'password' => 'must-not-be-retained',
        ]);

        self::assertTrue(ApplicationPasswordRequestContext::isAuthenticatedUser(73));
        self::assertFalse(ApplicationPasswordRequestContext::isAuthenticatedUser(74));
        self::assertSame('application_password', ApplicationPasswordRequestContext::mode());

        $state = (new \ReflectionClass(ApplicationPasswordRequestContext::class))->getStaticProperties();
        self::assertSame(['userId', 'mode'], array_keys($state));
        self::assertStringNotContainsString('must-not-be-retained', serialize($state));
    }

    public function test_clear_removes_request_authentication_state(): void
    {
        $user = new \WP_User();
        $user->ID = 73;
        ApplicationPasswordRequestContext::authenticated($user, []);

        ApplicationPasswordRequestContext::clear();

        self::assertFalse(ApplicationPasswordRequestContext::isAuthenticatedUser(73));
        self::assertNull(ApplicationPasswordRequestContext::mode());
    }

    public function test_first_successful_authentication_capture_cannot_be_replaced(): void
    {
        $first = new \WP_User();
        $first->ID = 73;
        $second = new \WP_User();
        $second->ID = 74;

        ApplicationPasswordRequestContext::authenticated($first, []);
        ApplicationPasswordRequestContext::authenticated($second, []);

        self::assertTrue(ApplicationPasswordRequestContext::isAuthenticatedUser(73));
        self::assertFalse(ApplicationPasswordRequestContext::isAuthenticatedUser(74));
    }
}
