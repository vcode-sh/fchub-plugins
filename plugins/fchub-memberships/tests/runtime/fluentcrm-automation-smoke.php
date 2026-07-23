<?php

use FChubMemberships\FluentCRM\FluentCrmAutomation;
use FChubMemberships\FluentCRM\Filters\MembershipFilters;
use FChubMemberships\Integration\FluentCrmIntegrationHealth;
use FChubMemberships\Integration\FluentCrmSync;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Storage\FluentCrmProjectionJobRepository;

if (!defined('ABSPATH') || !defined('FLUENTCRM')) {
    fwrite(STDERR, "Run through the installed WordPress and FluentCRM runtime.\n");
    exit(1);
}

FluentCrmAutomation::boot();
$triggers = array_filter(
    apply_filters('fluentcrm_funnel_triggers', []),
    static fn(mixed $trigger, string $name): bool => str_starts_with($name, 'fchub_memberships/'),
    ARRAY_FILTER_USE_BOTH
);
if (count($triggers) !== 16 || !isset($triggers['fchub_memberships/plan_changed'])) {
    fwrite(STDERR, "Expected the 16 Memberships triggers, including plan_changed.\n");
    exit(1);
}

foreach ($triggers as $name => $trigger) {
    $details = apply_filters('fluentcrm_funnel_editor_details_' . $name, (object) [
        'settings' => [],
        'conditions' => [],
    ]);
    if (!is_object($details)) {
        fwrite(STDERR, "Editor schema did not render for {$name}.\n");
        exit(1);
    }
}

$funnel = (object) ['settings' => [], 'conditions' => []];
$blocks = apply_filters('fluentcrm_funnel_blocks', [], $funnel);
$membershipBlocks = array_filter(
    $blocks,
    static fn(array $block): bool => ($block['category'] ?? '') === 'FCHub Memberships'
);
$actions = array_filter($membershipBlocks, static fn(array $block): bool => ($block['type'] ?? '') === 'action');
$benchmarkNames = [
    'fchub_memberships/grant_created',
    'fchub_memberships/grant_expired',
    'fchub_memberships/trial_converted',
    'fchub_memberships/grant_renewed',
    'fchub_memberships/grant_resumed',
    'fchub_memberships/grant_paused',
    'fchub_memberships/grant_revoked',
];
$benchmarks = array_intersect_key($blocks, array_flip($benchmarkNames));
if (count($actions) !== 7 || count($benchmarks) !== 7) {
    fwrite(STDERR, sprintf("Expected 7 actions and 7 benchmarks; found %d and %d.\n", count($actions), count($benchmarks)));
    exit(1);
}
$blockFields = apply_filters('fluentcrm_funnel_block_fields', [], $funnel);
foreach (array_merge(array_keys($actions), array_keys($benchmarks)) as $name) {
    if (!isset($blockFields[$name])) {
        fwrite(STDERR, "Block editor schema did not render for {$name}.\n");
        exit(1);
    }
}

$smartCodeGroups = apply_filters('fluent_crm_funnel_context_smart_codes', [], '');
$membershipSmartCodes = array_values(array_filter($smartCodeGroups, static fn(array $group): bool => ($group['key'] ?? '') === 'membership'));
if (count($membershipSmartCodes) !== 1 || count($membershipSmartCodes[0]['shortcodes'] ?? []) !== 25) {
    fwrite(STDERR, "Expected the Membership smart-code group with 25 values.\n");
    exit(1);
}

$filterGroups = apply_filters('fluentcrm_advanced_filter_options', []);
$membershipFilters = $filterGroups['fchub_memberships']['children'] ?? [];
if (count($membershipFilters) !== 6) {
    fwrite(STDERR, "Expected all 6 Membership contact filters.\n");
    exit(1);
}
$filterCases = [
    ['property' => 'fchub_has_membership', 'operator' => 'exist', 'value' => []],
    ['property' => 'fchub_membership_status', 'operator' => 'exist', 'value' => 'active'],
    ['property' => 'fchub_days_until_expiry', 'operator' => '>=', 'value' => 0],
    ['property' => 'fchub_renewal_count', 'operator' => '>=', 'value' => 0],
    ['property' => 'fchub_member_duration', 'operator' => '>=', 'value' => 0],
    ['property' => 'fchub_in_trial', 'operator' => 'exist', 'value' => ''],
];
foreach ($filterCases as $filter) {
    $query = MembershipFilters::applyFilters(\FluentCrm\App\Models\Subscriber::query(), [$filter]);
    $query->limit(1)->get();
}

