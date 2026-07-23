<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration\Community;

use FChubMemberships\Integration\Community\CommunityMemberContext;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class CommunityMemberContextTest extends PluginTestCase
{
    public function test_no_membership_returns_an_empty_member_safe_context_with_batched_reads(): void
    {
        $calls = [];
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities(),
            spacesForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['spaces', $userId];
                return [];
            },
            activeSpaceIdsForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['active_space_ids', $userId];
                return [];
            },
            enrolledCourseIdsForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['enrolled_course_ids', $userId];
                return [];
            },
            coursesForIds: static function () use (&$calls): never {
                $calls[] = ['courses'];
                throw new \LogicException('Course titles must not be read without an active enrolment.');
            },
            progressForCourse: static function () use (&$calls): never {
                $calls[] = ['progress'];
                throw new \LogicException('Progress must not be read without a course.');
            },
            profileForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['profile', $userId];
                return ['is_verified' => 0, 'display_name' => 'Private'];
            },
            edgesForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['edges', $userId];
                return [];
            },
            operationsForEdges: static function () use (&$calls): never {
                $calls[] = ['operations'];
                throw new \LogicException('Operations must not be read without an edge.');
            }
        );

        $result = $context->forUser(17);

        self::assertSame([
            'state' => 'empty',
            'profile' => ['is_verified' => false],
            'spaces' => [],
            'courses' => [],
            'pending_access_count' => 0,
            'capabilities' => [
                'spaces' => 'available',
                'courses' => 'available',
                'profile_verification_read' => 'available',
                'badges' => 'inactive',
                'points' => 'inactive',
                'leaderboard_levels' => 'inactive',
            ],
        ], $result);
        self::assertSame([
            ['spaces', 17],
            ['active_space_ids', 17],
            ['enrolled_course_ids', 17],
            ['profile', 17],
            ['edges', 17],
        ], $calls);
    }

    public function test_it_composes_spaces_courses_stacked_plans_and_latest_operations_without_leaking_provider_fields(): void
    {
        $calls = [];
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities(),
            spacesForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['spaces', $userId];
                return [
                    (object) [
                        'id' => 2,
                        'title' => 'Members Lounge',
                        'type' => 'community',
                        'settings' => ['private' => 'provider-only'],
                    ],
                ];
            },
            activeSpaceIdsForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['active_space_ids', $userId];
                return [2, 5];
            },
            enrolledCourseIdsForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['enrolled_course_ids', $userId];
                return [5];
            },
            coursesForIds: static function (array $courseIds) use (&$calls): array {
                $calls[] = ['courses', $courseIds];
                return [(object) [
                    'id' => 5,
                    'title' => 'Launch Course',
                    'settings' => ['private' => 'provider-only'],
                ]];
            },
            progressForCourse: static function (int $courseId, int $userId) use (&$calls): int {
                $calls[] = ['progress', $courseId, $userId];
                return 37;
            },
            profileForUser: static fn(): array => [
                'is_verified' => 1,
                'email' => 'private@example.test',
                'total_points' => 9000,
            ],
            edgesForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['edges', $userId];
                return [
                    self::edge(41, 'fc_space', '2', 5),
                    self::edge(42, 'fc_course', '5', 5),
                    self::edge(43, 'fc_course', '5', 8),
                ];
            },
            operationsForEdges: static function (array $edgeIds) use (&$calls): array {
                $calls[] = ['operations', $edgeIds];
                return [
                    41 => ['id' => 91, 'edge_id' => 41, 'state' => 'applied', 'desired_action' => 'grant'],
                    42 => ['id' => 94, 'edge_id' => 42, 'state' => 'pending', 'desired_action' => 'grant'],
                    43 => ['id' => 93, 'edge_id' => 43, 'state' => 'applied', 'desired_action' => 'grant'],
                ];
            }
        );

        $result = $context->forUser(17);

        self::assertSame('degraded', $result['state']);
        self::assertSame(['is_verified' => true], $result['profile']);
        self::assertSame([[
            'id' => 2,
            'title' => 'Members Lounge',
            'plan_ids' => [5],
            'ownership' => 'fchub',
            'operation_state' => 'healthy',
        ]], $result['spaces']);
        self::assertSame([[
            'id' => 5,
            'title' => 'Launch Course',
            'progress' => 37,
            'plan_ids' => [5, 8],
            'ownership' => 'fchub',
            'operation_state' => 'pending',
        ]], $result['courses']);
        self::assertSame(1, $result['pending_access_count']);
        self::assertSame([
            ['spaces', 17],
            ['active_space_ids', 17],
            ['enrolled_course_ids', 17],
            ['courses', [5]],
            ['progress', 5, 17],
            ['edges', 17],
            ['operations', [41, 42, 43]],
        ], $calls);
        self::assertStringNotContainsString('provider-only', json_encode($result));
        self::assertStringNotContainsString('private@example.test', json_encode($result));
        self::assertStringNotContainsString('9000', json_encode($result));
    }

    public function test_it_includes_only_certified_pro_profile_context_with_read_only_level_lookup(): void
    {
        $calls = [];
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities([
                'badges' => 'available',
                'points' => 'available',
                'leaderboard_levels' => 'available',
            ]),
            spacesForUser: static fn(): array => [],
            activeSpaceIdsForUser: static fn(): array => [],
            enrolledCourseIdsForUser: static fn(): array => [],
            coursesForIds: static fn(): never => throw new \LogicException('No courses are enrolled.'),
            progressForCourse: static fn(): never => throw new \LogicException('No courses are enrolled.'),
            profileForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['profile', $userId];

                return [
                    'is_verified' => 1,
                    'badge_slugs' => ['founder', 'member'],
                    'total_points' => 9001,
                ];
            },
            edgesForUser: static fn(): array => [],
            operationsForEdges: static fn(): array => [],
            badgesForSlugs: static function (array $slugs) use (&$calls): array {
                $calls[] = ['badges', $slugs];

                return [
                    ['slug' => 'founder', 'title' => 'Founder'],
                    ['slug' => 'member', 'title' => 'Member'],
                ];
            },
            levelForPoints: static function (int $points) use (&$calls): array {
                $calls[] = ['level', $points];

                return ['slug' => 'legend', 'title' => 'Legend'];
            }
        );

        $result = $context->forUser(17);

        self::assertSame([
            'is_verified' => true,
            'badges' => [
                ['slug' => 'founder', 'title' => 'Founder'],
                ['slug' => 'member', 'title' => 'Member'],
            ],
            'total_points' => 9001,
            'level' => ['slug' => 'legend', 'title' => 'Legend'],
        ], $result['profile']);
        self::assertSame([
            ['profile', 17],
            ['badges', ['founder', 'member']],
            ['level', 9001],
        ], $calls);
        self::assertStringNotContainsString(
            'getLeaderBoard',
            file_get_contents(FCHUB_MEMBERSHIPS_PATH . 'app/Integration/Community/CommunityMemberContext.php')
        );
    }

    public function test_inactive_pro_keeps_core_profile_healthy_without_pro_reads(): void
    {
        $proReads = 0;
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities(),
            spacesForUser: static fn(): array => [],
            activeSpaceIdsForUser: static fn(): array => [],
            enrolledCourseIdsForUser: static fn(): array => [],
            coursesForIds: static fn(): never => throw new \LogicException('No courses are enrolled.'),
            progressForCourse: static fn(): never => throw new \LogicException('No courses are enrolled.'),
            profileForUser: static fn(): array => ['is_verified' => 1],
            edgesForUser: static fn(): array => [],
            operationsForEdges: static fn(): array => [],
            badgesForSlugs: static function () use (&$proReads): never {
                $proReads++;
                throw new \LogicException('Inactive Pro must not be read.');
            },
            levelForPoints: static function () use (&$proReads): never {
                $proReads++;
                throw new \LogicException('Inactive Pro must not be read.');
            }
        );

        $result = $context->forUser(17);

        self::assertSame('empty', $result['state']);
        self::assertSame(['is_verified' => true], $result['profile']);
        self::assertSame(0, $proReads);
    }

    public function test_it_excludes_provider_rows_without_an_active_space_membership_in_one_batched_read(): void
    {
        $calls = [];
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities(),
            spacesForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['spaces', $userId];

                return [
                    (object) ['id' => 2, 'title' => 'Active Space', 'type' => 'community'],
                    (object) ['id' => 3, 'title' => 'Pending Space', 'type' => 'community'],
                    (object) ['id' => 5, 'title' => 'Active Course', 'type' => 'course'],
                ];
            },
            activeSpaceIdsForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['active_space_ids', $userId];

                return [2, 5];
            },
            enrolledCourseIdsForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['enrolled_course_ids', $userId];
                return [5];
            },
            coursesForIds: static function (array $courseIds) use (&$calls): array {
                $calls[] = ['courses', $courseIds];
                return [(object) ['id' => 5, 'title' => 'Active Course']];
            },
            progressForCourse: static fn(): int => 62,
            profileForUser: static fn(): array => ['is_verified' => 0],
            edgesForUser: static fn(): array => [],
            operationsForEdges: static fn(): array => []
        );

        $result = $context->forUser(17);

        self::assertSame([2], array_column($result['spaces'], 'id'));
        self::assertSame([5], array_column($result['courses'], 'id'));
        self::assertSame([
            ['spaces', 17],
            ['active_space_ids', 17],
            ['enrolled_course_ids', 17],
            ['courses', [5]],
        ], $calls);
    }

    public function test_it_reads_an_active_enrolled_course_that_is_absent_from_user_spaces(): void
    {
        $calls = [];
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities(),
            spacesForUser: static fn(): array => [],
            activeSpaceIdsForUser: static fn(): array => [5],
            enrolledCourseIdsForUser: static function (int $userId) use (&$calls): array {
                $calls[] = ['enrolled_course_ids', $userId];
                return [5];
            },
            coursesForIds: static function (array $courseIds) use (&$calls): array {
                $calls[] = ['courses', $courseIds];
                return [(object) ['id' => 5, 'title' => 'Provider Course']];
            },
            progressForCourse: static function (int $courseId, int $userId) use (&$calls): int {
                $calls[] = ['progress', $courseId, $userId];
                return 42;
            },
            profileForUser: static fn(): array => ['is_verified' => 0],
            edgesForUser: static fn(): array => [],
            operationsForEdges: static fn(): array => []
        );

        $result = $context->forUser(17);

        self::assertSame([[
            'id' => 5,
            'title' => 'Provider Course',
            'progress' => 42,
            'plan_ids' => [],
            'ownership' => 'unmanaged',
            'operation_state' => 'unmanaged',
        ]], $result['courses']);
        self::assertSame([
            ['enrolled_course_ids', 17],
            ['courses', [5]],
            ['progress', 5, 17],
        ], $calls);
    }

    public function test_it_omits_an_enrolled_course_whose_space_pivot_is_not_active(): void
    {
        $titleReads = [];
        $progressReads = [];
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities(),
            spacesForUser: static fn(): array => [],
            activeSpaceIdsForUser: static fn(): array => [5],
            enrolledCourseIdsForUser: static fn(): array => [5, 6],
            coursesForIds: static function (array $courseIds) use (&$titleReads): array {
                $titleReads[] = $courseIds;
                return [(object) ['id' => 5, 'title' => 'Active Course']];
            },
            progressForCourse: static function (int $courseId) use (&$progressReads): int {
                $progressReads[] = $courseId;
                return 17;
            },
            profileForUser: static fn(): array => ['is_verified' => 0],
            edgesForUser: static fn(): array => [],
            operationsForEdges: static fn(): array => []
        );

        $result = $context->forUser(17);

        self::assertSame([5], array_column($result['courses'], 'id'));
        self::assertSame([[5]], $titleReads);
        self::assertSame([5], $progressReads);
    }

    public function test_inaccessible_provider_skips_provider_reads_and_keeps_capability_truth(): void
    {
        $providerReads = 0;
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities([
                'spaces' => 'inactive',
                'courses' => 'inactive',
                'profile_verification_read' => 'incompatible',
            ]),
            spacesForUser: static function () use (&$providerReads): never {
                $providerReads++;
                throw new \LogicException('Inactive provider must not be queried.');
            },
            activeSpaceIdsForUser: static function () use (&$providerReads): never {
                $providerReads++;
                throw new \LogicException('Inactive provider must not be queried.');
            },
            enrolledCourseIdsForUser: static function () use (&$providerReads): never {
                $providerReads++;
                throw new \LogicException('Inactive provider must not be queried.');
            },
            coursesForIds: static function () use (&$providerReads): never {
                $providerReads++;
                throw new \LogicException('Inactive provider must not be queried.');
            },
            progressForCourse: static function () use (&$providerReads): never {
                $providerReads++;
                throw new \LogicException('Inactive provider must not be queried.');
            },
            profileForUser: static function () use (&$providerReads): never {
                $providerReads++;
                throw new \LogicException('Inactive provider must not be queried.');
            },
            edgesForUser: static fn(): array => [],
            operationsForEdges: static fn(): array => []
        );

        $result = $context->forUser(17);

        self::assertSame('inactive', $result['state']);
        self::assertSame(['is_verified' => null], $result['profile']);
        self::assertSame([], $result['spaces']);
        self::assertSame([], $result['courses']);
        self::assertSame('inactive', $result['capabilities']['spaces']);
        self::assertSame('incompatible', $result['capabilities']['profile_verification_read']);
        self::assertSame(0, $providerReads);
    }

    public function test_active_edge_without_provider_membership_is_reported_as_drift(): void
    {
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities(),
            spacesForUser: static fn(): array => [],
            activeSpaceIdsForUser: static fn(): array => [],
            enrolledCourseIdsForUser: static fn(): array => [],
            coursesForIds: static fn(): never => throw new \LogicException('No active enrolled course exists.'),
            progressForCourse: static fn(): never => throw new \LogicException('No enrolled course exists.'),
            profileForUser: static fn(): array => ['is_verified' => 0],
            edgesForUser: static fn(): array => [self::edge(41, 'fc_space', '2', 5)],
            operationsForEdges: static fn(array $edgeIds): array => []
        );

        $result = $context->forUser(17);

        self::assertSame('degraded', $result['state']);
        self::assertSame([[
            'id' => 2,
            'title' => 'Space #2',
            'plan_ids' => [5],
            'ownership' => 'fchub',
            'operation_state' => 'drift',
        ]], $result['spaces']);
        self::assertSame(1, $result['pending_access_count']);
    }

    public function test_provider_query_failure_degrades_without_exposing_error_details(): void
    {
        $context = new CommunityMemberContext(
            capabilities: $this->capabilities(),
            spacesForUser: static fn(): never => throw new \RuntimeException(
                'private provider SQL and member@example.test'
            ),
            activeSpaceIdsForUser: static fn(): never => throw new \LogicException(
                'Active IDs must not be read after the provider row query fails.'
            ),
            enrolledCourseIdsForUser: static fn(): never => throw new \LogicException(
                'Course IDs must not be read after the provider row query fails.'
            ),
            coursesForIds: static fn(): never => throw new \LogicException(
                'Course titles must not be read after the provider row query fails.'
            ),
            progressForCourse: static fn(): int => 0,
            profileForUser: static fn(): array => ['is_verified' => 1],
            edgesForUser: static fn(): array => [],
            operationsForEdges: static fn(): array => []
        );

        $result = $context->forUser(17);

        self::assertSame('degraded', $result['state']);
        self::assertSame(['is_verified' => null], $result['profile']);
        self::assertSame('available', $result['capabilities']['spaces']);
        self::assertStringNotContainsString('SQL', json_encode($result));
        self::assertStringNotContainsString('member@example.test', json_encode($result));
    }

    private static function edge(
        int $id,
        string $resourceType,
        string $resourceId,
        int $planId,
        array $overrides = []
    ): array {
        return array_merge([
            'id' => $id,
            'user_id' => 17,
            'provider' => 'fluent_community',
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'plan_id' => $planId,
            'owner' => 'fchub',
            'assignment_provenance' => 'fchub_created',
            'lifecycle' => 'active',
        ], $overrides);
    }

    private function capabilities(array $states = []): TestCommunityCapabilityRegistry
    {
        return new TestCommunityCapabilityRegistry(array_merge([
            'spaces' => 'available',
            'courses' => 'available',
            'profile_verification_read' => 'available',
            'badges' => 'inactive',
            'points' => 'inactive',
            'leaderboard_levels' => 'inactive',
        ], $states));
    }
}

final class TestCommunityCapabilityRegistry
{
    /** @param array<string, string> $states */
    public function __construct(private array $states)
    {
    }

    public function capabilities(): array
    {
        return array_map(
            static fn(string $state): array => [
                'status' => $state,
                'available' => $state === 'available',
                'reason' => 'private_provider_reason',
                'version' => '2.7.0',
            ],
            $this->states
        );
    }

    public function supports(string $capability): bool
    {
        return ($this->states[$capability] ?? '') === 'available';
    }
}
