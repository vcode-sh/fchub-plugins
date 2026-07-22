<?php

declare(strict_types=1);

namespace FChubMemberships\Http\Controllers;

use FChubMemberships\FluentCRM\Projection\MembershipContactProjector;
use FChubMemberships\Http\MembershipMutationPermission;
use FChubMemberships\Http\IntegrationHealthRestArguments;
use FChubMemberships\Integration\FluentCrmIntegrationHealth;

defined('ABSPATH') || exit;

final class IntegrationHealthController
{
    private const PAGE_SIZE = 100;

    /** @var \Closure(int, bool): array<string, mixed> */
    private \Closure $reconciler;
    /** @var \Closure(int, int): array */
    private \Closure $memberIdsResolver;

    public function __construct(
        private FluentCrmIntegrationHealth $health = new FluentCrmIntegrationHealth(),
        ?callable $reconciler = null,
        ?callable $usersResolver = null
    ) {
        $this->reconciler = \Closure::fromCallable($reconciler ?? static function (int $userId, bool $dryRun): array {
            $projector = new MembershipContactProjector();

            return $dryRun ? $projector->preview($userId) : $projector->reconcile($userId);
        });
        $this->memberIdsResolver = \Closure::fromCallable($usersResolver ?? static function (int $offset, int $limit): array {
            global $wpdb;
            $table = $wpdb->prefix . 'fchub_membership_grants';

            return array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$table} WHERE user_id > 0 ORDER BY user_id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )) ?: []);
        });
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

        $dryRun = $this->dryRun($request->get_param('dry_run'));
        $results = $allUsers ? $this->reconcileAll($dryRun) : [$this->reconcileUser($userId, $dryRun)];
        $processed = count($results);
        $failed = count(array_filter($results, static fn(array $result): bool => !$result['success']));
        $drift = array_sum(array_column($results, 'drift'));
        $appliedDrift = array_sum(array_column($results, 'applied_drift'));
        $remainingDrift = array_sum(array_column($results, 'remaining_drift'));

        if (!$dryRun) {
            $this->health->record($processed, $failed, $remainingDrift);
        }

        return new \WP_REST_Response(['data' => [
            'dry_run' => $dryRun,
            'processed' => $processed,
            'failed' => $failed,
            'drift' => $drift,
            'applied_drift' => $appliedDrift,
            'remaining_drift' => $remainingDrift,
            'results' => $results,
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private function reconcileAll(bool $dryRun): array
    {
        $offset = 0;
        $results = [];

        do {
            $memberIds = ($this->memberIdsResolver)($offset, self::PAGE_SIZE);
            foreach ($memberIds as $userId) {
                $userId = (int) $userId;
                if ($userId > 0) {
                    $results[] = $this->reconcileUser($userId, $dryRun);
                }
            }
            $offset += count($memberIds);
        } while (count($memberIds) === self::PAGE_SIZE);

        return $results;
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

    /** @return array{success:bool, attached_tags:list<int>, detached_tags:list<int>, attached_lists:list<int>, detached_lists:list<int>, custom_fields:list<string>, errors:list<string>} */
    private function sanitiseOutcome(?array $outcome): array
    {
        if ($outcome === null) {
            return [
                'success' => true,
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
