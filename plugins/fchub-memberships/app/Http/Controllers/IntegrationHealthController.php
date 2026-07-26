<?php

declare(strict_types=1);

namespace FChubMemberships\Http\Controllers;

use FChubMemberships\FluentCRM\Projection\MembershipContactProjector;
use FChubMemberships\Http\IdempotentMutation;
use FChubMemberships\Http\MembershipMutationPermission;
use FChubMemberships\Http\IntegrationHealthRestArguments;
use FChubMemberships\Integration\FluentCrmIntegrationHealth;
use FChubMemberships\Integration\FluentCrmSync;

defined('ABSPATH') || exit;

final class IntegrationHealthController
{
    private const PAGE_SIZE = 100;

    /** @var \Closure(int, bool): array<string, mixed> */
    private \Closure $reconciler;
    /** @var \Closure(int, int, int): array */
    private \Closure $memberIdsResolver;
    /** @var \Closure(): int */
    private \Closure $watermarkResolver;
    /** @var \Closure(int): array<string, mixed> */
    private \Closure $projectionQueue;

    public function __construct(
        private FluentCrmIntegrationHealth $health = new FluentCrmIntegrationHealth(),
        ?callable $reconciler = null,
        ?callable $usersResolver = null,
        ?callable $watermarkResolver = null,
        ?callable $projectionQueue = null
    ) {
        $this->reconciler = \Closure::fromCallable($reconciler ?? static function (int $userId, bool $dryRun): array {
            $projector = new MembershipContactProjector();

            return $dryRun ? $projector->preview($userId) : $projector->reconcile($userId);
        });
        $this->memberIdsResolver = \Closure::fromCallable($usersResolver ?? static function (
            int $cursor,
            int $watermark,
            int $limit
        ): array {
            global $wpdb;
            $table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_grants');

            return array_map('intval', \FChubMemberships\Support\CustomTableDatabase::getCol(\FChubMemberships\Support\CustomTableDatabase::prepare(
                "SELECT DISTINCT user_id FROM {$table}
                 WHERE user_id > %d AND user_id <= %d
                 ORDER BY user_id ASC LIMIT %d",
                $cursor,
                $watermark,
                $limit
            )) ?: []);
        });
        $this->watermarkResolver = \Closure::fromCallable($watermarkResolver ?? static function (): int {
            global $wpdb;
            $table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_grants');

            return max(0, (int) \FChubMemberships\Support\CustomTableDatabase::getVar(
                \FChubMemberships\Support\CustomTableDatabase::prepare(
                    "SELECT MAX(user_id) FROM {$table} WHERE user_id > %d",
                    0,
                ),
            ));
        });
        if ($projectionQueue === null) {
            $sync = new FluentCrmSync();
            $projectionQueue = [$sync, 'queueProjection'];
        }
        $this->projectionQueue = \Closure::fromCallable($projectionQueue);
    }

    public static function registerRoutes(): void
    {
        $namespace = 'fchub-memberships/v1';

        register_rest_route($namespace, '/admin/integrations/fluentcrm/health', [
            'methods' => 'GET',
            'callback' => [self::class, 'health'],
            'permission_callback' => [self::class, 'healthPermission'],
        ]);
        register_rest_route($namespace, '/admin/integrations/fluentcrm/reconcile', [
            'methods' => 'POST',
            'callback' => [self::class, 'reconcileRoute'],
            'permission_callback' => [self::class, 'reconcileRoutePermission'],
            'args' => IntegrationHealthRestArguments::reconcile(),
        ]);
    }

    public static function healthPermission(\WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public static function reconcileRoutePermission(\WP_REST_Request $request): bool
    {
        if (!MembershipMutationPermission::check($request)) {
            return false;
        }

        return $request->get_param('scope') !== 'all' || current_user_can('manage_options');
    }

    public static function health(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->healthResponse();
    }

    public static function reconcileRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->reconcile($request);
    }

