<?php

declare(strict_types=1);

namespace FChubMemberships\Integration\Community;

use FluentCommunity\App\Models\XProfile;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCommunity\Modules\Course\Services\CourseHelper;
use FluentCommunityPro\App\Modules\LeaderBoard\Services\LeaderBoardHelper;
use FluentCommunity\App\Functions\Utility;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\ProviderOperationRepository;

defined('ABSPATH') || exit;

final class CommunityMemberContext
{
    private const CAPABILITIES = [
        'spaces',
        'courses',
        'profile_verification_read',
        'badges',
        'points',
        'leaderboard_levels',
    ];

    private \Closure $spacesForUser;
    private \Closure $activeSpaceIdsForUser;
    private \Closure $enrolledCourseIdsForUser;
    private \Closure $coursesForIds;
    private \Closure $progressForCourse;
    private \Closure $profileForUser;
    private \Closure $badgesForSlugs;
    private \Closure $levelForPoints;
    private \Closure $edgesForUser;
    private \Closure $operationsForEdges;

    public function __construct(
        private ?object $capabilities = null,
        ?callable $spacesForUser = null,
        ?callable $activeSpaceIdsForUser = null,
        ?callable $enrolledCourseIdsForUser = null,
        ?callable $coursesForIds = null,
        ?callable $progressForCourse = null,
        ?callable $profileForUser = null,
        ?callable $edgesForUser = null,
        ?callable $operationsForEdges = null,
        ?callable $badgesForSlugs = null,
        ?callable $levelForPoints = null
    ) {
        $this->capabilities ??= new CommunityCapabilityRegistry();
        $this->spacesForUser = \Closure::fromCallable(
            $spacesForUser ?? static fn(int $userId): mixed => Helper::getUserSpaces($userId)
        );
        $this->activeSpaceIdsForUser = \Closure::fromCallable(
            $activeSpaceIdsForUser ?? static fn(int $userId): array => Helper::getUserSpaceIds($userId)
        );
        $this->enrolledCourseIdsForUser = \Closure::fromCallable(
            $enrolledCourseIdsForUser ?? static fn(int $userId): array => CourseHelper::getEnrolledCourseIds($userId)
        );
        $this->coursesForIds = \Closure::fromCallable(
            $coursesForIds ?? static fn(array $courseIds): mixed => Course::query()
                ->whereIn('id', $courseIds)
                ->get()
        );
        $this->progressForCourse = \Closure::fromCallable(
            $progressForCourse ?? static fn(int $courseId, int $userId): int => (int) CourseHelper::getCourseProgress(
                $courseId,
                $userId
            )
        );
        $this->profileForUser = \Closure::fromCallable(
            $profileForUser ?? static function (int $userId): array {
                $profile = XProfile::where('user_id', $userId)->first();
                $meta = $profile->meta ?? [];
                if (is_string($meta)) {
                    $meta = json_decode($meta, true) ?: [];
                }

                return [
                    'is_verified' => (int) ($profile->is_verified ?? 0),
                    'badge_slugs' => is_array($meta) ? ($meta['badge_slug'] ?? []) : [],
                    'total_points' => (int) ($profile->total_points ?? 0),
                ];
            }
        );
        $this->badgesForSlugs = \Closure::fromCallable(
            $badgesForSlugs ?? static function (array $slugs): array {
                $installed = Utility::getOption('user_badges', []);
                if (!is_array($installed)) {
                    return [];
                }

                $badges = [];
                foreach ($slugs as $slug) {
                    $slug = (string) $slug;
                    $badge = $installed[$slug] ?? null;
                    if (!is_array($badge)) {
                        continue;
                    }
                    $badges[] = [
                        'slug' => $slug,
                        'title' => (string) ($badge['title'] ?? $slug),
                    ];
                }

                return $badges;
            }
        );
        $this->levelForPoints = \Closure::fromCallable(
            $levelForPoints ?? static fn(int $points): mixed => LeaderBoardHelper::getLevelByPoint($points)
        );
        $this->edgesForUser = \Closure::fromCallable(
            $edgesForUser ?? static fn(int $userId): array => (
                new EntitlementEdgeRepository()
            )->getActiveByUserProvider($userId, 'fluent_community')
        );
        $this->operationsForEdges = \Closure::fromCallable(
            $operationsForEdges ?? static fn(array $edgeIds): array => (
                new ProviderOperationRepository()
            )->findLatestForEdgeIds($edgeIds)
        );
    }

