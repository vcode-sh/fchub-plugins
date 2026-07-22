<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http;

use FChubMemberships\Http\Controllers\MemberController;
use FChubMemberships\Http\MembershipRestArguments;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipRestArgumentsTest extends PluginTestCase
{
    public function test_all_membership_mutation_routes_declare_args(): void
    {
        MemberController::registerRoutes();

        foreach ([
            '/admin/members/grant',
            '/admin/members/revoke',
            '/admin/members/pause',
            '/admin/members/resume',
            '/admin/members/extend',
            '/admin/members/bulk-grant',
            '/admin/members/bulk-revoke',
            '/admin/members/bulk-extend',
        ] as $path) {
            $route = $GLOBALS['_fchub_test_routes']['fchub-memberships/v1' . $path];

            self::assertArrayHasKey('args', $route, $path);
            self::assertIsArray($route['args']);
        }
    }

    public function test_single_mutation_schemas_declare_types_bounds_and_callbacks(): void
    {
        self::assertTrue(class_exists(MembershipRestArguments::class));

        $grant = MembershipRestArguments::grant();
        $revoke = MembershipRestArguments::revoke();
        $pause = MembershipRestArguments::pause();
        $resume = MembershipRestArguments::resume();
        $extend = MembershipRestArguments::extend();

        $this->assertPositiveIdArgument($grant['user_id']);
        $this->assertPositiveIdArgument($grant['plan_id']);
        self::assertFalse($grant['expires_at']['required']);
        self::assertSame(['string', 'null'], $grant['expires_at']['type']);
        self::assertSame(
            [MembershipRestArguments::class, 'sanitizeIsoMysqlDate'],
            $grant['expires_at']['sanitize_callback']
        );
        self::assertSame([MembershipRestArguments::class, 'isoMysqlDate'], $grant['expires_at']['validate_callback']);

        $this->assertPositiveIdArgument($revoke['user_id']);
        $this->assertPositiveIdArgument($revoke['plan_id']);
        $this->assertReasonArgument($revoke['reason']);

        $this->assertPositiveIdArgument($pause['grant_id']);
        $this->assertReasonArgument($pause['reason']);
        $this->assertPositiveIdArgument($resume['grant_id']);

        $this->assertPositiveIdArgument($extend['user_id']);
        $this->assertPositiveIdArgument($extend['plan_id']);
        self::assertTrue($extend['expires_at']['required']);
        self::assertSame('string', $extend['expires_at']['type']);
        self::assertSame(
            [MembershipRestArguments::class, 'sanitizeIsoMysqlDate'],
            $extend['expires_at']['sanitize_callback']
        );
        self::assertSame([MembershipRestArguments::class, 'isoMysqlDate'], $extend['expires_at']['validate_callback']);
    }

    public function test_bulk_schemas_are_unique_bounded_and_positive(): void
    {
        self::assertTrue(class_exists(MembershipRestArguments::class));

        $bulkGrant = MembershipRestArguments::bulkGrant();
        $bulkRevoke = MembershipRestArguments::bulkRevoke();
        $bulkExtend = MembershipRestArguments::bulkExtend();

        foreach ([$bulkGrant, $bulkRevoke, $bulkExtend] as $schema) {
            $userIds = $schema['user_ids'];

            self::assertTrue($userIds['required']);
            self::assertSame('array', $userIds['type']);
            self::assertSame(1, $userIds['minItems']);
            self::assertSame(100, $userIds['maxItems']);
            self::assertTrue($userIds['uniqueItems']);
            self::assertSame(['type' => 'integer', 'minimum' => 1], $userIds['items']);
            self::assertSame([MembershipRestArguments::class, 'sanitizeUserIds'], $userIds['sanitize_callback']);
            self::assertSame([MembershipRestArguments::class, 'userIds'], $userIds['validate_callback']);
            $this->assertPositiveIdArgument($schema['plan_id']);
        }

        self::assertFalse($bulkGrant['expires_at']['required']);
        self::assertSame(['string', 'null'], $bulkGrant['expires_at']['type']);
        $this->assertReasonArgument($bulkRevoke['reason']);
        self::assertTrue($bulkExtend['expires_at']['required']);
        self::assertSame('string', $bulkExtend['expires_at']['type']);
    }

    public function test_route_args_use_the_reusable_schema_methods(): void
    {
        self::assertTrue(class_exists(MembershipRestArguments::class));
        MemberController::registerRoutes();

        $routes = [
            '/admin/members/grant' => MembershipRestArguments::grant(),
            '/admin/members/revoke' => MembershipRestArguments::revoke(),
            '/admin/members/pause' => MembershipRestArguments::pause(),
            '/admin/members/resume' => MembershipRestArguments::resume(),
            '/admin/members/extend' => MembershipRestArguments::extend(),
            '/admin/members/bulk-grant' => MembershipRestArguments::bulkGrant(),
            '/admin/members/bulk-revoke' => MembershipRestArguments::bulkRevoke(),
            '/admin/members/bulk-extend' => MembershipRestArguments::bulkExtend(),
        ];

        foreach ($routes as $path => $expected) {
            self::assertSame(
                $expected,
                $GLOBALS['_fchub_test_routes']['fchub-memberships/v1' . $path]['args'],
                $path
            );
        }
    }

    public function test_positive_id_rejects_non_integer_zero_and_negative_values(): void
    {
        self::assertTrue(class_exists(MembershipRestArguments::class));

        self::assertTrue(MembershipRestArguments::positiveId(1));
        self::assertTrue(MembershipRestArguments::positiveId(42));
        self::assertTrue(MembershipRestArguments::positiveId('42'));
        self::assertTrue(MembershipRestArguments::positiveId(42.0));
        self::assertFalse(MembershipRestArguments::positiveId(0));
        self::assertFalse(MembershipRestArguments::positiveId(-1));
        self::assertFalse(MembershipRestArguments::positiveId(1.5));
        self::assertFalse(MembershipRestArguments::positiveId('1.5'));
        self::assertFalse(MembershipRestArguments::positiveId('not-an-id'));
        self::assertFalse(MembershipRestArguments::positiveId(null));
    }

    public function test_nullable_expiry_remains_null_after_validation_and_sanitisation(): void
    {
        $argument = MembershipRestArguments::grant()['expires_at'];

        self::assertSame(
            [MembershipRestArguments::class, 'sanitizeIsoMysqlDate'],
            $argument['sanitize_callback']
        );
        self::assertTrue(($argument['validate_callback'])(null));
        self::assertNull(($argument['sanitize_callback'])(null));
    }

    public function test_iso_mysql_date_rejects_malformed_and_impossible_dates(): void
    {
        self::assertTrue(class_exists(MembershipRestArguments::class));

        self::assertTrue(MembershipRestArguments::isoMysqlDate(null));
        self::assertTrue(MembershipRestArguments::isoMysqlDate('2028-02-29 23:59:59'));
        self::assertFalse(MembershipRestArguments::isoMysqlDate(''));
        self::assertFalse(MembershipRestArguments::isoMysqlDate('2026-02-29 12:00:00'));
        self::assertFalse(MembershipRestArguments::isoMysqlDate('2026-01-01'));
        self::assertFalse(MembershipRestArguments::isoMysqlDate('2026-01-01T12:00:00Z'));
        self::assertFalse(MembershipRestArguments::isoMysqlDate(123));
    }

    public function test_user_ids_rejects_empty_duplicate_invalid_and_oversized_lists(): void
    {
        self::assertTrue(class_exists(MembershipRestArguments::class));

        self::assertTrue(MembershipRestArguments::userIds([1, '2', 100.0]));
        self::assertFalse(MembershipRestArguments::userIds([]));
        self::assertFalse(MembershipRestArguments::userIds([1, 1]));
        self::assertFalse(MembershipRestArguments::userIds([0, 1]));
        self::assertFalse(MembershipRestArguments::userIds([-1, 1]));
        self::assertFalse(MembershipRestArguments::userIds([1, 2 => 3]));
        self::assertFalse(MembershipRestArguments::userIds(['first' => 1, 'second' => 2]));
        self::assertFalse(MembershipRestArguments::userIds((object) ['first' => 1]));
        self::assertFalse(MembershipRestArguments::userIds(range(1, 101)));
        self::assertFalse(MembershipRestArguments::userIds('1,2'));
    }

    public function test_user_ids_validation_then_sanitisation_returns_an_integer_list(): void
    {
        $argument = MembershipRestArguments::bulkGrant()['user_ids'];
        $payload = ['1', 2.0, 3];

        self::assertTrue(($argument['validate_callback'])($payload));

        $sanitised = ($argument['sanitize_callback'])($payload);

        self::assertSame([1, 2, 3], $sanitised);
        self::assertTrue(array_is_list($sanitised));
        self::assertSame([], ($argument['sanitize_callback'])(['first' => 1]));
    }

    public function test_positive_id_validation_then_sanitisation_matches_wordpress_integer_schema(): void
    {
        $argument = MembershipRestArguments::grant()['user_id'];

        self::assertTrue(($argument['validate_callback'])('12'));
        self::assertSame(12, ($argument['sanitize_callback'])('12'));
    }

    private function assertPositiveIdArgument(array $argument): void
    {
        self::assertTrue($argument['required']);
        self::assertSame('integer', $argument['type']);
        self::assertSame(1, $argument['minimum']);
        self::assertSame('absint', $argument['sanitize_callback']);
        self::assertSame([MembershipRestArguments::class, 'positiveId'], $argument['validate_callback']);
    }

    private function assertReasonArgument(array $argument): void
    {
        self::assertFalse($argument['required']);
        self::assertSame('string', $argument['type']);
        self::assertSame(500, $argument['maxLength']);
        self::assertSame('sanitize_text_field', $argument['sanitize_callback']);
        self::assertSame('rest_validate_request_arg', $argument['validate_callback']);
    }
}
