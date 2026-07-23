<?php

declare(strict_types=1);

namespace {
    if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
        define('FLUENT_COMMUNITY_PLUGIN_VERSION', '2.7.0');
    }
}

namespace FluentCommunity\App\Models {
    final class User
    {
        /** @var array<int, self> */
        private static array $users = [];

        /** @var list<int> */
        public static array $profileSyncs = [];

        /** @var list<int> */
        public static array $accessSpaceCacheRefreshes = [];

        public function __construct(public int $ID)
        {
        }

        public static function reset(): void
        {
            self::$users = [];
            self::$profileSyncs = [];
            self::$accessSpaceCacheRefreshes = [];
        }

        public static function seed(int $userId): void
        {
            self::$users[$userId] = new self($userId);
        }

        public static function find(int $userId): ?self
        {
            return self::$users[$userId] ?? null;
        }

        public function syncXProfile(): void
        {
            self::$profileSyncs[] = $this->ID;
        }

        public function cacheAccessSpaces(): void
        {
            self::$accessSpaceCacheRefreshes[] = $this->ID;
        }
    }

    final class XProfile
    {
        /** @var array<int, self> */
        public static array $profiles = [];

        /** @var array<string, mixed> */
        public array $meta;

        public int $saveCount = 0;
        public bool $saveResult = true;

        /** @param array<string, mixed> $meta */
        public function __construct(array $meta = [])
        {
            $this->meta = $meta;
        }

        public static function reset(): void
        {
            self::$profiles = [];
        }

        /** @param array<string, mixed> $meta */
        public static function seed(int $userId, array $meta = []): void
        {
            self::$profiles[$userId] = new self($meta);
        }

        public static function where(string $column, int $userId): object
        {
            return new class ($userId) {
                public function __construct(private int $userId)
                {
                }

                public function first(): ?XProfile
                {
                    return XProfile::$profiles[$this->userId] ?? null;
                }
            };
        }

        public function save(): bool
        {
            $this->saveCount++;
            return $this->saveResult;
        }
    }
}

namespace FluentCommunity\App\Functions {
    final class Utility
    {
        /** @var array<string, mixed> */
        public static array $options = [];

        public static function getOption(string $key, mixed $default = null): mixed
        {
            return self::$options[$key] ?? $default;
        }
    }
}

namespace FluentCommunity\App\Services {
    use FluentCommunity\App\Models\User;

    final class Helper
    {
        /** @var array<int, array<int, bool>> */
        public static array $memberships = [];

        /** @var list<array<string, mixed>> */
        public static array $calls = [];

        public static bool $addResult = true;
        public static bool $removeResult = true;

        public static function reset(): void
        {
            self::$memberships = [];
            self::$calls = [];
            self::$addResult = true;
            self::$removeResult = true;
        }

        public static function addToSpace($space, $userId, $role = 'member', $by = 'self', $skipSync = false): bool
        {
            self::$calls[] = [
                'method' => 'addToSpace',
                'space' => (int) $space,
                'user_id' => (int) $userId,
                'role' => $role,
                'by' => $by,
                'skip_sync' => $skipSync,
            ];

            if (!$skipSync) {
                $user = User::find((int) $userId);
                if (!$user) {
                    return false;
                }

                $user->syncXProfile();
            }

            if (self::$addResult) {
                self::$memberships[(int) $userId][(int) $space] = true;
            }

            return self::$addResult;
        }

        public static function removeFromSpace($space, $userId, $by = 'self'): bool
        {
            self::$calls[] = [
                'method' => 'removeFromSpace',
                'space' => (int) $space,
                'user_id' => (int) $userId,
                'by' => $by,
            ];

            if (self::$removeResult) {
                unset(self::$memberships[(int) $userId][(int) $space]);
            }

            return self::$removeResult;
        }

        public static function isUserInSpace($userId, $spaceId): bool
        {
            return self::$memberships[(int) $userId][(int) $spaceId] ?? false;
        }
    }
}

namespace FluentCommunity\Modules\Course\Services {
    use FluentCommunity\App\Services\Helper;

    final class CourseHelper
    {
        /** @var list<array<string, mixed>> */
        public static array $calls = [];

        public static bool $enrollResult = true;
        public static bool $leaveResult = true;

        public static function reset(): void
        {
            self::$calls = [];
            self::$enrollResult = true;
            self::$leaveResult = true;
        }

        public static function enrollCourse($course, $userId = null, $by = 'self', $skipSync = false): bool
        {
            self::$calls[] = [
                'method' => 'enrollCourse',
                'course' => (int) $course,
                'user_id' => (int) $userId,
                'role' => 'student',
                'by' => $by,
                'skip_sync' => $skipSync,
            ];

            if (!self::$enrollResult) {
                return false;
            }

            return Helper::addToSpace($course, $userId, 'student', $by, $skipSync);
        }