    /** @return array<string, mixed> */
    public function forUser(int $userId): array
    {
        $capabilities = array_fill_keys(self::CAPABILITIES, 'unverified');
        try {
            $capabilities = $this->safeCapabilities();
            $spacesAvailable = $this->capabilities->supports('spaces');
            $coursesAvailable = $this->capabilities->supports('courses');
            $profileAvailable = $this->capabilities->supports('profile_verification_read');
            $spaceRows = $spacesAvailable
                ? $this->normaliseRows(($this->spacesForUser)($userId))
                : [];
            $activeSpaceIds = ($spacesAvailable || $coursesAvailable)
                ? $this->normalisePositiveIds(($this->activeSpaceIdsForUser)($userId))
                : [];
            $enrolledCourseIds = $coursesAvailable
                ? $this->normalisePositiveIds(($this->enrolledCourseIdsForUser)($userId))
                : [];
            $activeCourseIds = array_values(array_intersect($enrolledCourseIds, $activeSpaceIds));
            $courseRows = $activeCourseIds === []
                ? []
                : $this->normaliseRows(($this->coursesForIds)($activeCourseIds));
            $profileRow = $profileAvailable ? ($this->profileForUser)($userId) : [];
            $profile = $this->profileContext(
                $profileRow,
                $profileAvailable,
                $this->capabilities->supports('badges'),
                $this->capabilities->supports('points'),
                $this->capabilities->supports('leaderboard_levels')
            );
            $providerResources = $this->providerResources(
                $spaceRows,
                $courseRows,
                $activeSpaceIds,
                $activeCourseIds,
                $userId,
                $spacesAvailable,
                $coursesAvailable
            );
            $edges = $this->normaliseRows(($this->edgesForUser)($userId));
            $edgeIds = array_values(array_filter(
                array_map(static fn(array $edge): int => (int) ($edge['id'] ?? 0), $edges),
                static fn(int $edgeId): bool => $edgeId > 0
            ));
            $operations = [];
            if ($edgeIds !== []) {
                $operations = ($this->operationsForEdges)($edgeIds);
            }
            $items = $this->composeItems($providerResources, $edges, is_array($operations) ? $operations : []);
            $pending = count(array_filter(
                array_merge($items['spaces'], $items['courses']),
                static fn(array $item): bool => in_array(
                    $item['operation_state'],
                    ['pending', 'deferred', 'processing', 'retrying', 'failed', 'drift'],
                    true
                )
            ));

            return [
                'state' => !$spacesAvailable && !$coursesAvailable && $pending === 0
                    ? 'inactive'
                    : (
                        $items['spaces'] === [] && $items['courses'] === []
                            ? 'empty'
                            : ($pending > 0 ? 'degraded' : 'available')
                    ),
                'profile' => [
                    ...$profile,
                ],
                'spaces' => $items['spaces'],
                'courses' => $items['courses'],
                'pending_access_count' => $pending,
                'capabilities' => $capabilities,
            ];
        } catch (\Throwable) {
            return $this->degradedContext($capabilities);
        }
    }

    /** @return array<string, string> */
    private function safeCapabilities(): array
    {
        $capabilities = $this->capabilities->capabilities();
        $safe = [];

        foreach (self::CAPABILITIES as $name) {
            $state = is_array($capabilities[$name] ?? null)
                ? (string) (
                    $capabilities[$name]['status']
                    ?? $capabilities[$name]['state']
                    ?? ''
                )
                : '';
            $safe[$name] = in_array(
                $state,
                ['inactive', 'disabled', 'incompatible', 'unverified', 'available'],
                true
            ) ? $state : 'unverified';
        }

        return $safe;
    }