$token = 'fchub-smoke-' . wp_generate_uuid4();
$email = $token . '@example.test';
$userId = 0;
$postId = 0;
$planId = 0;
$ruleId = 0;
$grantIds = [];
$edgeIds = [];
$operationIds = [];
$dripIds = [];
$sourceRowIds = [];
$auditIds = [];
$contactId = 0;
$actionIds = [];
$actionLogIds = [];
$actionGroupIds = [];
$workerGroup = '';
$preExistingTagIds = [];
$createdTagRows = [];
$failure = null;
global $wpdb;
$settingsRaw = static function () use ($wpdb): ?string {
    $stored = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        'fchub_memberships_settings'
    ));
    return $stored === null ? null : (string) $stored;
};
$settingsRawBefore = $settingsRaw();
$settingsHashBefore = hash('sha256', (string) $settingsRawBefore);
$settingsLengthBefore = strlen((string) $settingsRawBefore);
$countQueries = [
    'users' => "SELECT COUNT(*) FROM {$wpdb->users}",
    'usermeta' => "SELECT COUNT(*) FROM {$wpdb->usermeta}",
    'plans' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_plans",
    'rules' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_plan_rules",
    'grants' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_grants",
    'sources' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_grant_sources",
    'audit' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_audit_log",
    'stats' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_stats_daily",
    'drips' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_drip_notifications",
    'validity' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_validity_log",
    'edges' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_entitlement_edges",
    'operations' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_provider_operations",
    'crm_projection_jobs' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_crm_projection_jobs",
    'crm_subscribers' => "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers",
    'crm_subscriber_meta' => "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_meta",
    'crm_pivots' => "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_pivot",
    'crm_tags' => "SELECT COUNT(*) FROM {$wpdb->prefix}fc_tags",
    'as_actions' => "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions",
    'as_logs' => "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs",
    'as_groups' => "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_groups",
];
$snapshotCounts = static function () use ($wpdb, $countQueries): array {
    $counts = [];
    foreach ($countQueries as $key => $query) {
        $counts[$key] = (int) $wpdb->get_var($query);
    }
    return $counts;
};
$countsBefore = $snapshotCounts();
if ($countsBefore['edges'] !== 0 || $countsBefore['operations'] !== 0) {
    fwrite(STDERR, "STOP: entitlement edge/provider operation baseline is not 0/0.\n");
    exit(1);
}
$settingsOwnedValues = [
    'membership_mode' => 'stack',
    'email_access_granted' => 'no',
    'email_access_revoked' => 'no',
    'email_membership_paused' => 'no',
    'email_membership_resumed' => 'no',
    'fluentcrm_enabled' => 'yes',
    'fluentcrm_default_list' => '',
    'fluentcrm_auto_create_tags' => 'yes',
    'fluentcrm_tag_prefix' => $token . ':',
];
$settingsOriginals = [];
$ownedTagTitles = [
    $token . ':' . $token,
    $token . ':paused',
    $token . ':revoked',
];

$countSyncCallbacks = static function (string $hook): int {
    global $wp_filter;

    $registered = $wp_filter[$hook] ?? null;
    if (!$registered instanceof WP_Hook) {
        return 0;
    }

    $count = 0;
    foreach ($registered->callbacks as $callbacks) {
        foreach ($callbacks as $callback) {
            $function = $callback['function'] ?? null;
            if (is_array($function)
                && is_object($function[0] ?? null)
                && $function[0] instanceof FluentCrmSync
            ) {
                $count++;
            }
        }
    }

    return $count;
};
$lifecycleHooks = [
    'fchub_memberships/grant_created',
    'fchub_memberships/grant_revoked',
    'fchub_memberships/grant_paused',
    'fchub_memberships/grant_resumed',
    'fchub_memberships/grant_expired',
    'fchub_memberships/grant_renewed',
    FluentCrmSync::WORKER_HOOK,
];
$lifecycleSync = null;

