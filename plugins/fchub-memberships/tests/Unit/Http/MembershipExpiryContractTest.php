<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http;

use FChubMemberships\Http\MembershipRestArguments;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * The server half of the expiry contract the admin forms have to satisfy.
 *
 * Every membership date picker emits `YYYY-MM-DD`; this validator has always
 * rejected that, so granting with an expiry, extending, and bulk-extending all
 * failed with "Invalid parameter: expires_at" the moment anyone picked a date.
 * `toExpiryTimestamp()` on the client is what closes the gap — see
 * tests/admin/membership-expiry-contract.test.js for the other half.
 */
final class MembershipExpiryContractTest extends PluginTestCase
{
    public function test_it_accepts_the_end_of_day_value_the_admin_forms_send(): void
    {
        self::assertTrue(MembershipRestArguments::isoMysqlDate('2027-01-01 23:59:59'));
    }

    public function test_it_rejects_the_bare_day_a_date_picker_emits(): void
    {
        self::assertFalse(
            MembershipRestArguments::isoMysqlDate('2027-01-01'),
            'A picker value must be converted before it reaches the API.'
        );
    }

    public function test_it_rejects_a_value_that_stops_at_minutes(): void
    {
        self::assertFalse(MembershipRestArguments::isoMysqlDate('2027-01-01 23:59'));
    }

    public function test_it_rejects_the_iso_separator(): void
    {
        self::assertFalse(MembershipRestArguments::isoMysqlDate('2027-01-01T23:59:59'));
    }

    public function test_it_rejects_a_date_that_does_not_exist(): void
    {
        self::assertFalse(MembershipRestArguments::isoMysqlDate('2027-02-30 00:00:00'));
        self::assertFalse(MembershipRestArguments::isoMysqlDate('2027-13-01 00:00:00'));
    }

    public function test_it_accepts_a_real_leap_day(): void
    {
        self::assertTrue(MembershipRestArguments::isoMysqlDate('2028-02-29 23:59:59'));
    }

    public function test_it_rejects_an_empty_string_but_allows_a_deliberate_null(): void
    {
        self::assertFalse(MembershipRestArguments::isoMysqlDate(''));
        self::assertTrue(MembershipRestArguments::isoMysqlDate(null));
    }

    public function test_it_rejects_values_that_are_not_strings(): void
    {
        self::assertFalse(MembershipRestArguments::isoMysqlDate(20270101));
        self::assertFalse(MembershipRestArguments::isoMysqlDate(['2027-01-01 00:00:00']));
        self::assertFalse(MembershipRestArguments::isoMysqlDate(true));
    }

    public function test_extend_requires_an_expiry_while_grant_may_omit_one(): void
    {
        self::assertTrue(MembershipRestArguments::extend()['expires_at']['required']);
        self::assertFalse(MembershipRestArguments::grant()['expires_at']['required']);
        self::assertSame(['string', 'null'], MembershipRestArguments::grant()['expires_at']['type']);
    }

    public function test_every_route_that_takes_an_expiry_validates_it_the_same_way(): void
    {
        foreach (['grant', 'extend', 'bulkGrant', 'bulkExtend'] as $route) {
            $argument = MembershipRestArguments::$route()['expires_at'];

            self::assertSame(
                [MembershipRestArguments::class, 'isoMysqlDate'],
                $argument['validate_callback'],
                "Route {$route} must validate expiry the same way."
            );
        }
    }
}
