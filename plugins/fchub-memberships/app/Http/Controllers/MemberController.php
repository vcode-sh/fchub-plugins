<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Drip\DripEvaluator;
use FChubMemberships\Domain\Member\MemberProfileService;
use FChubMemberships\Domain\Reconciliation\ProviderReconciliationService;
use FChubMemberships\Http\MembershipMutationPermission;
use FChubMemberships\Http\MembershipRestArguments;
use FChubMemberships\Http\IdempotentMutation;
use FChubMemberships\Support\AdminRequestFilters;
use FChubMemberships\Support\CsvSanitizer;

class MemberController
{
    private static ?\Closure $accessGrantServiceFactory = null;

    public static function setAccessGrantServiceFactory(?callable $factory): void
    {
        self::$accessGrantServiceFactory = $factory === null ? null : \Closure::fromCallable($factory);
    }

    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/members', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'index'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/(?P<user_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'show'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/grant', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'grant'],
            'permission_callback' => [MembershipMutationPermission::class, 'check'],
            'args'                => MembershipRestArguments::grant(),
        ]);

        register_rest_route($ns, '/admin/members/revoke', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'revoke'],
            'permission_callback' => [MembershipMutationPermission::class, 'check'],
            'args'                => MembershipRestArguments::revoke(),
        ]);

        register_rest_route($ns, '/admin/members/extend', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'extend'],
            'permission_callback' => [MembershipMutationPermission::class, 'check'],
            'args'                => MembershipRestArguments::extend(),
        ]);

        register_rest_route($ns, '/admin/members/(?P<user_id>\d+)/drip-timeline', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'dripTimeline'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/export', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'export'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/pause', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'pause'],
            'permission_callback' => [MembershipMutationPermission::class, 'check'],
            'args'                => MembershipRestArguments::pause(),
        ]);

        register_rest_route($ns, '/admin/members/resume', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'resume'],
            'permission_callback' => [MembershipMutationPermission::class, 'check'],
            'args'                => MembershipRestArguments::resume(),
        ]);

        register_rest_route($ns, '/admin/members/bulk-grant', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulkGrant'],
            'permission_callback' => [MembershipMutationPermission::class, 'check'],
            'args'                => MembershipRestArguments::bulkGrant(),
        ]);

        register_rest_route($ns, '/admin/members/bulk-revoke', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulkRevoke'],
            'permission_callback' => [MembershipMutationPermission::class, 'check'],
            'args'                => MembershipRestArguments::bulkRevoke(),
        ]);

        register_rest_route($ns, '/admin/members/bulk-extend', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulkExtend'],
            'permission_callback' => [MembershipMutationPermission::class, 'check'],
            'args'                => MembershipRestArguments::bulkExtend(),
        ]);

        register_rest_route($ns, '/admin/members/bulk-export', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulkExport'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/(?P<user_id>\d+)/audit-log', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'auditLog'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/(?P<user_id>\d+)/activity', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'activity'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/(?P<user_id>\d+)/provider-state', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'providerState'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function index(\WP_REST_Request $request): \WP_REST_Response
    {
        if ($request->get_param('users_only')) {
            return self::searchUsers($request);
        }

        $repo = new GrantRepository();
        $filters = AdminRequestFilters::memberList($request);

        $members = $repo->getMembers($filters);
        $total = $repo->countMembers($filters);

        foreach ($members as &$member) {
            if (!$member['plan_id']) {
                $member['plan_title'] = __('Direct Grant', 'fchub-memberships');
            }
        }

        return new \WP_REST_Response([
            'data'    => $members,
            'total'   => $total,
            'summary' => $repo->getAdminSummary(),
        ]);
    }

    public static function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        $user = get_userdata($userId);

        if (!$user) {
            return new \WP_REST_Response(['message' => __('User not found.', 'fchub-memberships')], 404);
        }

        return new \WP_REST_Response([
            'data' => [
                'user' => [
                    'id'           => $user->ID,
                    'display_name' => $user->display_name,
                    'email'        => $user->user_email,
                    'user_email'   => $user->user_email,
                    'registered_at' => $user->user_registered ?? null,
                    'avatar_url'   => get_avatar_url($user->ID, ['size' => 96]),
                    'edit_url'     => get_edit_user_link($user->ID),
                ],
                'memberships' => (new MemberProfileService())->memberships($userId),
            ],
        ]);
    }

    public static function grant(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $userId = (int) ($data['user_id'] ?? 0);
        $planId = (int) ($data['plan_id'] ?? 0);
        $expiresAt = $data['expires_at'] ?? null;

        if (!$userId || !$planId) {
            return new \WP_REST_Response(['message' => __('User ID and Plan ID are required.', 'fchub-memberships')], 422);
        }

        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_REST_Response(['message' => __('User not found.', 'fchub-memberships')], 404);
        }

        return (new IdempotentMutation())->execute($request, 'grant', static function () use ($userId, $planId, $expiresAt): \WP_REST_Response {
            $result = self::accessGrantService()->manualGrant($userId, $planId, $expiresAt);
            return self::grantResultResponse($result);
        });
    }

    public static function revoke(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $userId = (int) ($data['user_id'] ?? 0);
        $planId = (int) ($data['plan_id'] ?? 0);
        $reason = sanitize_text_field($data['reason'] ?? '');

        if (!$userId || !$planId) {
            return new \WP_REST_Response(['message' => __('User ID and Plan ID are required.', 'fchub-memberships')], 422);
        }

        return (new IdempotentMutation())->execute($request, 'revoke', static function () use ($userId, $planId, $reason): \WP_REST_Response {
            $result = self::accessGrantService()->revokePlan($userId, $planId, ['reason' => $reason]);
            return self::revokeResultResponse($result);
        });
    }

    public static function grantResultResponse(array $result): \WP_REST_Response
    {
        $succeeded = (int) ($result['created'] ?? 0) + (int) ($result['updated'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);

        if ($failed > 0) {
            $partial = $succeeded > 0;
            return new \WP_REST_Response([
                'data' => $result,
                'message' => $partial
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    ? sprintf(__('%1$d resources were granted and %2$d failed. Access was partially granted.', 'fchub-memberships'), $succeeded, $failed)
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    : sprintf(__('Access could not be granted. %d resources failed.', 'fchub-memberships'), $failed),
            ], $partial ? 207 : 502);
        }

        return new \WP_REST_Response([
            'data' => $result,
            'message' => __('Access granted successfully.', 'fchub-memberships'),
        ]);
    }

    public static function revokeResultResponse(array $result): \WP_REST_Response
    {
        $revoked = (int) ($result['revoked'] ?? 0);
        $graceStarted = (int) ($result['grace_started'] ?? 0);
        $retained = (int) ($result['retained'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);

        if ($failed > 0 || (array_key_exists('success', $result) && $result['success'] === false)) {
            $partial = $revoked > 0 || $graceStarted > 0 || $retained > 0 || !empty($result['partial']);
            return new \WP_REST_Response([
                'data' => $result,
                'message' => $partial
                    ? sprintf(
                        /* translators: Placeholder values are runtime membership details included in this message. */
                        __('%1$d resources were revoked, %2$d scheduled after grace, %3$d retained, and %4$d failed. Access was partially revoked or scheduled.', 'fchub-memberships'),
                        $revoked,
                        $graceStarted,
                        $retained,
                        $failed
                    )
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    : sprintf(__('Access could not be revoked. %d resources failed.', 'fchub-memberships'), $failed),
            ], $partial ? 207 : 502);
        }

        if ($graceStarted > 0) {
            return new \WP_REST_Response([
                'data' => $result,
                'message' => $revoked > 0
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    ? sprintf(__('%1$d resources revoked and %2$d scheduled after grace.', 'fchub-memberships'), $revoked, $graceStarted)
                    : __('Access revocation scheduled for the end of the grace period.', 'fchub-memberships'),
            ]);
        }

        return new \WP_REST_Response([
            'data' => $result,
            'message' => __('Access revoked.', 'fchub-memberships'),
        ]);
    }

    public static function extend(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $userId = (int) ($data['user_id'] ?? 0);
        $planId = (int) ($data['plan_id'] ?? 0);
        $expiresAt = $data['expires_at'] ?? null;

        if (!$userId || !$planId || !$expiresAt) {
            return new \WP_REST_Response(['message' => __('User ID, Plan ID, and expiry date are required.', 'fchub-memberships')], 422);
        }

        return (new IdempotentMutation())->execute($request, 'extend', static function () use ($userId, $planId, $expiresAt): \WP_REST_Response {
            $extended = self::accessGrantService()->extendExpiry($userId, $planId, $expiresAt);
            if ($extended === 0) {
                return new \WP_REST_Response(['message' => __('No compatible active grant was found.', 'fchub-memberships')], 404);
            }

            return new \WP_REST_Response([
                'data'    => ['extended' => $extended],
                /* translators: Placeholder values are runtime membership details included in this message. */
                'message' => sprintf(__('%d grants extended.', 'fchub-memberships'), $extended),
            ]);
        });
    }

    public static function dripTimeline(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        $planId = (int) $request->get_param('plan_id');

        if (!$planId) {
            return new \WP_REST_Response(['message' => __('Plan ID is required.', 'fchub-memberships')], 422);
        }

        $evaluator = new DripEvaluator();
        $timeline = $evaluator->getTimeline($userId, $planId);

        return new \WP_REST_Response(['data' => $timeline]);
    }

    public static function export(\WP_REST_Request $request): \WP_REST_Response
    {
        $repo = new GrantRepository();
        $filters = [
            'status'  => $request->get_param('status') ?: 'active',
            'plan_id' => $request->get_param('plan_id'),
        ];

        $members = $repo->getMembers(array_merge($filters, ['per_page' => 10000]));

        $rows = [];
        foreach ($members as $member) {
            $rows[] = [
                'user_id'      => $member['user_id'],
                'email'        => $member['user_email'] ?? '',
                'display_name' => $member['display_name'] ?? '',
                'plan_id'      => $member['plan_id'],
                'plan_title'   => $member['plan_title'] ?? '',
                'status'       => $member['status'],
                'source_type'  => $member['source_type'],
                'created_at'   => $member['created_at'],
                'expires_at'   => $member['expires_at'],
            ];
        }

        return new \WP_REST_Response(['data' => $rows]);
    }

    public static function pause(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $grantId = (int) ($data['grant_id'] ?? 0);
        $reason = sanitize_text_field($data['reason'] ?? '');
        if (!$grantId) {
            return new \WP_REST_Response(['message' => __('Grant ID is required.', 'fchub-memberships')], 422);
        }
        return (new IdempotentMutation())->execute($request, 'pause', static function () use ($grantId, $reason): \WP_REST_Response {
            try {
                $result = self::accessGrantService()->pauseGrant($grantId, $reason);
                if (!empty($result['error'])) {
                    return new \WP_REST_Response(['message' => $result['error']], 404);
                }
                return new \WP_REST_Response(['data' => $result, 'message' => __('Membership paused.', 'fchub-memberships')]);
            } catch (\InvalidArgumentException $exception) {
                return self::grantExceptionResponse($exception);
            }
        });
    }

    public static function resume(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $grantId = (int) ($data['grant_id'] ?? 0);
        if (!$grantId) {
            return new \WP_REST_Response(['message' => __('Grant ID is required.', 'fchub-memberships')], 422);
        }
        return (new IdempotentMutation())->execute($request, 'resume', static function () use ($grantId): \WP_REST_Response {
            try {
                $result = self::accessGrantService()->resumeGrant($grantId);
                if (!empty($result['error'])) {
                    return new \WP_REST_Response(['message' => $result['error']], 404);
                }
                return new \WP_REST_Response(['data' => $result, 'message' => __('Membership resumed.', 'fchub-memberships')]);
            } catch (\InvalidArgumentException $exception) {
                return self::grantExceptionResponse($exception);
            }
        });
    }

    public static function bulkGrant(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $userIds = array_map('intval', $data['user_ids'] ?? []);
        $planId = (int) ($data['plan_id'] ?? 0);
        $expiresAt = $data['expires_at'] ?? null;
        if (empty($userIds) || !$planId) {
            return new \WP_REST_Response(['message' => __('User IDs and Plan ID are required.', 'fchub-memberships')], 422);
        }
        return (new IdempotentMutation())->execute($request, 'bulk_grant', static function () use ($userIds, $planId, $expiresAt): \WP_REST_Response {
            $result = self::accessGrantService()->bulkGrant($userIds, $planId, ['expires_at' => $expiresAt, 'source_type' => 'manual']);
            $status = $result['failed'] > 0 ? ($result['granted'] > 0 ? 207 : 502) : 200;
            $message = $result['failed'] > 0
                /* translators: Placeholder values are runtime membership details included in this message. */
                ? sprintf(__('%1$d memberships granted and %2$d failed.', 'fchub-memberships'), $result['granted'], $result['failed'])
                /* translators: Placeholder values are runtime membership details included in this message. */
                : sprintf(__('%d memberships granted.', 'fchub-memberships'), $result['granted']);
            return new \WP_REST_Response(['data' => $result, 'message' => $message], $status);
        });
    }

    public static function bulkRevoke(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $userIds = array_map('intval', $data['user_ids'] ?? []);
        $planId = (int) ($data['plan_id'] ?? 0);
        $reason = sanitize_text_field($data['reason'] ?? '');
        if (empty($userIds) || !$planId) {
            return new \WP_REST_Response(['message' => __('User IDs and Plan ID are required.', 'fchub-memberships')], 422);
        }
        return (new IdempotentMutation())->execute($request, 'bulk_revoke', static function () use ($userIds, $planId, $reason): \WP_REST_Response {
            $result = self::accessGrantService()->bulkRevoke($userIds, $planId, ['reason' => $reason]);
            $graceStarted = (int) ($result['grace_started'] ?? 0);
            $succeeded = (int) $result['revoked'] + $graceStarted;
            $status = $result['failed'] > 0 ? ($succeeded > 0 ? 207 : 502) : 200;
            $message = match (true) {
                $result['failed'] > 0 => sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('%1$d memberships revoked, %2$d scheduled after grace, and %3$d failed.', 'fchub-memberships'),
                    $result['revoked'],
                    $graceStarted,
                    $result['failed']
                ),
                $graceStarted > 0 && $result['revoked'] > 0 => sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('%1$d memberships revoked and %2$d scheduled after grace.', 'fchub-memberships'),
                    $result['revoked'],
                    $graceStarted
                ),
                $graceStarted > 0 => sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('%d membership revocations scheduled after grace.', 'fchub-memberships'),
                    $graceStarted
                ),
                /* translators: Placeholder values are runtime membership details included in this message. */
                default => sprintf(__('%d memberships revoked.', 'fchub-memberships'), $result['revoked']),
            };
            return new \WP_REST_Response(['data' => $result, 'message' => $message], $status);
        });
    }

    public static function bulkExtend(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $userIds = array_map('intval', $data['user_ids'] ?? []);
        $planId = (int) ($data['plan_id'] ?? 0);
        $expiresAt = $data['expires_at'] ?? null;

        if (empty($userIds) || !$planId || !$expiresAt) {
            return new \WP_REST_Response([
                'message' => __('User IDs, Plan ID, and expiry date are required.', 'fchub-memberships'),
            ], 422);
        }

        return (new IdempotentMutation())->execute($request, 'bulk_extend', static function () use ($userIds, $planId, $expiresAt): \WP_REST_Response {
            $service = self::accessGrantService();
            $extended = 0;
            $failed = 0;
            $notFound = 0;
            $errors = [];

            foreach ($userIds as $userId) {
                try {
                    $count = $service->extendExpiry($userId, $planId, $expiresAt);
                    if ($count === 0) {
                        $notFound++;
                        $errors[] = sprintf('User #%d: no compatible active grant was found.', $userId);
                        continue;
                    }
                    $extended += $count;
                } catch (\Exception $exception) {
                    $failed++;
                    $errors[] = sprintf('User #%d: %s', $userId, $exception->getMessage());
                }
            }

            $total = count($userIds);
            $status = match (true) {
                $extended > 0 && ($notFound > 0 || $failed > 0) => 207,
                $failed === $total => 502,
                $notFound === $total => 404,
                $notFound > 0 || $failed > 0 => 207,
                default => 200,
            };
            return new \WP_REST_Response([
                'data'    => ['extended' => $extended, 'not_found' => $notFound, 'failed' => $failed, 'errors' => $errors],
                /* translators: Placeholder values are runtime membership details included in this message. */
                'message' => sprintf(__('%d grants extended.', 'fchub-memberships'), $extended),
            ], $status);
        });
    }

    public static function bulkExport(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $userIds = array_map('intval', $data['user_ids'] ?? []);

        if (empty($userIds)) {
            return new \WP_REST_Response([
                'message' => __('User IDs are required.', 'fchub-memberships'),
            ], 422);
        }

        $rows = self::membershipExportRows($userIds);

        // Build CSV string
        $csv = '';
        if (!empty($rows)) {
            $csv .= implode(',', array_keys($rows[0])) . "\n";
            foreach ($rows as $row) {
                $csv .= implode(',', array_map(function ($v) {
                    $value = CsvSanitizer::sanitizeCell((string) $v);
                    return '"' . str_replace('"', '""', $value) . '"';
                }, $row)) . "\n";
            }
        }

        return new \WP_REST_Response(['csv' => $csv]);
    }

    /**
     * One export row per membership, matching the filtered export and the list.
     *
     * A plan writes one grant row per rule, so exporting rows would repeat a
     * membership once per protected resource and report each row's own status.
     * Plan titles are read in one query rather than once per grant.
     *
     * @param list<int> $userIds
     * @return list<array<string, mixed>>
     */
    private static function membershipExportRows(array $userIds): array
    {
        $repo = new GrantRepository();
        $grantsByUser = [];
        $planIds = [];

        foreach ($userIds as $userId) {
            $grants = $repo->getByUserId($userId);
            $grantsByUser[$userId] = $grants;
            $planIds = array_merge($planIds, array_column($grants, 'plan_id'));
        }

        $planTitles = array_map(
            static fn(array $plan): string => (string) $plan['title'],
            (new \FChubMemberships\Storage\PlanRepository())->findMany($planIds)
        );
        $grouper = new \FChubMemberships\Domain\Member\MembershipGrouper();

        $rows = [];
        foreach ($grantsByUser as $userId => $grants) {
            $user = get_userdata($userId);

            foreach ($grouper->group($grants, $planTitles) as $membership) {
                $rows[] = [
                    'user_id'      => $userId,
                    'email'        => $user ? $user->user_email : '',
                    'display_name' => $user ? $user->display_name : '',
                    'plan_id'      => $membership['plan_id'],
                    'plan_title'   => $membership['plan_title'],
                    'status'       => $membership['status'],
                    'source_type'  => $membership['source_type'],
                    'created_at'   => $membership['created_at'],
                    'expires_at'   => $membership['expires_at'],
                ];
            }
        }

        return $rows;
    }

    public static function auditLog(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        $grants = (new GrantRepository())->getByUserId($userId);
        $entries = (new \FChubMemberships\Storage\AuditLogRepository())
            ->getByEntityIds('grant', array_column($grants, 'id'), 50);

        return new \WP_REST_Response(['data' => $entries]);
    }

    public static function activity(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = min(50, max(10, (int) ($request->get_param('per_page') ?: 50)));

        $events = (new MemberProfileService())->timeline($userId);

        return new \WP_REST_Response([
            'data'  => array_slice($events, ($page - 1) * $perPage, $perPage),
            'total' => count($events),
            'page'  => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Provider truth for one member, read on request.
     *
     * Classification calls into the live providers, so this is never part of
     * loading the profile.
     */
    public static function providerState(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        if (!get_userdata($userId)) {
            return new \WP_REST_Response(['message' => __('User not found.', 'fchub-memberships')], 404);
        }

        return new \WP_REST_Response([
            'data' => (new ProviderReconciliationService())->classifyForUser($userId),
        ]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }

    private static function grantExceptionResponse(\InvalidArgumentException $exception): \WP_REST_Response
    {
        $message = $exception->getMessage();
        $status = str_contains(strtolower($message), 'not found') ? 404 : 422;

        return new \WP_REST_Response(['message' => $message], $status);
    }

    private static function accessGrantService(): AccessGrantService
    {
        if (self::$accessGrantServiceFactory === null) {
            return new AccessGrantService();
        }

        $service = (self::$accessGrantServiceFactory)();
        if (!$service instanceof AccessGrantService) {
            throw new \LogicException('The access grant service factory must return AccessGrantService.');
        }

        return $service;
    }

    private static function searchUsers(\WP_REST_Request $request): \WP_REST_Response
    {
        $search = sanitize_text_field((string) ($request->get_param('search') ?? ''));
        $perPage = max(1, min(20, (int) ($request->get_param('per_page') ?: 10)));

        $users = get_users([
            'search'         => $search !== '' ? '*' . $search . '*' : '',
            'search_columns' => ['user_email', 'display_name', 'user_login'],
            'number'         => $perPage,
            'orderby'        => $search === '' ? 'registered' : 'display_name',
            'order'          => $search === '' ? 'DESC' : 'ASC',
            'count_total'    => false,
        ]);

        $data = array_map(static function (object $user): array {
            return [
                'id'           => (int) $user->ID,
                'display_name' => (string) ($user->display_name ?? ''),
                'email'        => (string) ($user->user_email ?? ''),
                'registered_at' => $user->user_registered ?? null,
            ];
        }, $users);

        return new \WP_REST_Response([
            'data'  => $data,
            'total' => count($data),
        ]);
    }
}
