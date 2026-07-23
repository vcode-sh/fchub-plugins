<?php

use FChubMemberships\Http\Controllers\IntegrationHealthController;
use FChubMemberships\Integration\FluentCrmIntegrationHealth;
use FChubMemberships\Integration\FluentCrmSync;

defined('ABSPATH') || exit;

global $wpdb;

$userId = (int) getenv('FCHUB_TASK8_USER_ID');
$prefix = (string) getenv('FCHUB_TASK8_PREFIX');
$expectedLogin = (string) getenv('FCHUB_TASK8_LOGIN');
$expectedEmail = (string) getenv('FCHUB_TASK8_EMAIL');
$optionName = 'fchub_memberships_fluentcrm_reconciliation_health';
$route = '/fchub-memberships/v1/admin/integrations/fluentcrm/reconcile';

if ($userId <= 2 || preg_match('/^[A-Za-z0-9_-]{8,120}$/', $prefix) !== 1
    || $expectedLogin !== $prefix . '-user'
    || $expectedEmail !== $prefix . '@example.test'
) {
    throw new RuntimeException('Task 8 runtime identity is invalid.');
}

$applyKey = $prefix . '-task8-apply';
$conflictKey = $prefix . '-task8-conflict';
$groupPrefix = sprintf('fchub-memberships-crm-projection-%d-v', $userId);
$user = $wpdb->get_row($wpdb->prepare(
    "SELECT user_login, user_email FROM {$wpdb->users} WHERE ID = %d",
    $userId
), ARRAY_A);
if (!is_array($user)
    || !hash_equals($expectedLogin, (string) ($user['user_login'] ?? ''))
    || !hash_equals($expectedEmail, (string) ($user['user_email'] ?? ''))
) {
    throw new RuntimeException('Task 8 disposable user identity does not match the shell fixture.');
}
$email = $expectedEmail;

