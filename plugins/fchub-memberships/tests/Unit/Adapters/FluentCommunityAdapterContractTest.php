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
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use FluentCommunity\App\Models\User;
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
            User::seed(17);
        }

        public function test_space_grant_and_revoke_use_helper_with_member_role_and_are_idempotent(): void
        {
            $adapter = new FluentCommunityAdapter();

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
            $adapter = new FluentCommunityAdapter();

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
            $adapter = new FluentCommunityAdapter();

            self::assertFalse($adapter->supports('fc_group'));
            self::assertFalse($adapter->grant(17, 'fc_group', '9')['success']);
            self::assertFalse($adapter->revoke(17, 'fc_group', '9')['success']);
            self::assertSame([], Helper::$calls);
            self::assertSame([], CourseHelper::$calls);
        }

        public function test_successful_grants_refresh_the_native_access_space_cache(): void
        {
            $adapter = new FluentCommunityAdapter();

            self::assertTrue($adapter->grant(17, 'fc_space', '41')['success']);
            self::assertSame([17], User::$accessSpaceCacheRefreshes);

            self::assertTrue($adapter->grant(17, 'fc_course', '73')['success']);
            self::assertSame([17, 17], User::$accessSpaceCacheRefreshes);
        }

        public function test_unchanged_native_state_is_reported_as_provider_failure(): void
        {
            $adapter = new FluentCommunityAdapter();

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
    }
}
