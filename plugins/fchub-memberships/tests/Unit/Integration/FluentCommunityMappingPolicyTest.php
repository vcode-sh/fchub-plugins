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

    public function test_grant_path_always_reconciles_the_external_membership(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Integration/FluentCommunitySync.php');
        self::assertIsString($source);

        $grantStart = strpos($source, 'public function onGrantCreated');
        $revokeStart = strpos($source, 'public function onGrantRevoked');
        self::assertNotFalse($grantStart);
        self::assertNotFalse($revokeStart);

        $grantMethod = substr($source, $grantStart, $revokeStart - $grantStart);
        self::assertStringNotContainsString('isResourceStillGranted', $grantMethod);
    }

    public function test_revoke_path_checks_shared_space_before_removing_member(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Integration/FluentCommunitySync.php');
        self::assertIsString($source);

        $revokeStart = strpos($source, 'public function onGrantRevoked');
        $pauseStart = strpos($source, 'public function onGrantPaused');
        self::assertNotFalse($revokeStart);
        self::assertNotFalse($pauseStart);

        $revokeMethod = substr($source, $revokeStart, $pauseStart - $revokeStart);
        self::assertStringContainsString(
            '!$this->isResourceStillGranted($userId, (string) $spaceId, $spaceMappings)',
            $revokeMethod
        );
    }
}
