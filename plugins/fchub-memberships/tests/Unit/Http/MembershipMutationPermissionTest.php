<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http;

use FChubMemberships\Http\Controllers\MemberController;
use FChubMemberships\Http\ApplicationPasswordRequestContext;
use FChubMemberships\Http\MembershipMutationPermission;
use FChubMemberships\Support\Migrations;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipMutationPermissionTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_current_user_can'] = false;
        $GLOBALS['_fchub_test_current_user_can_checks'] = [];
        $GLOBALS['_fchub_test_current_user_caps'] = [];
        $GLOBALS['_fchub_test_role_lookups'] = [];
        $GLOBALS['_fchub_test_roles'] = [];
    }

    public function test_exposes_the_dedicated_membership_capability(): void
    {
        self::assertSame('manage_fchub_memberships', MembershipMutationPermission::CAPABILITY);
    }

    public function test_allows_the_backwards_compatible_administrator_fallback(): void
    {
        $GLOBALS['_fchub_test_current_user_caps'] = [
            'manage_options' => true,
        ];

        self::assertTrue(MembershipMutationPermission::check(new \WP_REST_Request()));
        self::assertSame(
            ['manage_fchub_memberships', 'manage_options'],
            $GLOBALS['_fchub_test_current_user_can_checks']
        );
    }

    public function test_allows_a_user_with_only_the_dedicated_capability(): void
    {
        $GLOBALS['_fchub_test_current_user_caps'] = [
            'manage_fchub_memberships' => true,
            'manage_options' => false,
        ];

        self::assertTrue(MembershipMutationPermission::check(new \WP_REST_Request()));
        self::assertSame(
            ['manage_fchub_memberships'],
            $GLOBALS['_fchub_test_current_user_can_checks']
        );
    }

    public function test_denies_an_ordinary_logged_in_user(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 42;

        self::assertFalse(MembershipMutationPermission::check(new \WP_REST_Request()));
    }

    public function test_denies_an_anonymous_user(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 0;

        self::assertFalse(MembershipMutationPermission::check(new \WP_REST_Request()));
    }

    public function test_application_password_authentication_uses_the_already_authenticated_user(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 73;
        $GLOBALS['_fchub_test_current_user_caps'] = [
            'manage_fchub_memberships' => true,
        ];

        $request = new class ('POST', '/fchub-memberships/v1/admin/members/grant') extends \WP_REST_Request {
            public function get_header(string $key): mixed
            {
                throw new \LogicException('The permission policy must not inspect authentication headers.');
            }
        };
        $request->set_header('Authorization', 'Basic deliberately-not-a-password');

        self::assertTrue(MembershipMutationPermission::check($request));
        self::assertSame(
            ['manage_fchub_memberships'],
            $GLOBALS['_fchub_test_current_user_can_checks']
        );
    }

    public function test_idempotency_is_required_only_for_the_captured_application_password_user(): void
    {
        $user = new \WP_User();
        $user->ID = 73;
        ApplicationPasswordRequestContext::authenticated($user, []);

        try {
            $GLOBALS['_fchub_test_current_user_id'] = 73;
            self::assertTrue(MembershipMutationPermission::requiresIdempotencyKey());

            $GLOBALS['_fchub_test_current_user_id'] = 74;
            self::assertFalse(MembershipMutationPermission::requiresIdempotencyKey());
        } finally {
            ApplicationPasswordRequestContext::clear();
        }

        self::assertFalse(MembershipMutationPermission::requiresIdempotencyKey());
    }

    public function test_only_the_eight_membership_mutations_use_the_dedicated_policy(): void
    {
        MemberController::registerRoutes();

        $mutationPaths = [
            '/admin/members/grant',
            '/admin/members/revoke',
            '/admin/members/pause',
            '/admin/members/resume',
            '/admin/members/extend',
            '/admin/members/bulk-grant',
            '/admin/members/bulk-revoke',
            '/admin/members/bulk-extend',
        ];

        foreach ($mutationPaths as $path) {
            self::assertSame(
                [MembershipMutationPermission::class, 'check'],
                $GLOBALS['_fchub_test_routes']['fchub-memberships/v1' . $path]['permission_callback'],
                $path
            );
        }

        $existingAdminPaths = [
            '/admin/members',
            '/admin/members/(?P<user_id>\d+)',
            '/admin/members/(?P<user_id>\d+)/drip-timeline',
            '/admin/members/export',
            '/admin/members/bulk-export',
            '/admin/members/(?P<user_id>\d+)/audit-log',
            '/admin/members/(?P<user_id>\d+)/activity',
        ];

        foreach ($existingAdminPaths as $path) {
            self::assertSame(
                [MemberController::class, 'adminPermission'],
                $GLOBALS['_fchub_test_routes']['fchub-memberships/v1' . $path]['permission_callback'],
                $path
            );
        }
    }

    public function test_administrator_capability_installation_is_idempotent(): void
    {
        $role = new MembershipTestRole();
        $GLOBALS['_fchub_test_roles']['administrator'] = $role;

        Migrations::ensureAdministratorCapability();
        Migrations::ensureAdministratorCapability();

        self::assertTrue($role->has_cap('manage_fchub_memberships'));
        self::assertSame(['manage_fchub_memberships'], $role->addedCapabilities);
        self::assertSame(['administrator', 'administrator'], $GLOBALS['_fchub_test_role_lookups']);
    }

    public function test_capability_installation_does_not_create_a_missing_role(): void
    {
        Migrations::ensureAdministratorCapability();

        self::assertSame(['administrator'], $GLOBALS['_fchub_test_role_lookups']);
        self::assertSame([], $GLOBALS['_fchub_test_roles']);
    }
}

final class MembershipTestRole
{
    /** @var array<string, bool> */
    private array $capabilities = [];

    /** @var list<string> */
    public array $addedCapabilities = [];

    public function has_cap(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    public function add_cap(string $capability): void
    {
        $this->capabilities[$capability] = true;
        $this->addedCapabilities[] = $capability;
    }
}