$fail = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$readOptionRow = static fn(): ?array => $wpdb->get_row($wpdb->prepare(
    "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
    $optionName
), ARRAY_A) ?: null;
$ownedCounts = static function () use ($wpdb, $userId, $prefix, $groupPrefix): array {
    $groupLike = $wpdb->esc_like($groupPrefix) . '%';

    return $wpdb->get_row($wpdb->prepare(
        "SELECT
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_crm_projection_jobs WHERE user_id = %d) AS jobs,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_mutation_requests
             WHERE user_id = %d AND request_key LIKE %s) AS receipts,
            (SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_groups WHERE slug LIKE %s) AS action_groups,
            (SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions actions
             INNER JOIN {$wpdb->prefix}actionscheduler_groups groups ON groups.group_id = actions.group_id
             WHERE groups.slug LIKE %s) AS actions,
            (SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs logs
             INNER JOIN {$wpdb->prefix}actionscheduler_actions actions ON actions.action_id = logs.action_id
             INNER JOIN {$wpdb->prefix}actionscheduler_groups groups ON groups.group_id = actions.group_id
             WHERE groups.slug LIKE %s) AS action_logs",
        $userId,
        $userId,
        $wpdb->esc_like($prefix) . '%',
        $groupLike,
        $groupLike,
        $groupLike
    ), ARRAY_A) ?: [];
};
$providerSnapshot = static function () use ($wpdb, $userId, $email): array {
    return $wpdb->get_row($wpdb->prepare(
        "SELECT
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_grants WHERE user_id = %d) AS grants,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_grant_sources sources
             INNER JOIN {$wpdb->prefix}fchub_membership_grants grants ON grants.id = sources.grant_id
             WHERE grants.user_id = %d) AS grant_sources,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_entitlement_edges WHERE user_id = %d) AS edges,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_provider_operations operations
             INNER JOIN {$wpdb->prefix}fchub_membership_entitlement_edges edges ON edges.id = operations.edge_id
             WHERE edges.user_id = %d) AS provider_operations,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_drip_notifications WHERE user_id = %d) AS drips,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_audit_log audit
             WHERE audit.actor_id = %d
                OR (audit.entity_type = 'grant' AND audit.entity_id IN (
                    SELECT id FROM {$wpdb->prefix}fchub_membership_grants WHERE user_id = %d
                ))
                OR (audit.entity_type = 'entitlement_edge' AND audit.entity_id IN (
                    SELECT id FROM {$wpdb->prefix}fchub_membership_entitlement_edges WHERE user_id = %d
                ))
                OR (audit.entity_type = 'provider_operation' AND audit.entity_id IN (
                    SELECT operations.id FROM {$wpdb->prefix}fchub_membership_provider_operations operations
                    INNER JOIN {$wpdb->prefix}fchub_membership_entitlement_edges edges ON edges.id = operations.edge_id
                    WHERE edges.user_id = %d
                ))) AS audit_rows,
            (SELECT COUNT(*) FROM {$wpdb->usermeta}
             WHERE user_id = %d AND meta_key = '_fchub_memberships_fluentcrm_projection') AS crm_ownership,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers
             WHERE user_id = %d OR email = %s) AS crm_contacts,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_meta meta
             INNER JOIN {$wpdb->prefix}fc_subscribers contacts ON contacts.id = meta.subscriber_id
             WHERE contacts.user_id = %d OR contacts.email = %s) AS crm_meta,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_pivot pivot
             INNER JOIN {$wpdb->prefix}fc_subscribers contacts ON contacts.id = pivot.subscriber_id
             WHERE contacts.user_id = %d OR contacts.email = %s) AS crm_pivot,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_notes notes
             INNER JOIN {$wpdb->prefix}fc_subscribers contacts ON contacts.id = notes.subscriber_id
             WHERE contacts.user_id = %d OR contacts.email = %s) AS crm_notes,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fc_funnel_subscribers funnels
             INNER JOIN {$wpdb->prefix}fc_subscribers contacts ON contacts.id = funnels.subscriber_id
             WHERE contacts.user_id = %d OR contacts.email = %s) AS crm_funnels",
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $email,
        $userId,
        $email,
        $userId,
        $email,
        $userId,
        $email,
        $userId,
        $email
    ), ARRAY_A) ?: [];
};
$globalSnapshot = static function () use ($wpdb): array {
    return $wpdb->get_row(
        "SELECT
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_crm_projection_jobs) AS jobs,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_mutation_requests) AS receipts,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_audit_log) AS audit_rows,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_notes) AS crm_notes,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fc_funnel_subscribers) AS crm_funnels,
            (SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions
             WHERE hook = 'fchub_memberships_process_crm_projection') AS actions,
            (SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs logs
             INNER JOIN {$wpdb->prefix}actionscheduler_actions actions ON actions.action_id = logs.action_id
             WHERE actions.hook = 'fchub_memberships_process_crm_projection') AS action_logs",
        ARRAY_A
    ) ?: [];
};
$request = static function (array $body, ?string $key = null) use ($route): WP_REST_Request {
    $request = new WP_REST_Request('POST', $route);
    $request->set_header('Content-Type', 'application/json');
    if ($key !== null) {
        $request->set_header('Idempotency-Key', $key);
    }
    $request->set_body(wp_json_encode($body, JSON_THROW_ON_ERROR));

    return $request;
};

$optionBefore = $readOptionRow();
$settingsBefore = $wpdb->get_row($wpdb->prepare(
    "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
    'fchub_memberships_settings'
), ARRAY_A) ?: null;
$providerBefore = $providerSnapshot();
$globalBefore = $globalSnapshot();
$ownedBefore = $ownedCounts();
$fail($ownedBefore === [
    'jobs' => '0',
    'receipts' => '0',
    'action_groups' => '0',
    'actions' => '0',
    'action_logs' => '0',
], 'Task 8 runtime ownership was not clean before the smoke.');

