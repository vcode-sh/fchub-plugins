<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Drip\DripEvaluator;
use FChubMemberships\Support\AdminRequestFilters;

class MemberController
{
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
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/revoke', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'revoke'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/extend', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'extend'],
            'permission_callback' => [self::class, 'adminPermission'],
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
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/resume', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'resume'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/bulk-grant', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulkGrant'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/bulk-revoke', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulkRevoke'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/members/bulk-extend', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulkExtend'],
            'permission_callback' => [self::class, 'adminPermission'],
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
    }

    public static function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $repo = new GrantRepository();
        $filters = AdminRequestFilters::memberList($request);

        $members = $repo->getMembers($filters);
        $total = $repo->countMembers($filters);

        // Enrich with plan info
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        foreach ($members as &$member) {
            if ($member['plan_id']) {
                $plan = $planRepo->find($member['plan_id']);
                $member['plan_title'] = $plan ? $plan['title'] : '';
            } else {
                $member['plan_title'] = __('Direct Grant', 'fchub-memberships');
            }
        }

        return new \WP_REST_Response([
            'data'  => $members,
            'total' => $total,
        ]);
    }

    public static function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        $user = get_userdata($userId);

        if (!$user) {
            return new \WP_REST_Response(['message' => __('User not found.', 'fchub-memberships')], 404);
        }

        $repo = new GrantRepository();
        $activeGrants = $repo->getActiveByUserGroupedByPlan($userId);
        $allGrants = $repo->getByUserId($userId);

        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $evaluator = new AccessEvaluator();

        $plans = [];
        foreach ($activeGrants as $planId => $grants) {
            $plan = $planId ? $planRepo->find($planId) : null;
            $progress = $planId ? $evaluator->getDripProgress($userId, $planId) : null;

            $plans[] = [
                'plan_id'    => $planId,
                'plan_title' => $plan ? $plan['title'] : __('Direct Grant', 'fchub-memberships'),
                'grants'     => $grants,
                'progress'   => $progress,
            ];
        }

        // Collect audit log entries for all user grants
        $auditRepo = new \FChubMemberships\Storage\AuditLogRepository();
        $grantIds = array_column($allGrants, 'id');
        $auditEntries = [];
        foreach ($grantIds as $grantId) {
            $auditEntries = array_merge($auditEntries, $auditRepo->getByEntity('grant', (int) $grantId, 20));
        }
        usort($auditEntries, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

        return new \WP_REST_Response([
            'data' => [
                'user' => [
                    'id'           => $user->ID,
                    'display_name' => $user->display_name,
                    'user_email'   => $user->user_email,
                    'avatar_url'   => get_avatar_url($user->ID, ['size' => 64]),
                ],
                'plans'     => $plans,
                'history'   => $allGrants,
                'audit_log' => array_slice($auditEntries, 0, 50),
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

        $service = new AccessGrantService();
        $result = $service->manualGrant($userId, $planId, $expiresAt);

        return new \WP_REST_Response([
            'data'    => $result,
            'message' => __('Access granted successfully.', 'fchub-memberships'),
        ]);
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

        $service = new AccessGrantService();
        $result = $service->revokePlan($userId, $planId, ['reason' => $reason]);

        return new \WP_REST_Response([
            'data'    => $result,
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

        $service = new AccessGrantService();
        $extended = $service->extendExpiry($userId, $planId, $expiresAt);

        return new \WP_REST_Response([
            'data'    => ['extended' => $extended],
            'message' => sprintf(__('%d grants extended.', 'fchub-memberships'), $extended),
        ]);
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
        $service = new AccessGrantService();
        try {
            $result = $service->pauseGrant($grantId, $reason);
            return new \WP_REST_Response(['data' => $result, 'message' => __('Membership paused.', 'fchub-memberships')]);
        } catch (\InvalidArgumentException $e) {
            return new \WP_REST_Response(['message' => $e->getMessage()], 422);
        }
    }

    public static function resume(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $grantId = (int) ($data['grant_id'] ?? 0);
        if (!$grantId) {
            return new \WP_REST_Response(['message' => __('Grant ID is required.', 'fchub-memberships')], 422);
        }
        $service = new AccessGrantService();
        try {
            $result = $service->resumeGrant($grantId);
            return new \WP_REST_Response(['data' => $result, 'message' => __('Membership resumed.', 'fchub-memberships')]);
        } catch (\InvalidArgumentException $e) {
            return new \WP_REST_Response(['message' => $e->getMessage()], 422);
        }
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
        $service = new AccessGrantService();
        $result = $service->bulkGrant($userIds, $planId, ['expires_at' => $expiresAt, 'source_type' => 'manual']);
        return new \WP_REST_Response(['data' => $result, 'message' => sprintf(__('%d grants created.', 'fchub-memberships'), $result['granted'])]);
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
        $service = new AccessGrantService();
        $result = $service->bulkRevoke($userIds, $planId, ['reason' => $reason]);
        return new \WP_REST_Response(['data' => $result, 'message' => sprintf(__('%d grants revoked.', 'fchub-memberships'), $result['revoked'])]);
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

        $service = new AccessGrantService();
        $extended = 0;
        $failed = 0;
        $errors = [];

        foreach ($userIds as $userId) {
            try {
                $count = $service->extendExpiry($userId, $planId, $expiresAt);
                $extended += $count;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = sprintf('User #%d: %s', $userId, $e->getMessage());
            }
        }

        return new \WP_REST_Response([
            'data'    => ['extended' => $extended, 'failed' => $failed, 'errors' => $errors],
            'message' => sprintf(__('%d grants extended.', 'fchub-memberships'), $extended),
        ]);
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

        $repo = new GrantRepository();
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $rows = [];

        foreach ($userIds as $userId) {
            $grants = $repo->getByUserId($userId);
            $user = get_userdata($userId);

            foreach ($grants as $grant) {
                $planTitle = '';
                if ($grant['plan_id']) {
                    $plan = $planRepo->find($grant['plan_id']);
                    $planTitle = $plan ? $plan['title'] : '';
                }

                $rows[] = [
                    'user_id'      => $grant['user_id'],
                    'email'        => $user ? $user->user_email : '',
                    'display_name' => $user ? $user->display_name : '',
                    'plan_id'      => $grant['plan_id'],
                    'plan_title'   => $planTitle,
                    'status'       => $grant['status'],
                    'source_type'  => $grant['source_type'],
                    'created_at'   => $grant['created_at'],
                    'expires_at'   => $grant['expires_at'],
                ];
            }
        }

        // Build CSV string
        $csv = '';
        if (!empty($rows)) {
            $csv .= implode(',', array_keys($rows[0])) . "\n";
            foreach ($rows as $row) {
                $csv .= implode(',', array_map(function ($v) {
                    return '"' . str_replace('"', '""', (string) $v) . '"';
                }, $row)) . "\n";
            }
        }

        return new \WP_REST_Response(['csv' => $csv]);
    }

    public static function auditLog(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        $auditRepo = new \FChubMemberships\Storage\AuditLogRepository();
        $grantRepo = new GrantRepository();
        $grants = $grantRepo->getByUserId($userId);
        $grantIds = array_column($grants, 'id');

        $entries = [];
        foreach ($grantIds as $grantId) {
            $entries = array_merge($entries, $auditRepo->getByEntity('grant', (int) $grantId, 20));
        }
        usort($entries, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

        return new \WP_REST_Response(['data' => array_slice($entries, 0, 50)]);
    }

    public static function activity(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = min(50, max(10, (int) ($request->get_param('per_page') ?: 50)));

        $grantRepo = new GrantRepository();
        $auditRepo = new \FChubMemberships\Storage\AuditLogRepository();
        $dripRepo = new \FChubMemberships\Storage\DripScheduleRepository();
        $planRepo = new \FChubMemberships\Storage\PlanRepository();

        $events = [];

        // 1. Grant events (created, expired, revoked, paused)
        $grants = $grantRepo->getByUserId($userId);
        foreach ($grants as $grant) {
            $planTitle = '';
            if ($grant['plan_id']) {
                $plan = $planRepo->find($grant['plan_id']);
                $planTitle = $plan ? $plan['title'] : '';
            }

            // Grant created
            $events[] = [
                'date'        => $grant['created_at'],
                'type'        => 'grant_created',
                'description' => sprintf('Access granted to plan "%s"', $planTitle ?: 'Direct Grant'),
                'metadata'    => [
                    'grant_id'    => $grant['id'],
                    'plan_id'     => $grant['plan_id'],
                    'plan_title'  => $planTitle,
                    'source_type' => $grant['source_type'],
                ],
            ];

            // Revoked
            if (!empty($grant['revoked_at'])) {
                $events[] = [
                    'date'        => $grant['revoked_at'],
                    'type'        => 'grant_revoked',
                    'description' => sprintf('Access revoked for plan "%s"', $planTitle ?: 'Direct Grant'),
                    'metadata'    => [
                        'grant_id'   => $grant['id'],
                        'plan_id'    => $grant['plan_id'],
                        'plan_title' => $planTitle,
                    ],
                ];
            }

            // Paused
            if (!empty($grant['paused_at'])) {
                $events[] = [
                    'date'        => $grant['paused_at'],
                    'type'        => 'grant_paused',
                    'description' => sprintf('Membership paused for plan "%s"', $planTitle ?: 'Direct Grant'),
                    'metadata'    => [
                        'grant_id'   => $grant['id'],
                        'plan_id'    => $grant['plan_id'],
                        'plan_title' => $planTitle,
                    ],
                ];
            }

            // Status is expired and updated_at differs from created_at
            if ($grant['status'] === 'expired' && $grant['updated_at'] !== $grant['created_at']) {
                $events[] = [
                    'date'        => $grant['updated_at'],
                    'type'        => 'grant_expired',
                    'description' => sprintf('Access expired for plan "%s"', $planTitle ?: 'Direct Grant'),
                    'metadata'    => [
                        'grant_id'   => $grant['id'],
                        'plan_id'    => $grant['plan_id'],
                        'plan_title' => $planTitle,
                    ],
                ];
            }

            // Trial started
            if (!empty($grant['trial_ends_at'])) {
                $events[] = [
                    'date'        => $grant['created_at'],
                    'type'        => 'trial_started',
                    'description' => sprintf('Trial started for plan "%s" (ends %s)', $planTitle ?: 'Direct Grant', $grant['trial_ends_at']),
                    'metadata'    => [
                        'grant_id'      => $grant['id'],
                        'plan_id'       => $grant['plan_id'],
                        'plan_title'    => $planTitle,
                        'trial_ends_at' => $grant['trial_ends_at'],
                    ],
                ];
            }

            // Renewal events
            if ($grant['renewal_count'] > 0) {
                $events[] = [
                    'date'        => $grant['updated_at'],
                    'type'        => 'grant_renewed',
                    'description' => sprintf('Membership renewed for plan "%s" (renewal #%d)', $planTitle ?: 'Direct Grant', $grant['renewal_count']),
                    'metadata'    => [
                        'grant_id'      => $grant['id'],
                        'plan_id'       => $grant['plan_id'],
                        'plan_title'    => $planTitle,
                        'renewal_count' => $grant['renewal_count'],
                    ],
                ];
            }
        }

        // 2. Audit log entries
        $grantIds = array_column($grants, 'id');
        foreach ($grantIds as $grantId) {
            $auditEntries = $auditRepo->getByEntity('grant', (int) $grantId, 50);
            foreach ($auditEntries as $entry) {
                $events[] = [
                    'date'        => $entry['created_at'],
                    'type'        => 'audit_' . ($entry['action'] ?? 'unknown'),
                    'description' => sprintf('%s by %s #%d', ucfirst($entry['action'] ?? 'Action'), $entry['actor_type'] ?? 'system', $entry['actor_id'] ?? 0),
                    'metadata'    => [
                        'audit_id'   => $entry['id'],
                        'action'     => $entry['action'] ?? '',
                        'actor_type' => $entry['actor_type'] ?? '',
                        'actor_id'   => $entry['actor_id'] ?? 0,
                        'context'    => $entry['context'] ?? '',
                        'old_value'  => $entry['old_value'] ?? [],
                        'new_value'  => $entry['new_value'] ?? [],
                    ],
                ];
            }
        }

        // 3. Drip notifications
        $dripNotifications = $dripRepo->getByUserId($userId, ['per_page' => 100]);
        foreach ($dripNotifications as $notif) {
            $date = $notif['sent_at'] ?? $notif['notify_at'];
            $status = $notif['status'];
            $type = $status === 'sent' ? 'drip_sent' : ($status === 'pending' ? 'drip_scheduled' : 'drip_failed');
            $desc = $status === 'sent'
                ? 'Drip notification sent'
                : ($status === 'pending' ? 'Drip notification scheduled' : 'Drip notification failed');

            $events[] = [
                'date'        => $date,
                'type'        => $type,
                'description' => $desc,
                'metadata'    => [
                    'notification_id' => $notif['id'],
                    'grant_id'        => $notif['grant_id'],
                    'status'          => $status,
                ],
            ];
        }

        // Sort by date descending
        usort($events, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        // Deduplicate: remove audit entries that duplicate grant events at the same timestamp
        $seen = [];
        $deduplicated = [];
        foreach ($events as $event) {
            $key = $event['date'] . '|' . $event['type'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduplicated[] = $event;
        }

        $total = count($deduplicated);
        $offset = ($page - 1) * $perPage;
        $paginated = array_slice($deduplicated, $offset, $perPage);

        return new \WP_REST_Response([
            'data'  => $paginated,
            'total' => $total,
            'page'  => $page,
            'per_page' => $perPage,
        ]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