try {
    $settingsSetup = (new MembershipSettingsOptionCoordinator())->mutate(static function (array $settings) use (
        &$settingsOriginals,
        $settingsOwnedValues
    ): array {
        foreach ($settingsOwnedValues as $key => $value) {
            $settingsOriginals[$key] = [
                'existed' => array_key_exists($key, $settings),
                'value' => $settings[$key] ?? null,
            ];
            $settings[$key] = $value;
        }
        return $settings;
    });
    if (!$settingsSetup['success']) {
        throw new RuntimeException('The smoke-owned Memberships settings could not be saved.');
    }

    if ($countSyncCallbacks('fchub_memberships/grant_created') === 0) {
        $lifecycleSync = new FluentCrmSync();
        $lifecycleSync->register();
        $lifecycleSync->register();
    }
    foreach ($lifecycleHooks as $hook) {
        if ($countSyncCallbacks($hook) !== 1) {
            throw new RuntimeException("Expected exactly one FluentCRM lifecycle callback for {$hook}.");
        }
    }

    $tagPlaceholders = implode(',', array_fill(0, count($ownedTagTitles), '%s'));
    $preExistingTagIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}fc_tags WHERE title IN ({$tagPlaceholders})",
        ...$ownedTagTitles
    )));

    $userId = wp_insert_user(['user_login' => $token, 'user_email' => $email, 'user_pass' => wp_generate_password(24)]);
    if (is_wp_error($userId)) {
        throw new RuntimeException('Disposable WordPress user could not be created.');
    }
    $postId = wp_insert_post(['post_title' => $token, 'post_type' => 'post', 'post_status' => 'private'], true);
    if (is_wp_error($postId)) {
        throw new RuntimeException('Disposable protected resource could not be created.');
    }
    $planId = (new \FChubMemberships\Storage\PlanRepository())->create([
        'title' => $token,
        'slug' => $token,
        'status' => 'active',
        'duration_type' => 'lifetime',
        'trial_days' => 0,
        'grace_period_days' => 0,
        'meta' => [],
    ]);
    if ($planId <= 0) {
        throw new RuntimeException('Disposable membership plan could not be created.');
    }
    $ruleId = (new \FChubMemberships\Storage\PlanRuleRepository())->create([
        'plan_id' => $planId,
        'provider' => 'wordpress_core',
        'resource_type' => 'post',
        'resource_id' => (string) $postId,
        'drip_type' => 'immediate',
    ]);
    if ($ruleId <= 0) {
        throw new RuntimeException('Disposable membership rule could not be created.');
    }

    $access = new \FChubMemberships\Domain\AccessGrantService();
    $first = $access->manualGrant((int) $userId, $planId);
    $renewal = $access->manualGrant((int) $userId, $planId);
    if (($first['created'] ?? 0) !== 1 || ($renewal['updated'] ?? 0) !== 1) {
        throw new RuntimeException('Disposable membership did not grant and renew exactly once.');
    }
    $grantRepository = new \FChubMemberships\Storage\GrantRepository();
    $grants = $grantRepository->getByUserId((int) $userId, ['plan_id' => $planId]);
    $grantIds = array_map('intval', array_column($grants, 'id'));
    $grantId = $grantIds[0] ?? 0;
    if ($grantId <= 0 || count($grants) !== 1 || (int) ($grants[0]['renewal_count'] ?? 0) !== 0) {
        throw new RuntimeException('Disposable idempotent replay repository truth was not visible.');
    }
    $projectionJobs = new FluentCrmProjectionJobRepository();
    $renewedJob = $projectionJobs->find((int) $userId);
    if (($renewedJob['status'] ?? null) !== 'succeeded'
        || (int) ($renewedJob['request_version'] ?? 0) < 2
        || empty($renewedJob['last_success_at'])
    ) {
        throw new RuntimeException('Grant/renew lifecycle projection job was not durably successful.');
    }
    $renewedVersion = (int) $renewedJob['request_version'];
    if (empty($access->pauseGrant($grantId, 'runtime smoke')['success'])) {
        throw new RuntimeException('Disposable membership could not be paused.');
    }
    $pausedJob = $projectionJobs->find((int) $userId);
    if (($pausedJob['status'] ?? null) !== 'succeeded'
        || (int) ($pausedJob['request_version'] ?? 0) <= $renewedVersion
    ) {
        throw new RuntimeException('Pause lifecycle projection job was not durably successful.');
    }
    if (empty($access->resumeGrant($grantId)['success'])) {
        throw new RuntimeException('Disposable membership could not be resumed.');
    }
    $resumedJob = $projectionJobs->find((int) $userId);
    if (($resumedJob['status'] ?? null) !== 'succeeded'
        || (int) ($resumedJob['request_version'] ?? 0) <= (int) ($pausedJob['request_version'] ?? 0)
    ) {
        throw new RuntimeException('Resume lifecycle projection job was not durably successful.');
    }
    $projection = (new \FChubMemberships\FluentCRM\Projection\MembershipContactProjector())->reconcile((int) $userId);
    if (empty($projection['success'])) {
        throw new RuntimeException('Disposable FluentCRM contact projection failed: ' . implode(',', $projection['errors'] ?? []));
    }
    $contact = \FluentCrm\App\Models\Subscriber::where('email', $email)->first();
    $contactId = (int) ($contact->id ?? 0);
    if ($contactId <= 0) {
        throw new RuntimeException('Disposable FluentCRM contact projection was not persisted.');
    }
    $revoke = $access->revokePlan((int) $userId, $planId, ['reason' => 'runtime smoke cleanup']);
    if (empty($revoke['success']) || (int) ($revoke['revoked'] ?? 0) !== 1) {
        throw new RuntimeException('Disposable membership could not be revoked.');
    }
    $revokedJob = $projectionJobs->find((int) $userId);
    if (($revokedJob['status'] ?? null) !== 'succeeded'
        || (int) ($revokedJob['request_version'] ?? 0) <= (int) ($resumedJob['request_version'] ?? 0)
    ) {
        throw new RuntimeException('Revoke lifecycle projection job was not durably successful.');
    }

    $failureSync = new FluentCrmSync(
        static fn(int $requestedUserId): array => [
            'success' => false,
            'degraded' => false,
            'errors' => ['contact_resolve_failed'],
        ],
        $projectionJobs,
        static fn(int $requestedUserId): array => ['success' => false, 'drift' => 1, 'errors' => []]
    );
    $failureSync->onGrantCreated((int) $userId, $planId);
    $failedJob = $projectionJobs->find((int) $userId);
    $workerGroup = sprintf(
        'fchub-memberships-crm-projection-%d-v%d-a2',
        $userId,
        (int) ($failedJob['request_version'] ?? 0)
    );
    if (($failedJob['status'] ?? null) !== 'pending'
        || (int) ($failedJob['attempt_count'] ?? 0) !== 1
        || ($failedJob['last_error_code'] ?? null) !== 'projection_contact_failed'
        || empty($failedJob['next_retry_at'])
    ) {
        throw new RuntimeException('Returned CRM projection failure was not durably scheduled.');
    }

    $actionRows = $wpdb->get_results($wpdb->prepare(
        "SELECT action.action_id, action.group_id
         FROM {$wpdb->prefix}actionscheduler_actions action
         INNER JOIN {$wpdb->prefix}actionscheduler_groups action_group
            ON action_group.group_id = action.group_id
         WHERE action.hook = %s AND action_group.slug = %s",
        FluentCrmSync::WORKER_HOOK,
        $workerGroup
    ), ARRAY_A);
    $actionIds = array_values(array_unique(array_map('intval', array_column($actionRows, 'action_id'))));
    $actionGroupIds = array_values(array_unique(array_map('intval', array_column($actionRows, 'group_id'))));
    if (count($actionRows) !== 1) {
        throw new RuntimeException('The exact CRM retry worker action was not scheduled once.');
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}fchub_membership_crm_projection_jobs
         SET next_retry_at = %s WHERE user_id = %d AND request_version = %d AND status = 'pending'",
        current_time('mysql'),
        $userId,
        (int) $failedJob['request_version']
    ));
    (new FluentCrmSync())->processProjectionJob(
        (int) $userId,
        (int) $failedJob['request_version'],
        2
    );
    $recoveredJob = $projectionJobs->find((int) $userId);
    if (($recoveredJob['status'] ?? null) !== 'succeeded'
        || (int) ($recoveredJob['attempt_count'] ?? 0) !== 2
        || !empty($recoveredJob['last_error_code'])
        || empty($recoveredJob['last_success_at'])
    ) {
        throw new RuntimeException('CRM projection retry did not recover with a verified postflight.');
    }
    $health = (new FluentCrmIntegrationHealth())->status();
    if (($health['projection_jobs_readable'] ?? false) !== true
        || (int) ($health['pending_projections'] ?? -1) !== 0
        || (int) ($health['failed_projections'] ?? -1) !== 0
        || empty($health['last_successful_projection'])
    ) {
        throw new RuntimeException('CRM projection health did not reflect successful recovery.');
    }
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($userId > 0) {
        $grantIds = array_values(array_unique(array_merge(
            $grantIds,
            array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fchub_membership_grants WHERE user_id = %d",
                $userId
            )))
        )));
        $edgeIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fchub_membership_entitlement_edges WHERE user_id = %d",
            $userId
        )));
    }
    if ($edgeIds !== []) {
        $edgeList = implode(',', $edgeIds);
        $operationIds = array_map('intval', $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fchub_membership_provider_operations WHERE edge_id IN ({$edgeList})"
        ));
    }
    if ($grantIds !== []) {
        $grantList = implode(',', $grantIds);
        $dripIds = array_map('intval', $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fchub_membership_drip_notifications WHERE grant_id IN ({$grantList})"
        ));
        $sourceRowIds = array_map('intval', $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fchub_membership_grant_sources WHERE grant_id IN ({$grantList})"
        ));
        $auditIds = array_map('intval', $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fchub_membership_audit_log
             WHERE entity_type = 'grant' AND entity_id IN ({$grantList})"
        ));
    }

    $settingsCleanupFailure = null;
    $settingsConflicts = [];
    if ($settingsOriginals !== []) {
        $settingsRestore = (new MembershipSettingsOptionCoordinator())->mutate(static function (array $settings) use (
            $settingsOriginals,
            $settingsOwnedValues,
            &$settingsConflicts
        ): array {
            foreach ($settingsOwnedValues as $key => $ownedValue) {
                if (!array_key_exists($key, $settings) || $settings[$key] !== $ownedValue) {
                    $settingsConflicts[] = $key;
                    continue;
                }

                if ($settingsOriginals[$key]['existed']) {
                    $settings[$key] = $settingsOriginals[$key]['value'];
                } else {
                    unset($settings[$key]);
                }
            }
            return $settings;
        });
        if (!$settingsRestore['success']) {
            $settingsCleanupFailure = 'The smoke-owned Memberships settings could not be restored after rollback.';
        } elseif ($settingsConflicts !== []) {
            $settingsCleanupFailure = 'Concurrent Memberships settings were preserved after rollback: '
                . implode(', ', $settingsConflicts);
        }
    }

    $tagPlaceholders = implode(',', array_fill(0, count($ownedTagTitles), '%s'));
    $tagRows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title FROM {$wpdb->prefix}fc_tags WHERE title IN ({$tagPlaceholders})",
        ...$ownedTagTitles
    ), ARRAY_A);
    $createdTagRows = array_values(array_filter(
        $tagRows,
        static fn(array $row): bool => !in_array((int) $row['id'], $preExistingTagIds, true)
            && in_array((string) $row['title'], $ownedTagTitles, true)
    ));

    if ($contactId <= 0) {
        $contactId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_subscribers WHERE email = %s LIMIT 1",
            $email
        ));
    }
    if ($contactId > 0) {
        $wpdb->delete($wpdb->prefix . 'fc_subscriber_pivot', ['subscriber_id' => $contactId]);
        $wpdb->delete($wpdb->prefix . 'fc_subscriber_meta', ['subscriber_id' => $contactId]);
        $wpdb->delete($wpdb->prefix . 'fc_subscribers', ['id' => $contactId]);
    }
    foreach ($createdTagRows as $tagRow) {
        $tagId = (int) $tagRow['id'];
        $pivotCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_pivot WHERE object_id = %d AND object_type = %s",
            $tagId,
            'FluentCrm\\App\\Models\\Tag'
        ));
        if ($pivotCount !== 0) {
            $failure ??= new RuntimeException("Smoke-owned FluentCRM tag {$tagId} retained a non-smoke pivot.");
            continue;
        }
        $wpdb->delete($wpdb->prefix . 'fc_tags', [
            'id' => $tagId,
            'title' => (string) $tagRow['title'],
        ]);
    }
    if ($userId > 0) {
        $groupPattern = $wpdb->esc_like(sprintf(
            'fchub-memberships-crm-projection-%d-v',
            $userId
        )) . '%';
        $ownedActionRows = $wpdb->get_results($wpdb->prepare(
            "SELECT action.action_id, action.group_id
             FROM {$wpdb->prefix}actionscheduler_actions action
             INNER JOIN {$wpdb->prefix}actionscheduler_groups action_group
                ON action_group.group_id = action.group_id
             WHERE action.hook = %s AND action_group.slug LIKE %s",
            FluentCrmSync::WORKER_HOOK,
            $groupPattern
        ), ARRAY_A);
        $actionIds = array_values(array_unique(array_merge(
            $actionIds,
            array_map('intval', array_column($ownedActionRows, 'action_id'))
        )));
        $actionGroupIds = array_values(array_unique(array_merge(
            $actionGroupIds,
            array_map('intval', array_column($ownedActionRows, 'group_id')),
            array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug LIKE %s",
                $groupPattern
            )))
        )));
    }
    if ($actionIds !== []) {
        $actionList = implode(',', array_map('intval', $actionIds));
        $actionLogIds = array_map('intval', $wpdb->get_col(
            "SELECT log_id FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ({$actionList})"
        ));
    }
    if ($actionLogIds !== []) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE log_id IN ("
            . implode(',', $actionLogIds) . ')'
        );
    }
    if ($actionIds !== []) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ("
            . implode(',', $actionIds) . ')'
        );
    }
    if ($actionGroupIds !== []) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}actionscheduler_groups WHERE group_id IN ("
            . implode(',', $actionGroupIds) . ')'
        );
    }
    if ($userId > 0) {
        $wpdb->delete($wpdb->prefix . 'fchub_membership_crm_projection_jobs', ['user_id' => $userId]);
    }
    if ($operationIds !== []) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fchub_membership_provider_operations WHERE id IN ("
            . implode(',', $operationIds) . ')'
        );
    }
    if ($edgeIds !== []) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fchub_membership_entitlement_edges WHERE id IN ("
            . implode(',', $edgeIds) . ')'
        );
    }
    if ($dripIds !== []) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fchub_membership_drip_notifications WHERE id IN ("
            . implode(',', $dripIds) . ')'
        );
    }
    if ($sourceRowIds !== []) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fchub_membership_grant_sources WHERE id IN ("
            . implode(',', $sourceRowIds) . ')'
        );
    }
    if ($auditIds !== []) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fchub_membership_audit_log WHERE id IN ("
            . implode(',', $auditIds) . ')'
        );
    }
    if ($grantIds !== []) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fchub_membership_grants WHERE id IN ("
            . implode(',', $grantIds) . ')'
        );
    }
    if ($userId > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (get_userdata($userId) && !wp_delete_user($userId)) {
            $failure ??= new RuntimeException('Disposable WordPress user could not be deleted.');
        }
    }
    if ($ruleId > 0) {
        $wpdb->delete($wpdb->prefix . 'fchub_membership_plan_rules', ['id' => $ruleId]);
    }
    if ($planId > 0) {
        $wpdb->delete($wpdb->prefix . 'fchub_membership_plans', ['id' => $planId]);
    }
    if ($postId > 0) {
        wp_delete_post($postId, true);
    }
    if ($settingsCleanupFailure !== null) {
        $failure ??= new RuntimeException($settingsCleanupFailure);
    }
}