$restore = static function () use (
    $wpdb,
    $userId,
    $prefix,
    $groupPrefix,
    $optionName,
    $optionBefore,
    $readOptionRow,
    $ownedCounts,
    $providerSnapshot,
    $providerBefore,
    $globalSnapshot,
    $globalBefore,
    $settingsBefore,
    $fail
): void {
    $groupLike = $wpdb->esc_like($groupPrefix) . '%';
    $queries = [
        $wpdb->prepare(
            "DELETE logs FROM {$wpdb->prefix}actionscheduler_logs logs
             INNER JOIN {$wpdb->prefix}actionscheduler_actions actions ON actions.action_id = logs.action_id
             INNER JOIN {$wpdb->prefix}actionscheduler_groups groups ON groups.group_id = actions.group_id
             WHERE groups.slug LIKE %s",
            $groupLike
        ),
        $wpdb->prepare(
            "DELETE actions FROM {$wpdb->prefix}actionscheduler_actions actions
             INNER JOIN {$wpdb->prefix}actionscheduler_groups groups ON groups.group_id = actions.group_id
             WHERE groups.slug LIKE %s",
            $groupLike
        ),
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}actionscheduler_groups WHERE slug LIKE %s",
            $groupLike
        ),
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}fchub_membership_crm_projection_jobs WHERE user_id = %d",
            $userId
        ),
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}fchub_membership_mutation_requests
             WHERE user_id = %d AND request_key LIKE %s",
            $userId,
            $wpdb->esc_like($prefix) . '%'
        ),
    ];
    foreach ($queries as $query) {
        $fail($wpdb->query($query) !== false, 'Task 8 runtime cleanup query failed.');
    }

    if ($optionBefore === null) {
        $fail($wpdb->delete($wpdb->options, ['option_name' => $optionName]) !== false, 'Task 8 health option cleanup failed.');
    } else {
        $updated = $wpdb->update(
            $wpdb->options,
            ['option_value' => $optionBefore['option_value'], 'autoload' => $optionBefore['autoload']],
            ['option_name' => $optionName]
        );
        $fail($updated !== false, 'Task 8 health option restoration failed.');
    }
    wp_cache_delete($optionName, 'options');
    wp_cache_delete('alloptions', 'options');

    $fail($readOptionRow() === $optionBefore, 'Task 8 health option baseline was not restored exactly.');
    $fail($ownedCounts() === [
        'jobs' => '0',
        'receipts' => '0',
        'action_groups' => '0',
        'actions' => '0',
        'action_logs' => '0',
    ], 'Task 8 owned runtime rows remain after cleanup.');
    $fail($providerSnapshot() === $providerBefore, 'Task 8 provider or CRM state changed.');
    $fail($globalSnapshot() === $globalBefore, 'Task 8 global queue baseline was not restored.');
    $settingsAfter = $wpdb->get_row($wpdb->prepare(
        "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
        'fchub_memberships_settings'
    ), ARRAY_A) ?: null;
    $fail($settingsAfter === $settingsBefore, 'Task 8 settings baseline changed.');
};

