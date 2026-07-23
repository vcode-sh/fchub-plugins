<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\FluentCommunityMappingPolicy;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class FluentCommunityMappingPolicyTest extends PluginTestCase
{
    public function test_resource_is_preserved_when_another_active_plan_maps_to_it(): void
    {
        self::assertTrue(class_exists(FluentCommunityMappingPolicy::class));

        $policy = new FluentCommunityMappingPolicy();

        self::assertTrue($policy->isStillGranted(
            ['5' => '31', '8' => '31', '12' => '44'],
            '31',
            [8, 12]
        ));
    }

    public function test_resource_is_removed_when_no_other_active_plan_maps_to_it(): void
    {
        self::assertTrue(class_exists(FluentCommunityMappingPolicy::class));

        $policy = new FluentCommunityMappingPolicy();

        self::assertFalse($policy->isStillGranted(
            ['5' => '31', '8' => '31', '12' => '44'],
            '31',
            [12]
        ));
    }

    public function test_resource_is_preserved_when_same_plan_still_has_active_access(): void
    {
        $policy = new FluentCommunityMappingPolicy();

        self::assertTrue($policy->isStillGranted(
            ['5' => '31'],
            '31',
            [5]
        ));
    }

    public function test_legacy_sync_contains_no_provider_or_badge_mutation_contracts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Integration/FluentCommunitySync.php');
        self::assertIsString($source);

        self::assertStringNotContainsString('addMember', $source);
        self::assertStringNotContainsString('removeMember', $source);
        self::assertStringNotContainsString('Models\\Badge', $source);
        self::assertStringNotContainsString('assignToUser', $source);
        self::assertStringNotContainsString('removeFromUser', $source);
    }

    public function test_legacy_sync_contains_no_single_plan_user_meta_or_lifecycle_mutation_hooks(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Integration/FluentCommunitySync.php');
        self::assertIsString($source);

        self::assertStringNotContainsString('_fchub_membership_plan_id', $source);
        self::assertStringNotContainsString('_fchub_membership_status', $source);
        self::assertStringNotContainsString('update_user_meta', $source);
        self::assertStringNotContainsString("add_action('fchub_memberships/grant_", $source);
    }
}
