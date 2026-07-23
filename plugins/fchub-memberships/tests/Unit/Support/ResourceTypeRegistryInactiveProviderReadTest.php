<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Adapters\FluentCommunityAdapter;
use FChubMemberships\Adapters\FluentCrmAdapter;
use FChubMemberships\Adapters\LearnDashAdapter;
use FChubMemberships\Support\Constants;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ResourceTypeRegistryInactiveProviderReadTest extends PluginTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_inactive_provider_types_remain_readable_without_becoming_valid_for_writes(): void
    {
        ResourceTypeRegistry::reset();
        $registry = ResourceTypeRegistry::getInstance();

        $expected = [
            'fluentcrm_tag' => ['FluentCRM Tag', Constants::PROVIDER_FLUENTCRM, FluentCrmAdapter::class],
            'fluentcrm_list' => ['FluentCRM List', Constants::PROVIDER_FLUENTCRM, FluentCrmAdapter::class],
            'fc_space' => ['Spaces', Constants::PROVIDER_FLUENT_COMMUNITY, FluentCommunityAdapter::class],
            'fc_course' => ['Courses', Constants::PROVIDER_FLUENT_COMMUNITY, FluentCommunityAdapter::class],
        ];

        foreach ($expected as $type => [$label, $provider, $adapter]) {
            self::assertFalse($registry->isValid($type));
            self::assertArrayNotHasKey($type, $registry->getAll());
            self::assertSame([
                'key' => $type,
                'label' => $label,
                'group' => 'content',
                'icon' => $type === 'fluentcrm_tag'
                    ? 'tag'
                    : ($type === 'fluentcrm_list' ? 'list-view' : ($type === 'fc_space' ? 'groups' : 'welcome-learn-more')),
                'searchable' => false,
                'supports_bulk' => false,
                'allow_all' => false,
                ...($provider === Constants::PROVIDER_FLUENT_COMMUNITY
                    ? ['identifier' => 'positive_int']
                    : []),
                'provider' => $provider,
                'adapter' => $adapter,
                'source' => $provider === Constants::PROVIDER_FLUENTCRM
                    ? 'FluentCRM (inactive, read-only)'
                    : 'FluentCommunity (inactive, read-only)',
                'read_only' => true,
            ], $registry->getForRead($type));
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_inactive_learndash_types_and_legacy_course_alias_keep_canonical_read_identity_only(): void
    {
        ResourceTypeRegistry::reset();
        $registry = ResourceTypeRegistry::getInstance();

        $expected = [
            'ld_course' => 'LearnDash Course',
            'ld_group' => 'LearnDash Group',
        ];

        foreach ($expected as $type => $label) {
            self::assertFalse($registry->isValid($type));
            self::assertArrayNotHasKey($type, $registry->getAll());
            self::assertSame([
                'key' => $type,
                'label' => $label,
                'group' => 'content',
                'icon' => 'welcome-learn-more',
                'searchable' => false,
                'supports_bulk' => false,
                'allow_all' => false,
                'provider' => Constants::PROVIDER_LEARNDASH,
                'adapter' => LearnDashAdapter::class,
                'source' => 'LearnDash (inactive, read-only)',
                'read_only' => true,
            ], $registry->getForRead($type));
        }

        self::assertFalse($registry->isValid('sfwd-courses'));
        self::assertArrayNotHasKey('sfwd-courses', $registry->getAll());
        self::assertSame('ld_course', $registry->resolveReadType('sfwd-courses'));
        self::assertSame($registry->getForRead('ld_course'), $registry->getForRead('sfwd-courses'));
    }
}
