<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Adapters;

use FChubMemberships\Adapters\FluentCommunityAdapter;
use FChubMemberships\Adapters\FluentCrmAdapter;
use FChubMemberships\Adapters\LearnDashAdapter;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class InactiveProviderAdaptersTest extends PluginTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_inactive_external_providers_fail_grants_and_revocations(): void
    {
        $adapters = [
            [new FluentCommunityAdapter(), 'fc_space'],
            [new FluentCrmAdapter(), 'fluentcrm_tag'],
            [new LearnDashAdapter(), 'ld_course'],
        ];

        foreach ($adapters as [$adapter, $resourceType]) {
            self::assertFalse($adapter->grant(17, $resourceType, '41')['success']);
            self::assertFalse($adapter->revoke(17, $resourceType, '41')['success']);
        }
    }
}