    public function healthResponse(): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $this->health->status()]);
    }

    public function reconcilePermission(\WP_REST_Request $request): bool
    {
        return self::reconcileRoutePermission($request);
    }

    public function reconcile(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) $request->get_param('user_id');
        $allUsers = $request->get_param('scope') === 'all';
        if (($userId > 0) === $allUsers) {
            return new \WP_REST_Response(['code' => 'invalid_reconciliation_scope'], 400);
        }

        $cursor = (int) $request->get_param('cursor');
        $watermarkValue = $request->get_param('watermark');
        if (!$allUsers && ($cursor !== 0 || $watermarkValue !== null)) {
            return new \WP_REST_Response(['code' => 'invalid_reconciliation_scope'], 400);
        }
        if ($allUsers && $cursor === 0 && $watermarkValue !== null) {
            return new \WP_REST_Response(['code' => 'reconciliation_watermark_not_allowed'], 400);
        }
        if ($allUsers && $cursor > 0 && $watermarkValue === null) {
            return new \WP_REST_Response(['code' => 'reconciliation_watermark_required'], 400);
        }
        $watermark = $allUsers
            ? ($watermarkValue === null ? max(0, (int) ($this->watermarkResolver)()) : (int) $watermarkValue)
            : 0;
        if ($allUsers && ($cursor < 0 || $watermark < 0 || $cursor > $watermark)) {
            return new \WP_REST_Response(['code' => 'invalid_reconciliation_cursor'], 400);
        }

        $dryRun = $this->dryRun($request->get_param('dry_run'));
        if (!$dryRun) {
            return (new IdempotentMutation())->execute(
                $request,
                'fluentcrm_reconciliation_apply',
                fn(): \WP_REST_Response => $this->reconciliationResponse(
                    $userId,
                    $allUsers,
                    false,
                    $cursor,
                    $watermark
                )
            );
        }

        return $this->reconciliationResponse($userId, $allUsers, true, $cursor, $watermark);
    }

    private function reconciliationResponse(
        int $userId,
        bool $allUsers,
        bool $dryRun,
        int $cursor = 0,
        int $watermark = 0
    ): \WP_REST_Response
    {
        if ($allUsers && !$dryRun && $cursor > 0 && !$this->health->canResume($cursor, $watermark)) {
            return new \WP_REST_Response(['code' => 'reconciliation_resume_conflict'], 409);
        }

        try {
            $page = $allUsers ? $this->reconcileAllPage($dryRun, $cursor, $watermark) : null;
            $results = $page === null ? [$this->reconcileUser($userId, $dryRun)] : $page['results'];
        } catch (\Throwable) {
            return new \WP_REST_Response(['code' => 'reconciliation_page_failed'], 500);
        }
        $processed = count($results);
        $failed = count(array_filter($results, static fn(array $result): bool => $allUsers && !$dryRun
            ? empty($result['accepted'])
            : empty($result['success'])));
        $drift = array_sum(array_column($results, 'drift'));
        $appliedDrift = array_sum(array_column($results, 'applied_drift'));
        $remainingDrift = array_sum(array_column($results, 'remaining_drift'));

        $aggregate = null;
        if (!$dryRun && $allUsers && $page !== null) {
            try {
                $aggregate = $this->health->recordPage(
                    $watermark,
                    $cursor,
                    $page['last_cursor'],
                    $page['complete'],
                    $processed,
                    $failed,
                    $remainingDrift
                );
            } catch (\Throwable) {
                return new \WP_REST_Response(['code' => 'reconciliation_summary_failed'], 500);
            }
        } elseif (!$dryRun) {
            $this->health->record($processed, $failed, $remainingDrift);
        }

        $data = [
            'dry_run' => $dryRun,
            'processed' => $processed,
            'failed' => $failed,
            'drift' => $drift,
            'applied_drift' => $appliedDrift,
            'remaining_drift' => $remainingDrift,
            'results' => $results,
        ];
        if ($page !== null) {
            $data = array_merge($data, [
                'cursor' => $cursor,
                'watermark' => $watermark,
                'next_cursor' => $page['next_cursor'],
                'complete' => $page['complete'],
            ]);
            if ($aggregate !== null) {
                $data['aggregate'] = $aggregate;
            }
        }

        return new \WP_REST_Response(['data' => $data]);
    }

    /** @return array{results:list<array<string,mixed>>,last_cursor:int,next_cursor:?int,complete:bool} */
    private function reconcileAllPage(bool $dryRun, int $cursor, int $watermark): array
    {
        $memberIds = ($this->memberIdsResolver)($cursor, $watermark, self::PAGE_SIZE + 1);
        $memberIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($memberIds) ? $memberIds : []),
            static fn(int $userId): bool => $userId > $cursor && $userId <= $watermark
        )));
        sort($memberIds, SORT_NUMERIC);
        $hasMore = count($memberIds) > self::PAGE_SIZE;
        $memberIds = array_slice($memberIds, 0, self::PAGE_SIZE);
        $results = array_map(
            fn(int $memberId): array => $dryRun
                ? $this->reconcileUser($memberId, true)
                : $this->queueUser($memberId),
            $memberIds
        );
        $lastCursor = $memberIds === [] ? $cursor : $memberIds[array_key_last($memberIds)];

        return [
            'results' => $results,
            'last_cursor' => $lastCursor,
            'next_cursor' => $hasMore ? $lastCursor : null,
            'complete' => !$hasMore,
        ];
    }

    /** @return array<string, mixed> */
    private function queueUser(int $userId): array
    {
        $preview = $this->runReconciler($userId, true);
        $errors = $this->sanitiseErrors($preview['errors'] ?? []);
        try {
            $queued = ($this->projectionQueue)($userId);
            $accepted = !empty($queued['accepted']);
        } catch (\Throwable) {
            $queued = [];
            $accepted = false;
            $errors[] = 'projection_queue_failed';
        }

        $drift = max(0, (int) ($preview['drift'] ?? 0));

        return [
            'user_id' => $userId,
            'accepted' => $accepted,
            'request_version' => $accepted ? max(1, (int) ($queued['request_version'] ?? 0)) : 0,
            'status' => $accepted ? 'pending' : 'failed',
            'scheduled' => $accepted && !empty($queued['scheduled']),
            'drift' => $drift,
            'applied_drift' => 0,
            'remaining_drift' => $drift,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return array<string, mixed> */
    private function reconcileUser(int $userId, bool $dryRun): array
    {
        $preview = $this->runReconciler($userId, true);
        $outcome = $dryRun ? null : $this->runReconciler($userId, false);
        $postflight = $dryRun ? null : $this->runReconciler($userId, true);
        $previewSuccess = !empty($preview['success']);
        $postflightSuccess = $postflight === null || !empty($postflight['success']);
        $outcomeSuccess = $outcome === null || !empty($outcome['success']);
        $errors = array_merge(
            $this->sanitiseErrors($preview['errors'] ?? []),
            $this->sanitiseErrors($outcome['errors'] ?? []),
            $this->sanitiseErrors($postflight['errors'] ?? [])
        );
        $drift = max(0, (int) ($preview['drift'] ?? 0));
        $remainingDrift = $postflight === null
            ? $drift
            : max(0, (int) ($postflight['drift'] ?? $drift));
        $appliedDrift = $postflight === null ? 0 : max(0, $drift - $remainingDrift);

        return [
            'user_id' => $userId,
            'success' => $previewSuccess && $outcomeSuccess && $postflightSuccess,
            'drift' => $drift,
            'applied_drift' => $appliedDrift,
            'remaining_drift' => $remainingDrift,
            'desired' => $this->sanitiseDesired($preview['desired'] ?? []),
            'current' => $this->sanitiseCurrent($preview['current'] ?? []),
            'postflight' => $this->sanitisePostflight($postflight),
            'outcome' => $this->sanitiseOutcome($outcome),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return array<string, mixed> */
    private function runReconciler(int $userId, bool $dryRun): array
    {
        try {
            return ($this->reconciler)($userId, $dryRun);
        } catch (\Throwable) {
            return ['success' => false, 'drift' => 0, 'errors' => ['projection_failed']];
        }
    }

    private function dryRun(mixed $value): bool
    {
        return !in_array($value, [false, 'false', '0', 0], true);
    }

    /** @return list<string> */
    private function sanitiseErrors(mixed $errors): array
    {
        if (!is_array($errors)) {
            return ['projection_failed'];
        }

        $allowed = [
            'invalid_user', 'projection_load_failed', 'contact_unavailable', 'contact_missing_id',
            'contact_resolve_failed', 'tag_resolve_failed', 'tags_read_failed', 'lists_read_failed',
            'tag_attach_failed', 'tag_attach_unconfirmed', 'tag_detach_failed', 'tag_detach_unconfirmed',
            'list_attach_failed', 'list_attach_unconfirmed', 'list_detach_failed', 'list_detach_unconfirmed',
            'custom_field_sync_failed', 'state_save_failed', 'projection_failed',
            'tag_rollback_verification_failed', 'tag_rollback_unconfirmed', 'tag_rollback_failed',
            'list_rollback_verification_failed', 'list_rollback_unconfirmed', 'list_rollback_failed',
            'custom_field_read_failed',
            'tag_compensation_verification_failed', 'tag_compensation_attach_unconfirmed',
            'tag_compensation_attach_failed', 'list_compensation_verification_failed',
            'list_compensation_attach_unconfirmed', 'list_compensation_attach_failed',
            'projection_queue_failed',
        ];

        return array_values(array_unique(array_filter(
            array_map('strval', $errors),
            static fn(string $error): bool => in_array($error, $allowed, true)
        )));
    }

    /** @return array{tag_names:list<string>, tag_ids:list<int>, list_ids:list<int>} */
    private function sanitiseDesired(mixed $desired): array
    {
        if (!is_array($desired)) {
            return ['tag_names' => [], 'tag_ids' => [], 'list_ids' => []];
        }

        return [
            'tag_names' => array_values(array_filter(
                array_map('strval', is_array($desired['tag_names'] ?? null) ? $desired['tag_names'] : []),
                static fn(string $name): bool => (bool) preg_match('/^[a-z0-9:_-]+$/', $name)
            )),
            'tag_ids' => $this->sanitiseIds($desired['tag_ids'] ?? []),
            'list_ids' => $this->sanitiseIds($desired['list_ids'] ?? []),
        ];
    }

    /** @return array{owned_tag_ids:list<int>, owned_list_ids:list<int>} */
    private function sanitiseCurrent(mixed $current): array
    {
        if (!is_array($current)) {
            return ['owned_tag_ids' => [], 'owned_list_ids' => []];
        }

        return [
            'owned_tag_ids' => $this->sanitiseIds($current['owned_tag_ids'] ?? []),
            'owned_list_ids' => $this->sanitiseIds($current['owned_list_ids'] ?? []),
        ];
    }

    /** @return list<int> */
    private function sanitiseIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return array{success:bool, degraded:bool, attached_tags:list<int>, detached_tags:list<int>, attached_lists:list<int>, detached_lists:list<int>, custom_fields:list<string>, errors:list<string>} */
    private function sanitiseOutcome(?array $outcome): array
    {
        if ($outcome === null) {
            return [
                'success' => true,
                'degraded' => false,
                'attached_tags' => [],
                'detached_tags' => [],
                'attached_lists' => [],
                'detached_lists' => [],
                'custom_fields' => [],
                'errors' => [],
            ];
        }

        $customFields = is_array($outcome['custom_fields'] ?? null) ? array_keys($outcome['custom_fields']) : [];

        return [
            'success' => !empty($outcome['success']),
            'degraded' => !empty($outcome['degraded']),
            'attached_tags' => $this->sanitiseIds($outcome['attached_tags'] ?? []),
            'detached_tags' => $this->sanitiseIds($outcome['detached_tags'] ?? []),
            'attached_lists' => $this->sanitiseIds($outcome['attached_lists'] ?? []),
            'detached_lists' => $this->sanitiseIds($outcome['detached_lists'] ?? []),
            'custom_fields' => array_values(array_filter(array_map('strval', $customFields), static fn(string $field): bool => (bool) preg_match('/^[a-z0-9_]+$/', $field))),
            'errors' => $this->sanitiseErrors($outcome['errors'] ?? []),
        ];
    }

    /** @return array{success:bool, drift:int, errors:list<string>} */
    private function sanitisePostflight(?array $postflight): array
    {
        if ($postflight === null) {
            return ['success' => true, 'drift' => 0, 'errors' => []];
        }

        return [
            'success' => !empty($postflight['success']),
            'drift' => max(0, (int) ($postflight['drift'] ?? 0)),
            'errors' => $this->sanitiseErrors($postflight['errors'] ?? []),
        ];
    }
}
