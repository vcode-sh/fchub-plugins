<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration\Community;

use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class CommunityCapabilityRegistryTest extends PluginTestCase
{
    public function test_active_core_and_inactive_pro_are_reported_without_pro_runtime_probes(): void
    {
        $proProbeCalls = 0;
        $registry = $this->registry(
            [
                'core_active' => true,
                'core_version' => '2.7.0',
                'pro_active' => false,
                'pro_version' => '2.7.0',
                'pro_certified' => false,
            ],
            ['course_module' => true],
            static function (string $capability) use (&$proProbeCalls): bool {
                if (in_array($capability, ['badges', 'points', 'leaderboard_levels'], true)) {
                    $proProbeCalls++;
                }

                return true;
            }
        );

        $capabilities = $registry->capabilities();

        self::assertSame('available', $capabilities['spaces']['status']);
        self::assertSame('available', $capabilities['courses']['status']);
        self::assertSame('available', $capabilities['profile_verification_read']['status']);
        foreach (['badges', 'points', 'leaderboard_levels'] as $capability) {
            self::assertSame('inactive', $capabilities[$capability]['status']);
            self::assertSame('2.7.0', $capabilities[$capability]['version']);
            self::assertFalse($registry->supports($capability));
        }
        self::assertTrue($registry->supports('spaces'));
        self::assertSame(0, $proProbeCalls, 'Inactive Pro classes must never be probed or autoloaded.');
    }

    public function test_missing_disabled_incompatible_and_unverified_states_are_explicit(): void
    {
        $missing = $this->registry([
            'core_active' => false,
            'core_version' => null,
            'pro_active' => false,
            'pro_version' => null,
            'pro_certified' => false,
        ]);
        self::assertSame('inactive', $missing->capabilities()['spaces']['status']);
        self::assertSame('community_core_inactive', $missing->capabilities()['spaces']['reason']);

        $disabled = $this->registry(
            $this->activeEnvironment(),
            ['course_module' => false],
            static fn(string $capability): bool => true
        );
        self::assertSame('disabled', $disabled->capabilities()['courses']['status']);
        self::assertSame('course_module_disabled', $disabled->capabilities()['courses']['reason']);

        $incompatible = $this->registry(
            $this->activeEnvironment(),
            ['course_module' => true],
            static fn(string $capability): bool => $capability !== 'spaces'
        );
        self::assertSame('incompatible', $incompatible->capabilities()['spaces']['status']);
        self::assertSame('required_callable_missing', $incompatible->capabilities()['spaces']['reason']);

        $unverified = $this->registry(
            array_replace($this->activeEnvironment(), ['pro_active' => true]),
            ['course_module' => true, 'user_badge' => true, 'leader_board_module' => true],
            static fn(string $capability): bool => true
        );
        self::assertSame('unverified', $unverified->capabilities()['badges']['status']);
        self::assertSame('community_pro_not_certified', $unverified->capabilities()['badges']['reason']);
        self::assertFalse($unverified->supports('badges'));
    }

    public function test_certified_capability_is_supported_only_when_feature_and_contract_are_available(): void
    {
        $registry = $this->registry(
            array_replace($this->activeEnvironment(), [
                'pro_active' => true,
                'pro_certified' => true,
            ]),
            ['course_module' => true, 'user_badge' => true, 'leader_board_module' => true],
            static fn(string $capability): bool => $capability !== 'leaderboard_levels'
        );

        self::assertTrue($registry->supports('badges'));
        self::assertTrue($registry->supports('points'));
        self::assertFalse($registry->supports('leaderboard_levels'));
        self::assertSame('incompatible', $registry->capabilities()['leaderboard_levels']['status']);
        self::assertFalse($registry->supports('missing'));
    }

    public function test_exact_2_7_runtime_is_certified_when_the_environment_does_not_override_it(): void
    {
        $registry = $this->registry(
            [
                'core_active' => true,
                'core_version' => '2.7.0',
                'pro_active' => true,
                'pro_version' => '2.7.0',
            ],
            ['course_module' => true, 'user_badge' => true, 'leader_board_module' => true],
            static fn(string $capability): bool => true
        );

        self::assertTrue($registry->supports('badges'));
        self::assertTrue($registry->supports('points'));
        self::assertTrue($registry->supports('leaderboard_levels'));
    }

    public function test_non_exact_pro_versions_remain_unverified_without_an_explicit_override(): void
    {
        $registry = $this->registry(
            [
                'core_active' => true,
                'core_version' => '2.7.0',
                'pro_active' => true,
                'pro_version' => '2.7.1',
            ],
            ['course_module' => true, 'user_badge' => true, 'leader_board_module' => true],
            static fn(string $capability): bool => true
        );

        self::assertSame('unverified', $registry->capabilities()['badges']['status']);
        self::assertFalse($registry->supports('badges'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_spaces_are_incompatible_without_the_member_context_space_id_callable(): void
    {
        class_alias(
            CapabilityHelperWithoutSpaceIds::class,
            'FluentCommunity\\App\\Services\\Helper'
        );
        $environment = $this->activeEnvironment();
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => $environment,
            static fn(string $feature): bool => true
        );

        self::assertSame('incompatible', $registry->capabilities()['spaces']['status']);
        self::assertFalse($registry->supports('spaces'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_courses_are_incompatible_without_the_member_context_space_id_callable(): void
    {
        class_alias(
            CapabilityHelperWithoutSpaceIds::class,
            'FluentCommunity\\App\\Services\\Helper'
        );
        class_alias(
            CapabilityCourseHelper::class,
            'FluentCommunity\\Modules\\Course\\Services\\CourseHelper'
        );
        class_alias(
            CapabilityCourseModelWithQuery::class,
            'FluentCommunity\\Modules\\Course\\Model\\Course'
        );
        $environment = $this->activeEnvironment();
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => $environment,
            static fn(string $feature): bool => true
        );

        self::assertSame('incompatible', $registry->capabilities()['courses']['status']);
        self::assertFalse($registry->supports('courses'));
        self::assertSame(0, CapabilityCourseModelWithQuery::$queryCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_courses_are_incompatible_without_the_enrolment_callable(): void
    {
        class_alias(CapabilityHelper::class, 'FluentCommunity\\App\\Services\\Helper');
        class_alias(
            CapabilityCourseHelperWithoutEnrolments::class,
            'FluentCommunity\\Modules\\Course\\Services\\CourseHelper'
        );
        class_alias(
            CapabilityCourseModelWithQuery::class,
            'FluentCommunity\\Modules\\Course\\Model\\Course'
        );
        $environment = $this->activeEnvironment();
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => $environment,
            static fn(string $feature): bool => true
        );

        self::assertSame('incompatible', $registry->capabilities()['courses']['status']);
        self::assertFalse($registry->supports('courses'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_courses_are_incompatible_without_the_progress_callable(): void
    {
        class_alias(CapabilityHelper::class, 'FluentCommunity\\App\\Services\\Helper');
        class_alias(
            CapabilityCourseHelperWithoutProgress::class,
            'FluentCommunity\\Modules\\Course\\Services\\CourseHelper'
        );
        class_alias(
            CapabilityCourseModelWithQuery::class,
            'FluentCommunity\\Modules\\Course\\Model\\Course'
        );
        $environment = $this->activeEnvironment();
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => $environment,
            static fn(string $feature): bool => true
        );

        self::assertSame('incompatible', $registry->capabilities()['courses']['status']);
        self::assertFalse($registry->supports('courses'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_courses_are_incompatible_without_the_batch_title_query_contract(): void
    {
        class_alias(CapabilityHelper::class, 'FluentCommunity\\App\\Services\\Helper');
        class_alias(
            CapabilityCourseHelper::class,
            'FluentCommunity\\Modules\\Course\\Services\\CourseHelper'
        );
        class_alias(
            CapabilityCourseModelWithoutQuery::class,
            'FluentCommunity\\Modules\\Course\\Model\\Course'
        );
        $environment = $this->activeEnvironment();
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => $environment,
            static fn(string $feature): bool => true
        );

        self::assertSame('incompatible', $registry->capabilities()['courses']['status']);
        self::assertFalse($registry->supports('courses'));
    }

    #[DataProvider('adapterContractDriftCases')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_core_access_capabilities_fail_closed_without_exact_adapter_callables(
        string $capability,
        string $helperClass,
        string $courseHelperClass,
        string $userClass
    ): void {
        class_alias($helperClass, 'FluentCommunity\\App\\Services\\Helper');
        class_alias($userClass, 'FluentCommunity\\App\\Models\\User');
        if ($courseHelperClass !== '') {
            class_alias(
                $courseHelperClass,
                'FluentCommunity\\Modules\\Course\\Services\\CourseHelper'
            );
            class_alias(
                CapabilityCourseModelWithQuery::class,
                'FluentCommunity\\Modules\\Course\\Model\\Course'
            );
        }
        $environment = $this->activeEnvironment();
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => $environment,
            static fn(string $feature): bool => true
        );

        self::assertSame('incompatible', $registry->capabilities()[$capability]['status']);
        self::assertFalse($registry->supports($capability));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_core_access_capabilities_accept_the_complete_adapter_contract(): void
    {
        class_alias(
            CapabilityAdapterHelper::class,
            'FluentCommunity\\App\\Services\\Helper'
        );
        class_alias(
            CapabilityUser::class,
            'FluentCommunity\\App\\Models\\User'
        );
        class_alias(
            CapabilityCourseHelperWithWrites::class,
            'FluentCommunity\\Modules\\Course\\Services\\CourseHelper'
        );
        class_alias(
            CapabilityCourseModelWithQuery::class,
            'FluentCommunity\\Modules\\Course\\Model\\Course'
        );
        $environment = $this->activeEnvironment();
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => $environment,
            static fn(string $feature): bool => true
        );

        self::assertSame('available', $registry->capabilities()['spaces']['status']);
        self::assertSame('available', $registry->capabilities()['courses']['status']);
        self::assertTrue($registry->supports('spaces'));
        self::assertTrue($registry->supports('courses'));
        self::assertSame(0, CapabilityCourseModelWithQuery::$queryCalls);
    }

    public static function adapterContractDriftCases(): iterable
    {
        yield 'spaces missing add' => [
            'spaces',
            CapabilityHelperWithoutAdd::class,
            '',
            CapabilityUser::class,
        ];
        yield 'spaces missing remove' => [
            'spaces',
            CapabilityHelperWithoutRemove::class,
            '',
            CapabilityUser::class,
        ];
        yield 'spaces missing assignment check' => [
            'spaces',
            CapabilityHelperWithoutAssignmentCheck::class,
            '',
            CapabilityUser::class,
        ];
        yield 'spaces missing user lookup' => [
            'spaces',
            CapabilityAdapterHelper::class,
            '',
            CapabilityUserWithoutFind::class,
        ];
        yield 'spaces missing cache refresh' => [
            'spaces',
            CapabilityAdapterHelper::class,
            '',
            CapabilityUserWithoutCacheRefresh::class,
        ];
        yield 'courses missing enrol' => [
            'courses',
            CapabilityAdapterHelper::class,
            CapabilityCourseHelperWithoutEnroll::class,
            CapabilityUser::class,
        ];
        yield 'courses missing leave' => [
            'courses',
            CapabilityAdapterHelper::class,
            CapabilityCourseHelperWithoutLeave::class,
            CapabilityUser::class,
        ];
        yield 'courses missing assignment check' => [
            'courses',
            CapabilityHelperWithoutAssignmentCheck::class,
            CapabilityCourseHelperWithWrites::class,
            CapabilityUser::class,
        ];
        yield 'courses missing user lookup' => [
            'courses',
            CapabilityAdapterHelper::class,
            CapabilityCourseHelperWithWrites::class,
            CapabilityUserWithoutFind::class,
        ];
        yield 'courses missing cache refresh' => [
            'courses',
            CapabilityAdapterHelper::class,
            CapabilityCourseHelperWithWrites::class,
            CapabilityUserWithoutCacheRefresh::class,
        ];
    }

    private function registry(
        array $environment,
        array $features = [],
        ?callable $contracts = null
    ): CommunityCapabilityRegistry {
        return new CommunityCapabilityRegistry(
            static fn(): array => $environment,
            static fn(string $feature): bool => (bool) ($features[$feature] ?? false),
            $contracts ?? static fn(string $capability): bool => true
        );
    }

    private function activeEnvironment(): array
    {
        return [
            'core_active' => true,
            'core_version' => '2.7.0',
            'pro_active' => false,
            'pro_version' => '2.7.0',
            'pro_certified' => false,
        ];
    }
}

final class CapabilityHelperWithoutSpaceIds
{
    public static function getUserSpaces(): array
    {
        return [];
    }

    public static function isUserInSpace(): bool
    {
        return false;
    }

    public static function isFeatureEnabled(): bool
    {
        return true;
    }
}

final class CapabilityHelper
{
    public static function getUserSpaces(): array
    {
        return [];
    }

    public static function getUserSpaceIds(): array
    {
        return [];
    }

    public static function isUserInSpace(): bool
    {
        return false;
    }
}

final class CapabilityAdapterHelper
{
    public static function getUserSpaces(): array
    {
        return [];
    }

    public static function getUserSpaceIds(): array
    {
        return [];
    }

    public static function isUserInSpace(): bool
    {
        return false;
    }

    public static function addToSpace(): bool
    {
        return true;
    }

    public static function removeFromSpace(): bool
    {
        return true;
    }
}

final class CapabilityHelperWithoutAdd
{
    public static function getUserSpaces(): array
    {
        return [];
    }

    public static function getUserSpaceIds(): array
    {
        return [];
    }

    public static function isUserInSpace(): bool
    {
        return false;
    }

    public static function removeFromSpace(): bool
    {
        return true;
    }
}

final class CapabilityHelperWithoutRemove
{
    public static function getUserSpaces(): array
    {
        return [];
    }

    public static function getUserSpaceIds(): array
    {
        return [];
    }

    public static function isUserInSpace(): bool
    {
        return false;
    }

    public static function addToSpace(): bool
    {
        return true;
    }
}

final class CapabilityHelperWithoutAssignmentCheck
{
    public static function getUserSpaces(): array
    {
        return [];
    }

    public static function getUserSpaceIds(): array
    {
        return [];
    }

    public static function addToSpace(): bool
    {
        return true;
    }

    public static function removeFromSpace(): bool
    {
        return true;
    }
}

final class CapabilityUser
{
    public static function find(): ?self
    {
        return null;
    }

    public function cacheAccessSpaces(): void
    {
    }
}

final class CapabilityUserWithoutFind
{
    public function cacheAccessSpaces(): void
    {
    }
}

final class CapabilityUserWithoutCacheRefresh
{
    public static function find(): ?self
    {
        return null;
    }
}

final class CapabilityCourseHelper
{
    public static function getEnrolledCourseIds(): array
    {
        return [];
    }

    public static function getCourseProgress(): int
    {
        return 0;
    }
}

final class CapabilityCourseHelperWithWrites
{
    public static function getEnrolledCourseIds(): array
    {
        return [];
    }

    public static function getCourseProgress(): int
    {
        return 0;
    }

    public static function enrollCourse(): bool
    {
        return true;
    }

    public static function leaveCourse(): bool
    {
        return true;
    }
}

final class CapabilityCourseHelperWithoutEnroll
{
    public static function getEnrolledCourseIds(): array
    {
        return [];
    }

    public static function getCourseProgress(): int
    {
        return 0;
    }

    public static function leaveCourse(): bool
    {
        return true;
    }
}

final class CapabilityCourseHelperWithoutLeave
{
    public static function getEnrolledCourseIds(): array
    {
        return [];
    }

    public static function getCourseProgress(): int
    {
        return 0;
    }

    public static function enrollCourse(): bool
    {
        return true;
    }
}

final class CapabilityCourseHelperWithoutEnrolments
{
    public static function getCourseProgress(): int
    {
        return 0;
    }
}

final class CapabilityCourseHelperWithoutProgress
{
    public static function getEnrolledCourseIds(): array
    {
        return [];
    }
}

final class CapabilityCourseModelWithQuery
{
    public static int $queryCalls = 0;

    public static function query(): object
    {
        self::$queryCalls++;

        return new \stdClass();
    }
}

final class CapabilityCourseModelWithoutQuery
{
}