$countsAfter = $snapshotCounts();
if ($countsAfter !== $countsBefore) {
    $differences = [];
    foreach ($countsBefore as $key => $before) {
        if (($countsAfter[$key] ?? null) !== $before) {
            $differences[] = "{$key}:{$before}->" . ($countsAfter[$key] ?? 'missing');
        }
    }
    $failure ??= new RuntimeException('FluentCRM smoke counts did not return to baseline: ' . implode(', ', $differences));
}
$settingsRawAfter = $settingsRaw();
if (hash('sha256', (string) $settingsRawAfter) !== $settingsHashBefore
    || strlen((string) $settingsRawAfter) !== $settingsLengthBefore
) {
    $failure ??= new RuntimeException('Memberships settings hash/length did not return to baseline.');
}
$residue = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = %s OR user_email = %s",
    $token,
    $email
));
$residue += (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s",
    $token
));
$residue += $userId > 0 ? (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_crm_projection_jobs WHERE user_id = %d",
    $userId
)) : 0;
$residue += $workerGroup !== '' ? (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s",
    $workerGroup
)) : 0;
$residue += (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers WHERE email = %s",
    $email
));
$tagPlaceholders = implode(',', array_fill(0, count($ownedTagTitles), '%s'));
$residue += (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_tags WHERE title IN ({$tagPlaceholders}) AND id NOT IN ("
    . ($preExistingTagIds === [] ? '0' : implode(',', array_map('intval', $preExistingTagIds)))
    . ')',
    ...$ownedTagTitles
));
if ($contactId > 0) {
    $residue += (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_meta WHERE subscriber_id = %d",
        $contactId
    ));
    $residue += (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_pivot WHERE subscriber_id = %d",
        $contactId
    ));
}
if ($createdTagRows !== []) {
    $createdTagIds = array_map(static fn(array $row): int => (int) $row['id'], $createdTagRows);
    $createdTagList = implode(',', $createdTagIds);
    $residue += (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fc_tags WHERE id IN ({$createdTagList})"
    );
    $residue += (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_pivot
         WHERE object_id IN ({$createdTagList}) AND object_type = 'FluentCrm\\\\App\\\\Models\\\\Tag'"
    );
}
if ($workerGroup !== '') {
    $residue += (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$wpdb->prefix}actionscheduler_actions action
         INNER JOIN {$wpdb->prefix}actionscheduler_groups action_group
            ON action_group.group_id = action.group_id
         WHERE action.hook = %s AND action_group.slug = %s",
        FluentCrmSync::WORKER_HOOK,
        $workerGroup
    ));
}
if ($userId > 0) {
    $groupPattern = $wpdb->esc_like(sprintf(
        'fchub-memberships-crm-projection-%d-v',
        $userId
    )) . '%';
    $residue += (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_groups WHERE slug LIKE %s",
        $groupPattern
    ));
    $residue += (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$wpdb->prefix}actionscheduler_logs action_log
         INNER JOIN {$wpdb->prefix}actionscheduler_actions action
            ON action.action_id = action_log.action_id
         INNER JOIN {$wpdb->prefix}actionscheduler_groups action_group
            ON action_group.group_id = action.group_id
         WHERE action.hook = %s AND action_group.slug LIKE %s",
        FluentCrmSync::WORKER_HOOK,
        $groupPattern
    ));
}
if ($residue !== 0) {
    $failure ??= new RuntimeException('Disposable FluentCRM smoke token residue remained after cleanup.');
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}

echo "FluentCRM automation smoke passed: 16 triggers, 7 actions, 7 benchmarks, 25 smart codes, 6 executable filters, every editor schema, hook-once lifecycle jobs, durable failure/retry/health, exact cleanup, and restored settings/count baselines.\n";