$failure = null;
$cleanupFailure = null;
try {
    wp_set_current_user($userId);
    $queueCalls = 0;
    $dryHealth = new FluentCrmIntegrationHealth();
    $dryController = new IntegrationHealthController(
        $dryHealth,
        static fn(int $memberId, bool $dryRun): array => [
            'success' => $memberId === $userId && $dryRun,
            'drift' => 2,
            'errors' => [],
        ],
        static fn(int $cursor, int $watermark, int $limit): array => [$userId],
        static fn(): int => $userId,
        static function () use (&$queueCalls): array {
            $queueCalls++;
            return [];
        }
    );

    $dry = $dryController->reconcile($request(['scope' => 'all']));
    $dryData = $dry->get_data()['data'] ?? [];
    $fail($dry->get_status() === 200, 'Task 8 default dry page failed.');
    $fail(($dryData['dry_run'] ?? null) === true, 'Task 8 all-page dry default drifted.');
    $fail(($dryData['cursor'] ?? null) === 0 && ($dryData['watermark'] ?? null) === $userId, 'Task 8 server watermark drifted.');
    $fail(array_key_exists('next_cursor', $dryData) && $dryData['next_cursor'] === null && ($dryData['complete'] ?? null) === true, 'Task 8 dry page completion drifted.');
    $fail(($dryData['processed'] ?? null) === 1 && count($dryData['results'] ?? []) === 1, 'Task 8 dry page was not bounded.');
    $fail($queueCalls === 0, 'Task 8 dry page queued a projection.');
    $fail($readOptionRow() === $optionBefore && $ownedCounts() === $ownedBefore, 'Task 8 dry page mutated durable state.');

    $firstWatermark = $dryController->reconcile($request(['scope' => 'all', 'watermark' => $userId]));
    $missingWatermark = $dryController->reconcile($request(['scope' => 'all', 'cursor' => $userId - 1]));
    $pastWatermark = $dryController->reconcile($request([
        'scope' => 'all',
        'cursor' => $userId,
        'watermark' => $userId - 1,
    ]));
    $fail($firstWatermark->get_status() === 400 && ($firstWatermark->get_data()['code'] ?? '') === 'reconciliation_watermark_not_allowed', 'Task 8 accepted a caller watermark on page zero.');
    $fail($missingWatermark->get_status() === 400 && ($missingWatermark->get_data()['code'] ?? '') === 'reconciliation_watermark_required', 'Task 8 accepted a resume without a watermark.');
    $fail($pastWatermark->get_status() === 400 && ($pastWatermark->get_data()['code'] ?? '') === 'invalid_reconciliation_cursor', 'Task 8 accepted a cursor past its watermark.');
    $fail($ownedCounts() === $ownedBefore, 'Task 8 invalid cursor validation wrote durable state.');

    $seedHealth = new FluentCrmIntegrationHealth();
    $seed = $seedHealth->recordPage($userId, 0, $userId - 1, false, 100, 1, 3);
    $fail(($seed['cursor'] ?? null) === $userId - 1 && empty($seed['complete']), 'Task 8 restart seed drifted.');
    wp_cache_delete($optionName, 'options');
    wp_cache_delete('alloptions', 'options');

    $sync = new FluentCrmSync();
    $queuedUsers = [];
    $controller = new IntegrationHealthController(
        new FluentCrmIntegrationHealth(),
        static fn(int $memberId, bool $dryRun): array => [
            'success' => $memberId === $userId && $dryRun,
            'drift' => 2,
            'errors' => [],
        ],
        static function (int $cursor, int $watermark, int $limit) use ($userId): array {
            return $cursor === $userId - 1 && $watermark === $userId && $limit === 101 ? [$userId] : [];
        },
        static fn(): int => $userId,
        static function (int $memberId) use ($sync, &$queuedUsers): array {
            $queuedUsers[] = $memberId;
            return $sync->queueProjection($memberId);
        }
    );

    $conflict = $controller->reconcile($request([
        'scope' => 'all',
        'dry_run' => false,
        'cursor' => $userId - 1,
        'watermark' => $userId + 1,
    ], $conflictKey));
    $fail($conflict->get_status() === 409 && ($conflict->get_data()['code'] ?? '') === 'reconciliation_resume_conflict', 'Task 8 accepted a mismatched durable resume.');
    $fail($queuedUsers === [], 'Task 8 mismatched resume queued a projection.');

    $apply = $controller->reconcile($request([
        'scope' => 'all',
        'dry_run' => false,
        'cursor' => $userId - 1,
        'watermark' => $userId,
    ], $applyKey));
    $data = $apply->get_data()['data'] ?? [];
    $result = $data['results'][0] ?? [];
    $fail($apply->get_status() === 200, 'Task 8 resumed apply failed.');
    $fail($queuedUsers === [$userId], 'Task 8 apply did not queue exactly one disposable user.');
    $fail(($data['cursor'] ?? null) === $userId - 1 && ($data['watermark'] ?? null) === $userId, 'Task 8 resumed cursor response drifted.');
    $fail(array_key_exists('next_cursor', $data) && $data['next_cursor'] === null && ($data['complete'] ?? null) === true, 'Task 8 resumed page did not complete.');
    $fail(($data['processed'] ?? null) === 1 && ($data['failed'] ?? null) === 0, 'Task 8 resumed page counts drifted.');
    $fail(($result['accepted'] ?? null) === true && ($result['status'] ?? null) === 'pending', 'Task 8 apply did not report pending acceptance.');
    $fail(($result['scheduled'] ?? null) === true && (int) ($result['request_version'] ?? 0) === 1, 'Task 8 canonical projection schedule drifted.');
    foreach (['success', 'desired', 'current', 'postflight', 'outcome'] as $providerField) {
        $fail(!array_key_exists($providerField, $result), "Task 8 apply exposed provider field {$providerField}.");
    }

    $aggregate = $data['aggregate'] ?? [];
    $fail(($aggregate['watermark'] ?? null) === $userId && ($aggregate['cursor'] ?? null) === $userId, 'Task 8 aggregate cursor drifted.');
    $fail(($aggregate['processed'] ?? null) === 101 && ($aggregate['failed'] ?? null) === 1 && ($aggregate['drift'] ?? null) === 5, 'Task 8 aggregate did not survive restart.');
    $fail(($aggregate['complete'] ?? null) === true, 'Task 8 aggregate did not complete.');

    wp_cache_delete($optionName, 'options');
    wp_cache_delete('alloptions', 'options');
    $reloaded = (new FluentCrmIntegrationHealth())->status()['reconciliation'] ?? null;
    $fail($reloaded === $aggregate, 'Task 8 aggregate did not reload from canonical health storage.');
    $stored = maybe_unserialize((string) ($readOptionRow()['option_value'] ?? ''));
    $fail(is_array($stored) && ($stored['reconciliation'] ?? null) === $aggregate, 'Task 8 canonical summary storage drifted.');
    $fail(array_keys($stored['reconciliation']) === ['watermark', 'cursor', 'complete', 'processed', 'failed', 'drift', 'updated_at'], 'Task 8 summary stored response bodies.');

    $job = $wpdb->get_row($wpdb->prepare(
        "SELECT status, request_version, attempt_count, lease_owner, lease_expires_at, last_attempt_at
         FROM {$wpdb->prefix}fchub_membership_crm_projection_jobs WHERE user_id = %d",
        $userId
    ), ARRAY_A);
    $fail(is_array($job), 'Task 8 canonical projection job is missing.');
    $fail($job['status'] === 'pending' && (int) $job['request_version'] === 1 && (int) $job['attempt_count'] === 0, 'Task 8 projection job is not pending attempt zero.');
    $fail($job['lease_owner'] === null && $job['lease_expires_at'] === null && $job['last_attempt_at'] === null, 'Task 8 projection worker ran during the queue smoke.');

    $actionRows = $wpdb->get_results($wpdb->prepare(
        "SELECT actions.action_id, actions.status, actions.hook, groups.slug
         FROM {$wpdb->prefix}actionscheduler_actions actions
         INNER JOIN {$wpdb->prefix}actionscheduler_groups groups ON groups.group_id = actions.group_id
         WHERE groups.slug = %s AND actions.hook = %s",
        $groupPrefix . '1-a1',
        FluentCrmSync::WORKER_HOOK
    ), ARRAY_A);
    $fail(count($actionRows) === 1 && $actionRows[0]['status'] === 'pending', 'Task 8 Action Scheduler intent is not exactly one pending action.');

    $receipts = $wpdb->get_results($wpdb->prepare(
        "SELECT request_key, state, response_status, lease_token, lease_expires_at, completed_at
         FROM {$wpdb->prefix}fchub_membership_mutation_requests
         WHERE user_id = %d AND request_key IN (%s, %s) ORDER BY request_key ASC",
        $userId,
        $applyKey,
        $conflictKey
    ), ARRAY_A);
    $fail(count($receipts) === 2, 'Task 8 did not persist both owned terminal responses.');
    foreach ($receipts as $receipt) {
        $fail($receipt['state'] === 'complete' && $receipt['lease_token'] === null && $receipt['lease_expires_at'] === null && $receipt['completed_at'] !== null, 'Task 8 receipt did not terminate cleanly.');
    }
    $ownedAfter = $ownedCounts();
    $fail(
        ($ownedAfter['jobs'] ?? null) === '1'
        && ($ownedAfter['receipts'] ?? null) === '2'
        && ($ownedAfter['action_groups'] ?? null) === '1'
        && ($ownedAfter['actions'] ?? null) === '1'
        && ctype_digit((string) ($ownedAfter['action_logs'] ?? '')),
        'Task 8 produced unexpected owned queue or scheduler rows.'
    );
    $fail($providerSnapshot() === $providerBefore, 'Task 8 queue touched provider or CRM state.');

    printf(
        "Task 8 runtime audit: user_id=%d request_digest=%s aggregate_digest=%s action_id=%d\n",
        $userId,
        hash('sha256', $applyKey),
        hash('sha256', wp_json_encode($aggregate, JSON_THROW_ON_ERROR)),
        (int) $actionRows[0]['action_id']
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        $restore();
    } catch (Throwable $exception) {
        $cleanupFailure = $exception;
    }
}

if ($cleanupFailure !== null) {
    throw $cleanupFailure;
}
if ($failure !== null) {
    throw $failure;
}

echo "Task 8 reconciliation runtime smoke passed: server watermark, invalid resumes, default dry-run, pending canonical queue, restart aggregate and exact cleanup.\n";