        public static function leaveCourse($course, $userId = null, $by = 'self'): bool
        {
            self::$calls[] = [
                'method' => 'leaveCourse',
                'course' => (int) $course,
                'user_id' => (int) $userId,
                'by' => $by,
            ];

            if (!self::$leaveResult) {
                return false;
            }

            return Helper::removeFromSpace($course, $userId, $by);
        }
    }
}

namespace FChubMemberships\Tests\Unit\Adapters {
    use FChubMemberships\Adapters\FluentCommunityAdapter;
    use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use FluentCommunity\App\Models\User;
    use FluentCommunity\App\Models\XProfile;
    use FluentCommunity\App\Services\Helper;
    use FluentCommunity\Modules\Course\Services\CourseHelper;

    final class FluentCommunityAdapterContractTest extends PluginTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            Helper::reset();
            CourseHelper::reset();
            User::reset();
            XProfile::reset();
            \FluentCommunity\App\Functions\Utility::$options = [];
            User::seed(17);
        }

        public function test_space_grant_and_revoke_use_helper_with_member_role_and_are_idempotent(): void
        {
            $adapter = new FluentCommunityAdapter($this->coreCapabilities());

            self::assertTrue($adapter->grant(17, 'fc_space', '41')['success']);
            self::assertTrue($adapter->grant(17, 'fc_space', '41')['success']);
            self::assertSame([[
                'method' => 'addToSpace',
                'space' => 41,
                'user_id' => 17,
                'role' => 'member',
                'by' => 'by_admin',
                'skip_sync' => false,
            ]], Helper::$calls);
            self::assertSame([17], User::$profileSyncs);
            self::assertSame([17, 17], User::$accessSpaceCacheRefreshes);

            self::assertTrue($adapter->revoke(17, 'fc_space', '41')['success']);
            self::assertTrue($adapter->revoke(17, 'fc_space', '41')['success']);
            self::assertSame('removeFromSpace', Helper::$calls[1]['method']);
            self::assertCount(2, Helper::$calls);
        }

        public function test_course_grant_and_revoke_use_course_helper_with_student_role_and_are_idempotent(): void
        {
            $adapter = new FluentCommunityAdapter($this->coreCapabilities());

            self::assertTrue($adapter->grant(17, 'fc_course', '73')['success']);
            self::assertTrue($adapter->grant(17, 'fc_course', '73')['success']);
            self::assertSame([[
                'method' => 'enrollCourse',
                'course' => 73,
                'user_id' => 17,
                'role' => 'student',
                'by' => 'by_admin',
                'skip_sync' => false,
            ]], CourseHelper::$calls);
            self::assertSame([17], User::$profileSyncs);
            self::assertSame([17, 17], User::$accessSpaceCacheRefreshes);

            self::assertTrue($adapter->revoke(17, 'fc_course', '73')['success']);
            self::assertTrue($adapter->revoke(17, 'fc_course', '73')['success']);
            self::assertSame('leaveCourse', CourseHelper::$calls[1]['method']);
            self::assertCount(2, CourseHelper::$calls);
        }

        public function test_group_resources_are_rejected(): void
        {
            $adapter = new FluentCommunityAdapter($this->coreCapabilities());

            self::assertFalse($adapter->supports('fc_group'));
            self::assertFalse($adapter->grant(17, 'fc_group', '9')['success']);
            self::assertFalse($adapter->revoke(17, 'fc_group', '9')['success']);
            self::assertSame([], Helper::$calls);
            self::assertSame([], CourseHelper::$calls);
        }

        public function test_successful_grants_refresh_the_native_access_space_cache(): void
        {
            $adapter = new FluentCommunityAdapter($this->coreCapabilities());

            self::assertTrue($adapter->grant(17, 'fc_space', '41')['success']);
            self::assertSame([17], User::$accessSpaceCacheRefreshes);

            self::assertTrue($adapter->grant(17, 'fc_course', '73')['success']);
            self::assertSame([17, 17], User::$accessSpaceCacheRefreshes);
        }

        public function test_unchanged_native_state_is_reported_as_provider_failure(): void
        {
            $adapter = new FluentCommunityAdapter($this->coreCapabilities());

            Helper::$addResult = false;
            CourseHelper::$enrollResult = false;

            self::assertFalse($adapter->grant(17, 'fc_space', '41')['success']);
            self::assertFalse($adapter->grant(17, 'fc_course', '73')['success']);
            self::assertSame([], User::$accessSpaceCacheRefreshes);

            Helper::$memberships[17][41] = true;
            Helper::$memberships[17][73] = true;
            Helper::$removeResult = false;
            CourseHelper::$leaveResult = false;

            self::assertFalse($adapter->revoke(17, 'fc_space', '41')['success']);
            self::assertFalse($adapter->revoke(17, 'fc_course', '73')['success']);
        }

        public function test_supported_community_contract_uses_helper_services(): void
        {
            self::assertFalse(method_exists('FluentCommunity\\App\\Models\\Space', 'addMember'));
            self::assertFalse(method_exists('FluentCommunity\\App\\Models\\Space', 'removeMember'));
            self::assertTrue(method_exists(Helper::class, 'addToSpace'));
            self::assertTrue(method_exists(Helper::class, 'removeFromSpace'));
            self::assertTrue(method_exists(CourseHelper::class, 'enrollCourse'));
            self::assertTrue(method_exists(CourseHelper::class, 'leaveCourse'));
        }

        public function test_supports_only_resources_with_available_capabilities(): void
        {
            $adapter = new FluentCommunityAdapter(new CommunityCapabilityRegistry(
                static fn(): array => [
                    'core_active' => true,
                    'core_version' => '2.7.0',
                    'pro_active' => true,
                    'pro_version' => '2.7.0',
                    'pro_certified' => true,
                ],
                static fn(string $feature): bool => $feature === 'user_badge',
                static fn(string $capability): bool => in_array($capability, ['spaces', 'badges'], true)
            ));

            self::assertTrue($adapter->supports('fc_space'));
            self::assertFalse($adapter->supports('fc_course'));
            self::assertTrue($adapter->supports('fc_badge'));
            self::assertSame([
                'fc_space' => 'Community Space',
                'fc_badge' => 'Community Badge',
            ], $adapter->getResourceTypes());
        }

        public function test_certified_pro_badges_use_exact_installed_slugs_and_xprofile_state(): void
        {
            \FluentCommunity\App\Functions\Utility::$options = [
                'user_badges' => [
                    'founding-member' => ['title' => 'Founding Member'],
                ],
            ];
            XProfile::seed(17, ['badge_slug' => []]);
            $adapter = new FluentCommunityAdapter($this->certifiedCapabilities());

            self::assertTrue($adapter->supports('fc_badge'));
            self::assertTrue($adapter->grant(17, 'fc_badge', 'founding-member')['success']);
            self::assertTrue($adapter->check(17, 'fc_badge', 'founding-member'));
            self::assertTrue($adapter->grant(17, 'fc_badge', 'founding-member')['success']);
            self::assertSame(['founding-member'], XProfile::where('user_id', 17)->first()->meta['badge_slug']);
            self::assertSame(1, XProfile::where('user_id', 17)->first()->saveCount);

            self::assertTrue($adapter->revoke(17, 'fc_badge', 'founding-member')['success']);
            self::assertFalse($adapter->check(17, 'fc_badge', 'founding-member'));
            self::assertFalse($adapter->grant(17, 'fc_badge', 'legacy-numeric-id')['success']);
        }

        public function test_badge_mutations_share_one_site_user_lock_across_slugs_and_preserve_other_badges(): void
        {
            \FluentCommunity\App\Functions\Utility::$options = [
                'user_badges' => [
                    'alpha-badge' => ['title' => 'Alpha Badge'],
                    'beta-badge' => ['title' => 'Beta Badge'],
                ],
            ];
            XProfile::seed(17, ['badge_slug' => ['manual-badge']]);
            $adapter = new FluentCommunityAdapter($this->certifiedCapabilities());

            self::assertTrue($adapter->grant(17, 'fc_badge', 'alpha-badge')['success']);
            self::assertTrue($adapter->grant(17, 'fc_badge', 'beta-badge')['success']);
            self::assertTrue($adapter->revoke(17, 'fc_badge', 'alpha-badge')['success']);
            self::assertSame(
                ['manual-badge', 'beta-badge'],
                XProfile::where('user_id', 17)->first()->meta['badge_slug']
            );

            $queries = $this->badgeLockQueries();
            self::assertCount(6, $queries);
            self::assertSame(
                ['GET_LOCK', 'RELEASE_LOCK', 'GET_LOCK', 'RELEASE_LOCK', 'GET_LOCK', 'RELEASE_LOCK'],
                array_map(
                    static fn(string $query): string => str_contains($query, 'GET_LOCK')
                        ? 'GET_LOCK'
                        : 'RELEASE_LOCK',
                    $queries
                )
            );

            $lockNames = [];
            foreach ($queries as $query) {
                self::assertMatchesRegularExpression(
                    "/(?:GET_LOCK|RELEASE_LOCK)\\('([^']{1,64})'(?:, 10)?\\)/",
                    $query
                );
                preg_match("/(?:GET_LOCK|RELEASE_LOCK)\\('([^']+)'/", $query, $matches);
                $lockNames[] = $matches[1];
                self::assertStringNotContainsString('alpha-badge', $query);
                self::assertStringNotContainsString('beta-badge', $query);
                self::assertStringNotContainsString('manual-badge', $query);
            }
            self::assertCount(1, array_unique($lockNames));
        }

        public function test_badge_mutation_fails_closed_when_the_site_user_lock_is_unavailable(): void
        {
            \FluentCommunity\App\Functions\Utility::$options = [
                'user_badges' => [
                    'alpha-badge' => ['title' => 'Alpha Badge'],
                ],
            ];
            XProfile::seed(17, ['badge_slug' => ['manual-badge']]);
            $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int =>
                str_contains($query, 'GET_LOCK(') ? 0 : 1;

            $result = (new FluentCommunityAdapter($this->certifiedCapabilities()))
                ->grant(17, 'fc_badge', 'alpha-badge');

            self::assertFalse($result['success']);
            self::assertSame(
                ['manual-badge'],
                XProfile::where('user_id', 17)->first()->meta['badge_slug']
            );
            self::assertSame(0, XProfile::where('user_id', 17)->first()->saveCount);
            self::assertCount(1, $this->badgeLockQueries());
            self::assertStringContainsString('GET_LOCK(', $this->badgeLockQueries()[0]);
        }

        public function test_badge_mutation_releases_the_site_user_lock_after_provider_save_failure(): void
        {
            \FluentCommunity\App\Functions\Utility::$options = [
                'user_badges' => [
                    'alpha-badge' => ['title' => 'Alpha Badge'],
                ],
            ];
            XProfile::seed(17, ['badge_slug' => ['manual-badge']]);
            XProfile::where('user_id', 17)->first()->saveResult = false;

            $result = (new FluentCommunityAdapter($this->certifiedCapabilities()))
                ->grant(17, 'fc_badge', 'alpha-badge');

            self::assertFalse($result['success']);
            self::assertSame(
                ['GET_LOCK', 'RELEASE_LOCK'],
                array_map(
                    static fn(string $query): string => str_contains($query, 'GET_LOCK')
                        ? 'GET_LOCK'
                        : 'RELEASE_LOCK',
                    $this->badgeLockQueries()
                )
            );
        }

        public function test_adapter_contains_no_executable_numeric_badge_contract(): void
        {
            $source = file_get_contents(dirname(__DIR__, 3) . '/app/Adapters/FluentCommunityAdapter.php');

            self::assertIsString($source);
            self::assertStringNotContainsString('FluentCommunity\\App\\Models\\Badge', $source);
            self::assertStringNotContainsString('assignToUser', $source);
            self::assertStringNotContainsString('removeFromUser', $source);
            self::assertStringNotContainsString('fc_badge_mappings', $source);
            self::assertStringNotContainsString('fc_remove_badge_on_revoke', $source);
        }

        private function certifiedCapabilities(): CommunityCapabilityRegistry
        {
            return new CommunityCapabilityRegistry(
                static fn(): array => [
                    'core_active' => true,
                    'core_version' => '2.7.0',
                    'pro_active' => true,
                    'pro_version' => '2.7.0',
                    'pro_certified' => true,
                ],
                static fn(string $feature): bool => in_array($feature, [
                    'course_module',
                    'user_badge',
                    'leader_board_module',
                ], true),
                static fn(string $capability): bool => true
            );
        }

        private function coreCapabilities(): CommunityCapabilityRegistry
        {
            return new CommunityCapabilityRegistry(
                static fn(): array => [
                    'core_active' => true,
                    'core_version' => '2.7.0',
                    'pro_active' => false,
                    'pro_version' => null,
                ],
                static fn(string $feature): bool => $feature === 'course_module',
                static fn(string $capability): bool => in_array($capability, ['spaces', 'courses'], true)
            );
        }

        /** @return list<string> */
        private function badgeLockQueries(): array
        {
            return array_values(array_map(
                static fn(array $entry): string => $entry[1],
                array_filter(
                    $GLOBALS['_fchub_test_queries'],
                    static fn(array $entry): bool => $entry[0] === 'get_var'
                        && (
                            str_contains($entry[1], 'GET_LOCK(')
                            || str_contains($entry[1], 'RELEASE_LOCK(')
                        )
                )
            ));
        }
    }
}