    private function normaliseRows(mixed $rows): array
    {
        if (is_array($rows)) {
            return array_values($rows);
        }
        if ($rows instanceof \Traversable) {
            return array_values(iterator_to_array($rows));
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function profileContext(
        mixed $profile,
        bool $profileAvailable,
        bool $badgesAvailable,
        bool $pointsAvailable,
        bool $levelsAvailable
    ): array {
        if (!$profileAvailable) {
            return ['is_verified' => null];
        }

        $context = ['is_verified' => !empty($this->value($profile, 'is_verified'))];
        if ($badgesAvailable) {
            try {
                $slugs = $this->value($profile, 'badge_slugs');
                $context['badges'] = ($this->badgesForSlugs)(is_array($slugs) ? $slugs : []);
            } catch (\Throwable) {
                $context['badges'] = [];
            }
        }
        if (!$pointsAvailable) {
            return $context;
        }

        $points = max(0, (int) $this->value($profile, 'total_points'));
        $context['total_points'] = $points;
        if ($levelsAvailable) {
            try {
                $level = ($this->levelForPoints)($points);
                if (is_array($level)) {
                    $context['level'] = $level;
                }
            } catch (\Throwable) {
                // Community Pro levels are optional context, never core access.
            }
        }

        return $context;
    }

    /** @return list<int> */
    private function normalisePositiveIds(mixed $ids): array
    {
        if ($ids instanceof \Traversable) {
            $ids = iterator_to_array($ids);
        }
        if (!is_array($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return array<string, array<string, mixed>> */
    private function providerResources(
        array $spaceRows,
        array $courseRows,
        array $activeSpaceIds,
        array $activeCourseIds,
        int $userId,
        bool $spacesAvailable,
        bool $coursesAvailable
    ): array {
        $resources = [];

        foreach ($spaceRows as $row) {
            $id = (int) $this->value($row, 'id');
            $type = (string) $this->value($row, 'type');
            if ($id <= 0
                || $type !== 'community'
                || !in_array($id, $activeSpaceIds, true)
                || !$spacesAvailable
            ) {
                continue;
            }
            $resources['fc_space:' . $id] = [
                'id' => $id,
                'title' => trim((string) $this->value($row, 'title')),
                'resource_type' => 'fc_space',
                'progress' => null,
                'provider_present' => true,
            ];
        }

        if (!$coursesAvailable) {
            return $resources;
        }
        foreach ($courseRows as $row) {
            $id = (int) $this->value($row, 'id');
            if ($id <= 0 || !in_array($id, $activeCourseIds, true)) {
                continue;
            }
            $resources['fc_course:' . $id] = [
                'id' => $id,
                'title' => trim((string) $this->value($row, 'title')),
                'resource_type' => 'fc_course',
                'progress' => max(0, min(100, (int) ($this->progressForCourse)($id, $userId))),
                'provider_present' => true,
            ];
        }

        return $resources;
    }

    /** @return array{spaces: list<array<string, mixed>>, courses: list<array<string, mixed>>} */
    private function composeItems(array $providerResources, array $edges, array $operations): array
    {
        $edgesByResource = [];
        foreach ($edges as $edge) {
            $resourceType = (string) ($edge['resource_type'] ?? '');
            $resourceId = (int) ($edge['resource_id'] ?? 0);
            if (!in_array($resourceType, ['fc_space', 'fc_course'], true) || $resourceId <= 0) {
                continue;
            }
            $edgesByResource[$resourceType . ':' . $resourceId][] = $edge;
        }

        foreach ($edgesByResource as $key => $resourceEdges) {
            if (isset($providerResources[$key])) {
                continue;
            }
            [$resourceType, $resourceId] = explode(':', $key, 2);
            $providerResources[$key] = [
                'id' => (int) $resourceId,
                'title' => $resourceType === 'fc_course'
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    ? sprintf(__('Course #%d', 'fchub-memberships'), (int) $resourceId)
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    : sprintf(__('Space #%d', 'fchub-memberships'), (int) $resourceId),
                'resource_type' => $resourceType,
                'progress' => null,
                'provider_present' => false,
            ];
        }

        $spaces = [];
        $courses = [];
        foreach ($providerResources as $key => $resource) {
            $resourceEdges = $edgesByResource[$key] ?? [];
            $operation = $this->latestOperation($resourceEdges, $operations);
            $common = [
                'id' => $resource['id'],
                'title' => $resource['title'],
                'plan_ids' => $this->planIds($resourceEdges),
                'ownership' => $this->ownership($resourceEdges),
                'operation_state' => $this->operationState(
                    $resourceEdges,
                    $operation,
                    (bool) $resource['provider_present']
                ),
            ];

            if ($resource['resource_type'] === 'fc_course') {
                $courses[] = [
                    'id' => $common['id'],
                    'title' => $common['title'],
                    'progress' => $resource['progress'],
                    'plan_ids' => $common['plan_ids'],
                    'ownership' => $common['ownership'],
                    'operation_state' => $common['operation_state'],
                ];
            } else {
                $spaces[] = $common;
            }
        }

        usort($spaces, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);
        usort($courses, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);

        return ['spaces' => $spaces, 'courses' => $courses];
    }

    private function value(mixed $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }
        if (is_object($row)) {
            return $row->{$key} ?? null;
        }

        return null;
    }

    /** @return list<int> */
    private function planIds(array $edges): array
    {
        $planIds = array_values(array_unique(array_filter(
            array_map(static fn(array $edge): int => (int) ($edge['plan_id'] ?? 0), $edges),
            static fn(int $planId): bool => $planId > 0
        )));
        sort($planIds, SORT_NUMERIC);

        return $planIds;
    }

    private function ownership(array $edges): string
    {
        if ($edges === []) {
            return 'unmanaged';
        }

        foreach ($edges as $edge) {
            if (($edge['owner'] ?? '') !== 'fchub'
                || ($edge['assignment_provenance'] ?? '') === 'unknown'
            ) {
                return 'unknown';
            }
            if (($edge['assignment_provenance'] ?? '') === 'preexisting') {
                return 'preexisting';
            }
        }

        return 'fchub';
    }

    private function latestOperation(array $edges, array $operations): ?array
    {
        $latest = null;
        foreach ($edges as $edge) {
            $edgeId = (int) ($edge['id'] ?? 0);
            $operation = $operations[$edgeId] ?? null;
            if (!is_array($operation)) {
                continue;
            }
            if ($latest === null || (int) ($operation['id'] ?? 0) > (int) ($latest['id'] ?? 0)) {
                $latest = $operation;
            }
        }

        return $latest;
    }

    private function operationState(array $edges, ?array $operation, bool $providerPresent): string
    {
        if ($edges === []) {
            return 'unmanaged';
        }
        if ($operation === null) {
            return $providerPresent ? 'healthy' : 'drift';
        }

        $state = (string) ($operation['state'] ?? '');
        if (in_array($state, ['pending', 'deferred', 'processing'], true)) {
            return $state;
        }
        if ($state === 'failed') {
            return !empty($operation['retryable']) ? 'retrying' : 'failed';
        }
        if ($state === 'applied') {
            return $providerPresent ? 'healthy' : 'drift';
        }

        return 'drift';
    }

    /** @return array<string, mixed> */
    private function degradedContext(array $capabilities): array
    {
        return [
            'state' => 'degraded',
            'profile' => ['is_verified' => null],
            'spaces' => [],
            'courses' => [],
            'pending_access_count' => 0,
            'capabilities' => $capabilities,
        ];
    }
}
