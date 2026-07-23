<?php

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Drip\DripScheduleService;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Http\AccountController;
use FChubMemberships\Integration\Community\CommunityMemberContext;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Support\Clock;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Modules\Course\Services\CourseHelper;

if (!defined('ABSPATH')
    || !defined('FLUENT_COMMUNITY_PLUGIN_VERSION')
    || !class_exists(BaseSpace::class)
    || !class_exists(Helper::class)
    || !class_exists(CourseHelper::class)
) {
    fwrite(STDERR, "Run through the mounted WordPress runtime with FluentCommunity active.\n");
    exit(1);
}

global $wpdb;

$spaceId = 2;
$courseId = 5;
$spaceTable = $wpdb->prefix . 'fcom_spaces';
$pivotTable = $wpdb->prefix . 'fcom_space_user';
$edgeTable = $wpdb->prefix . 'fchub_membership_entitlement_edges';
$operationTable = $wpdb->prefix . 'fchub_membership_provider_operations';
$actionTable = $wpdb->prefix . 'actionscheduler_actions';
$actionLogTable = $wpdb->prefix . 'actionscheduler_logs';
$settingsOptionName = 'fchub_memberships_settings';
$token = 'fchub-entitlement-smoke-' . wp_generate_uuid4();
$tokenDigest = hash('sha256', $token);
$email = $token . '@example.test';
$clock = new Clock();
$userId = 0;
$planIds = [];
$ruleIds = [];
$grantIds = [];
$edgeIds = [];
$operationIds = [];
$actionIds = [];
$actionLogIds = [];
$dripIds = [];
$sourceRowIds = [];
$auditIds = [];
$failure = null;
$settingsChanged = false;
$settingsWritten = null;
$previousUserId = get_current_user_id();

$fail = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tableExists = static function (string $table) use ($wpdb): bool {
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
};

$settingsRaw = static function () use ($wpdb, $settingsOptionName): ?string {
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $settingsOptionName
    ));
    return $value === null ? null : (string) $value;
};

$settingsBefore = get_option($settingsOptionName, null);
if (!is_array($settingsBefore)) {
    fwrite(STDERR, "An existing array Memberships settings option is required for the disposable smoke.\n");
    exit(1);
}
$settingsRawBefore = $settingsRaw();
$settingsHashBefore = hash('sha256', (string) $settingsRawBefore);
$settingsLengthBefore = strlen((string) $settingsRawBefore);

$spaceRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$spaceTable} WHERE id = %d", $spaceId), ARRAY_A);
$courseRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$spaceTable} WHERE id = %d", $courseId), ARRAY_A);
if (!is_array($spaceRow)
    || ($spaceRow['type'] ?? '') !== 'community'
    || ($spaceRow['status'] ?? '') !== 'published'
    || !is_array($courseRow)
    || ($courseRow['type'] ?? '') !== 'course'
    || ($courseRow['status'] ?? '') !== 'published'
) {
    fwrite(STDERR, "Published FluentCommunity space #2 and course #5 are required.\n");
    exit(1);
}
$spaceHashBefore = hash('sha256', wp_json_encode($spaceRow));
$courseHashBefore = hash('sha256', wp_json_encode($courseRow));

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
    'protection' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_protection_rules",
    'event_locks' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_event_locks",
    'mutation_requests' => "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_mutation_requests",
    'edges' => "SELECT COUNT(*) FROM {$edgeTable}",
    'operations' => "SELECT COUNT(*) FROM {$operationTable}",
    'community_memberships' => "SELECT COUNT(*) FROM {$pivotTable}",
    'community_profiles' => "SELECT COUNT(*) FROM {$wpdb->prefix}fcom_xprofile",
    'crm_subscribers' => "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers",
    'crm_pivots' => "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_pivot",
    'provider_actions' => "SELECT COUNT(*) FROM {$actionTable} WHERE hook = 'fchub_memberships_process_provider_operation'",
    'provider_action_logs' => "SELECT COUNT(*) FROM {$actionLogTable} logs INNER JOIN {$actionTable} actions ON actions.action_id = logs.action_id WHERE actions.hook = 'fchub_memberships_process_provider_operation'",
];
$snapshotCounts = static function () use ($wpdb, $countQueries, $tableExists): array {
    $counts = [];
    foreach ($countQueries as $key => $query) {
        if (preg_match('/FROM\s+(`?[^\s`]+`?)/i', $query, $match) === 1
            && !$tableExists(trim($match[1], '`'))
        ) {
            $counts[$key] = null;
            continue;
        }
        $counts[$key] = (int) $wpdb->get_var($query);
    }
    return $counts;
};

$countsBefore = $snapshotCounts();
if (($countsBefore['edges'] ?? -1) !== 0 || ($countsBefore['operations'] ?? -1) !== 0) {
    fwrite(STDERR, "STOP: entitlement edge/provider operation baseline is not 0/0.\n");
    exit(1);
}

$memberCountsBefore = [
    $spaceId => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pivotTable} WHERE space_id = %d", $spaceId)),
    $courseId => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pivotTable} WHERE space_id = %d", $courseId)),
];

try {
    $space = BaseSpace::withoutGlobalScopes()->find($spaceId);
    $course = BaseSpace::withoutGlobalScopes()->find($courseId);
    $fail($space && $space->type === 'community', 'Installed space #2 did not resolve as a community.');
    $fail($course && $course->type === 'course', 'Installed space #5 did not resolve as a course.');

    $settingsOwnedValues = [
        'fc_enabled' => 'yes',
        'membership_mode' => 'stack',
        'email_access_granted' => 'no',
        'email_access_revoked' => 'no',
        'email_membership_paused' => 'no',
        'email_membership_resumed' => 'no',
        'email_drip_unlocked' => 'no',
    ];
    $settingsResult = (new MembershipSettingsOptionCoordinator())->synchronized(
        static function (MembershipSettingsOptionCoordinator $coordinator) use (
            $settingsBefore,
            $settingsOwnedValues,
            &$settingsWritten
        ): array {
            $current = $coordinator->read();
            if ($current !== $settingsBefore) {
                throw new RuntimeException('Memberships settings changed before smoke setup.');
            }
            $settingsWritten = array_merge($current, $settingsOwnedValues);
            return $coordinator->compareAndSwap($current, $settingsWritten);
        }
    );
    $settingsChanged = is_array($settingsWritten) && $settingsWritten !== $settingsBefore;
    $fail(
        !empty($settingsResult['success']) && !empty($settingsResult['value']['success']),
        'The smoke-owned Memberships settings could not be saved with compare-and-swap.'
    );

    $userId = wp_insert_user([
        'user_login' => $token,
        'user_email' => $email,
        'user_pass' => wp_generate_password(32, true, true),
        'role' => 'subscriber',
    ]);
    $fail(!is_wp_error($userId) && (int) $userId > 0, 'The disposable WordPress user could not be created.');
    $userId = (int) $userId;
    $fail(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$pivotTable} WHERE user_id = %d AND space_id IN (%d, %d)",
            $userId,
            $spaceId,
            $courseId
        )) === 0,
        'The disposable user unexpectedly had provider membership.'
    );

    $plans = new PlanRepository();
    foreach (['a_space', 'b_space', 'c_course'] as $key) {
        $planId = $plans->create([
            'title' => $token . '-' . $key,
            'slug' => $token . '-' . $key,
            'status' => 'active',
            'duration_type' => 'lifetime',
            'trial_days' => 0,
            'grace_period_days' => 0,
            'meta' => ['runtime_smoke_digest' => $tokenDigest],
        ]);
        $fail($planId > 0, "Disposable plan {$key} could not be created.");
        $planIds[$key] = $planId;
    }

    $rules = new PlanRuleRepository();
    foreach (['a_space', 'b_space'] as $key) {
        $ruleIds[$key] = $rules->create([
            'plan_id' => $planIds[$key],
            'provider' => 'fluent_community',
            'resource_type' => 'fc_space',
            'resource_id' => (string) $spaceId,
            'drip_type' => 'immediate',
        ]);
        $fail($ruleIds[$key] > 0, "Disposable rule {$key} could not be created.");
    }
    $unlockAt = $clock->now()->modify('+8 seconds');
    $unlockAtStorage = $clock->storage($unlockAt);
    $ruleIds['c_course'] = $rules->create([
        'plan_id' => $planIds['c_course'],
        'provider' => 'fluent_community',
        'resource_type' => 'fc_course',
        'resource_id' => (string) $courseId,
        'drip_type' => 'fixed_date',
        'drip_date' => $unlockAtStorage,
    ]);
    $fail($ruleIds['c_course'] > 0, 'Disposable future course rule could not be created.');

    $access = new AccessGrantService();
    $grantA = $access->manualGrant($userId, $planIds['a_space']);
    $fail(($grantA['created'] ?? 0) === 1 && ($grantA['failed'] ?? 0) === 0, 'Plan A did not create one space entitlement.');
    $fail(Helper::isUserInSpace($userId, $spaceId), 'Plan A provider grant was not observable.');

    $grantB = $access->manualGrant($userId, $planIds['b_space']);
    $fail(($grantB['created'] ?? 0) === 1 && ($grantB['failed'] ?? 0) === 0, 'Plan B did not create the stacked space entitlement.');
    $fail(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$pivotTable} WHERE user_id = %d AND space_id = %d AND status = 'active'",
            $userId,
            $spaceId
        )) === 1,
        'Stacked space plans created more than one active provider pivot.'
    );

    $grantC = $access->manualGrant($userId, $planIds['c_course']);
    $fail(($grantC['pending'] ?? 0) === 1 && ($grantC['failed'] ?? 0) === 0, 'Plan C did not persist one deferred course entitlement.');
    $fail(!Helper::isUserInSpace($userId, $courseId), 'Future course access was granted before unlock.');

    $edges = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$edgeTable} WHERE user_id = %d ORDER BY id ASC",
        $userId
    ), ARRAY_A);
    $fail(count($edges) === 3, 'The matrix did not persist exactly three entitlement edges.');
    $edgeIds = array_map('intval', array_column($edges, 'id'));
    $activePlans = array_map('intval', array_column($edges, 'plan_id'));
    sort($activePlans);
    $expectedPlans = array_values($planIds);
    sort($expectedPlans);
    $fail($activePlans === $expectedPlans, 'Typed plan identities were not preserved across the three edges.');

    $operations = $wpdb->get_results(
        "SELECT operation.* FROM {$operationTable} operation
         INNER JOIN {$edgeTable} edge ON edge.id = operation.edge_id
         WHERE edge.user_id = " . (int) $userId . ' ORDER BY operation.id ASC',
        ARRAY_A
    );
    $operationIds = array_map('intval', array_column($operations, 'id'));
    $fail(count($operations) === 3, 'Initial grant matrix did not persist exactly three operations.');
    $fail(
        count(array_filter($operations, static fn(array $operation): bool => $operation['state'] === 'applied')) === 2,
        'Immediate space operations were not both applied.'
    );
    $deferredOperations = array_values(array_filter(
        $operations,
        static fn(array $operation): bool => $operation['state'] === 'deferred'
    ));
    $fail(count($deferredOperations) === 1, 'Future course operation was not uniquely deferred.');
    $courseGrantOperationId = (int) $deferredOperations[0]['id'];
    $fail((string) $deferredOperations[0]['eligible_at'] === $unlockAtStorage, 'Deferred operation eligibility drifted from the exact rule time.');

    $communityBeforeUnlock = (new CommunityMemberContext())->forUser($userId);
    $fail(
        array_keys($communityBeforeUnlock) === [
            'state',
            'profile',
            'spaces',
            'courses',
            'pending_access_count',
            'capabilities',
        ],
        'Community context did not expose the exact member-safe top-level contract.'
    );
    $fail($communityBeforeUnlock['state'] === 'degraded', 'Deferred course access was not reported as degraded.');
    $fail(
        count($communityBeforeUnlock['spaces']) === 1
        && (int) $communityBeforeUnlock['spaces'][0]['id'] === $spaceId
        && $communityBeforeUnlock['spaces'][0]['plan_ids'] === [$planIds['a_space'], $planIds['b_space']]
        && $communityBeforeUnlock['spaces'][0]['operation_state'] === 'healthy',
        'Stacked active space access was not composed from the installed Community APIs.'
    );
    $fail(
        count($communityBeforeUnlock['courses']) === 1
        && (int) $communityBeforeUnlock['courses'][0]['id'] === $courseId
        && $communityBeforeUnlock['courses'][0]['progress'] === null
        && $communityBeforeUnlock['courses'][0]['plan_ids'] === [$planIds['c_course']]
        && $communityBeforeUnlock['courses'][0]['operation_state'] === 'deferred',
        'Deferred course context did not preserve the entitlement without inventing provider progress.'
    );
    $fail(
        (int) $communityBeforeUnlock['pending_access_count'] === 1,
        'Deferred course access was not counted exactly once.'
    );
    $fail(
        ($communityBeforeUnlock['capabilities']['spaces'] ?? null) === 'available'
        && ($communityBeforeUnlock['capabilities']['courses'] ?? null) === 'available'
        && ($communityBeforeUnlock['capabilities']['profile_verification_read'] ?? null) === 'available'
        && in_array($communityBeforeUnlock['capabilities']['badges'] ?? null, ['unverified', 'available'], true)
        && in_array($communityBeforeUnlock['capabilities']['points'] ?? null, ['unverified', 'available'], true)
        && in_array($communityBeforeUnlock['capabilities']['leaderboard_levels'] ?? null, ['unverified', 'available'], true),
        'Mounted core capability truth did not preserve optional active-Pro capability gating.'
    );

    wp_set_current_user($userId);
    $accountData = AccountController::myAccess(new WP_REST_Request('GET'))->get_data();
    wp_set_current_user($previousUserId);
    $fail(
        is_array($accountData) && array_keys($accountData) === ['plans', 'history', 'community'],
        'The mounted account response did not preserve its exact public sections.'
    );
    $fail(
        is_array($accountData['community'] ?? null)
        && ($accountData['community']['courses'][0]['operation_state'] ?? null) === 'deferred',
        'The mounted account response did not compose the Community context.'
    );
    $accountJson = wp_json_encode($accountData);
    $fail(
        is_string($accountJson)
        && !str_contains($accountJson, $email)
        && !str_contains($accountJson, 'provider-only')
        && !str_contains($accountJson, 'user_badges')
        && !str_contains($accountJson, 'badge_slug'),
        'The mounted account response exposed provider-private or badge storage fields.'
    );

    $grantRepository = new GrantRepository();
    $grants = $grantRepository->getByUserId($userId);
    $grantIds = array_map('intval', array_column($grants, 'id'));
    $fail(count($grantIds) === 2, 'Three edges did not produce the expected two resource aggregates.');
    $spaceGrantRows = array_values(array_filter(
        $grants,
        static fn(array $grant): bool => ($grant['resource_type'] ?? '') === 'fc_space'
    ));
    $fail(count($spaceGrantRows) === 1, 'The stacked space aggregate was not unique.');
    $spaceGrantId = (int) $spaceGrantRows[0]['id'];

    $revokeA = $access->revokePlan($userId, $planIds['a_space'], ['reason' => 'runtime_matrix_revoke_a']);
    $fail(!empty($revokeA['success']) && ($revokeA['revoked'] ?? 0) === 1, 'Plan A edge did not end cleanly.');
    $fail(Helper::isUserInSpace($userId, $spaceId), 'Revoking Plan A detached surviving stacked Plan B access.');
    $fail(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$operationTable} operation
             INNER JOIN {$edgeTable} edge ON edge.id = operation.edge_id
             WHERE edge.user_id = %d AND operation.desired_action = 'revoke'",
            $userId
        )) === 0,
        'Non-final Plan A revoke created unsafe provider detach intent.'
    );

    $pause = $access->pauseGrant($spaceGrantId, 'runtime_matrix_pause');
    $fail(!empty($pause['success']), 'Stacked space aggregate pause was not applied.');
    $fail(!Helper::isUserInSpace($userId, $spaceId), 'Paused Community space remained assigned.');
    $fail(!Helper::isUserInSpace($userId, $courseId), 'Pausing the space unlocked the unrelated future course.');

    $resume = $access->resumeGrant($spaceGrantId);
    $fail(!empty($resume['success']), 'Stacked space aggregate resume was not applied.');
    $fail(Helper::isUserInSpace($userId, $spaceId), 'Resumed Community space was not reassigned.');
    $fail(!Helper::isUserInSpace($userId, $courseId), 'Resuming the space unlocked the unrelated future course.');

    $drips = new DripScheduleService();
    $deadline = microtime(true) + 15;
    $processed = 0;
    while (microtime(true) < $deadline) {
        $processed += $drips->processNotifications(10);
        if (Helper::isUserInSpace($userId, $courseId)) {
            break;
        }
        usleep(250000);
    }
    $fail($processed === 1, 'Future course notification was not processed exactly once within 15 seconds.');
    $fail(Helper::isUserInSpace($userId, $courseId), 'Future course provider access was not observable after unlock.');
    $courseOperation = (new ProviderOperationRepository())->findById($courseGrantOperationId);
    $fail(
        is_array($courseOperation)
        && $courseOperation['state'] === 'applied'
        && (int) $courseOperation['attempt_count'] === 1,
        'Deferred course operation did not apply exactly once.'
    );
    $fail($drips->processNotifications(10) === 0, 'Deferred notification replay processed a second time.');
    $replayOutcome = (new ProviderOperationWorker())->process($courseGrantOperationId);
    $fail($replayOutcome->status === 'already-applied', 'Applied course operation replay was not idempotent.');
    $courseOperationAfterReplay = (new ProviderOperationRepository())->findById($courseGrantOperationId);
    $fail((int) ($courseOperationAfterReplay['attempt_count'] ?? 0) === 1, 'Applied course operation replay consumed another attempt.');
    $fail(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$pivotTable} WHERE user_id = %d AND space_id = %d AND status = 'active'",
            $userId,
            $courseId
        )) === 1,
        'Course unlock/replay did not retain one active provider pivot.'
    );
    $communityAfterUnlock = (new CommunityMemberContext())->forUser($userId);
    $communityAfterUnlockSummary = wp_json_encode([
        'state' => $communityAfterUnlock['state'] ?? null,
        'pending_access_count' => $communityAfterUnlock['pending_access_count'] ?? null,
        'courses' => $communityAfterUnlock['courses'] ?? null,
        'capabilities' => $communityAfterUnlock['capabilities'] ?? null,
    ]);
    $fail(
        $communityAfterUnlock['state'] === 'available'
        && (int) $communityAfterUnlock['pending_access_count'] === 0
        && count($communityAfterUnlock['courses']) === 1
        && (int) $communityAfterUnlock['courses'][0]['id'] === $courseId
        && is_int($communityAfterUnlock['courses'][0]['progress'])
        && $communityAfterUnlock['courses'][0]['progress'] >= 0
        && $communityAfterUnlock['courses'][0]['progress'] <= 100
        && $communityAfterUnlock['courses'][0]['operation_state'] === 'healthy',
        'Unlocked course context did not use the installed progress API and healthy operation truth: '
        . (is_string($communityAfterUnlockSummary) ? $communityAfterUnlockSummary : 'unavailable')
    );

    $revokeB = $access->revokePlan($userId, $planIds['b_space'], ['reason' => 'runtime_matrix_revoke_b']);
    $fail(!empty($revokeB['success']) && ($revokeB['revoked'] ?? 0) === 1, 'Final Plan B space revoke was not applied.');
    $fail(!Helper::isUserInSpace($userId, $spaceId), 'Final space revoke left provider access present.');

    $revokeC = $access->revokePlan($userId, $planIds['c_course'], ['reason' => 'runtime_matrix_revoke_c']);
    $fail(!empty($revokeC['success']) && ($revokeC['revoked'] ?? 0) === 1, 'Final Plan C course revoke was not applied.');
    $fail(!Helper::isUserInSpace($userId, $courseId), 'Final course revoke left provider access present.');

    $terminalEdges = $wpdb->get_results($wpdb->prepare(
        "SELECT id, lifecycle FROM {$edgeTable} WHERE user_id = %d ORDER BY id ASC",
        $userId
    ), ARRAY_A);
    $fail(
        count($terminalEdges) === 3
        && count(array_filter($terminalEdges, static fn(array $edge): bool => $edge['lifecycle'] === 'ended')) === 3,
        'The final matrix did not leave exactly three ended edges.'
    );
    $terminalOperations = $wpdb->get_results(
        "SELECT operation.id, operation.desired_action, operation.state
         FROM {$operationTable} operation
         INNER JOIN {$edgeTable} edge ON edge.id = operation.edge_id
         WHERE edge.user_id = " . (int) $userId . ' ORDER BY operation.id ASC',
        ARRAY_A
    );
    $operationIds = array_map('intval', array_column($terminalOperations, 'id'));
    $actions = array_count_values(array_column($terminalOperations, 'desired_action'));
    $fail(count($terminalOperations) === 7, 'The runtime matrix did not persist the expected seven durable operations.');
    $fail(($actions['grant'] ?? 0) === 3, 'The runtime matrix did not preserve three grant intents.');
    $fail(($actions['suspend'] ?? 0) === 1 && ($actions['resume'] ?? 0) === 1, 'Pause/resume durable intent was incomplete.');
    $fail(($actions['revoke'] ?? 0) === 2, 'Final provider revoke intent was incomplete.');
    $fail(
        count(array_filter($terminalOperations, static fn(array $operation): bool => $operation['state'] === 'applied')) === 7,
        'Not every provider operation reached applied truth.'
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    wp_set_current_user($previousUserId);

    if ($userId > 0) {
        try {
            if (Helper::isUserInSpace($userId, $spaceId)) {
                Helper::removeFromSpace($spaceId, $userId, 'by_admin');
            }
            if (Helper::isUserInSpace($userId, $courseId)) {
                CourseHelper::leaveCourse($courseId, $userId, 'by_admin');
            }
        } catch (Throwable $cleanupException) {
            $failure ??= $cleanupException;
        }
    }

    if ($userId > 0) {
        $grantIds = array_values(array_unique(array_merge($grantIds, array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fchub_membership_grants WHERE user_id = %d",
            $userId
        ))))));
        $edgeIds = array_values(array_unique(array_merge($edgeIds, array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$edgeTable} WHERE user_id = %d",
            $userId
        ))))));
    }

    if ($edgeIds !== []) {
        $edgeList = implode(',', array_map('intval', $edgeIds));
        $operationIds = array_values(array_unique(array_merge(
            $operationIds,
            array_map('intval', $wpdb->get_col("SELECT id FROM {$operationTable} WHERE edge_id IN ({$edgeList})"))
        )));
    }
    if ($grantIds !== []) {
        $grantList = implode(',', array_map('intval', $grantIds));
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

    if ($operationIds !== []) {
        $operationLookup = array_fill_keys(array_map('intval', $operationIds), true);
        $providerActions = $wpdb->get_results(
            "SELECT action_id, args FROM {$actionTable} WHERE hook = 'fchub_memberships_process_provider_operation'",
            ARRAY_A
        );
        foreach ($providerActions as $providerAction) {
            $args = json_decode((string) ($providerAction['args'] ?? ''), true);
            $operationId = (int) ($args['operation_id'] ?? 0);
            if (isset($operationLookup[$operationId])) {
                $actionIds[] = (int) $providerAction['action_id'];
            }
        }
    }
    $actionIds = array_values(array_unique($actionIds));
    if ($actionIds !== []) {
        $actionList = implode(',', array_map('intval', $actionIds));
        $actionLogIds = array_map('intval', $wpdb->get_col(
            "SELECT log_id FROM {$actionLogTable} WHERE action_id IN ({$actionList})"
        ));
        if ($actionLogIds !== []) {
            $wpdb->query("DELETE FROM {$actionLogTable} WHERE log_id IN (" . implode(',', $actionLogIds) . ')');
        }
        $wpdb->query("DELETE FROM {$actionTable} WHERE action_id IN ({$actionList})");
    }

    if ($operationIds !== []) {
        $wpdb->query("DELETE FROM {$operationTable} WHERE id IN (" . implode(',', array_map('intval', $operationIds)) . ')');
    }
    if ($edgeIds !== []) {
        $wpdb->query("DELETE FROM {$edgeTable} WHERE id IN (" . implode(',', array_map('intval', $edgeIds)) . ')');
    }
    if ($dripIds !== []) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}fchub_membership_drip_notifications WHERE id IN (" . implode(',', $dripIds) . ')');
    }
    if ($sourceRowIds !== []) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}fchub_membership_grant_sources WHERE id IN (" . implode(',', $sourceRowIds) . ')');
    }
    if ($auditIds !== []) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}fchub_membership_audit_log WHERE id IN (" . implode(',', $auditIds) . ')');
    }
    if ($grantIds !== []) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}fchub_membership_grants WHERE id IN (" . implode(',', array_map('intval', $grantIds)) . ')');
    }
    if ($ruleIds !== []) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}fchub_membership_plan_rules WHERE id IN (" . implode(',', array_map('intval', $ruleIds)) . ')');
    }
    if ($planIds !== []) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}fchub_membership_plans WHERE id IN (" . implode(',', array_map('intval', $planIds)) . ')');
    }

    if ($userId > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (get_userdata($userId) && !wp_delete_user($userId)) {
            $failure ??= new RuntimeException('The disposable WordPress user could not be deleted.');
        }
    }

    if ($settingsChanged && is_array($settingsWritten)) {
        $restoreResult = (new MembershipSettingsOptionCoordinator())->synchronized(
            static function (MembershipSettingsOptionCoordinator $coordinator) use ($settingsWritten, $settingsBefore): array {
                $current = $coordinator->read();
                if ($current !== $settingsWritten) {
                    throw new RuntimeException('Memberships settings changed during the smoke; concurrent values were preserved.');
                }
                return $coordinator->compareAndSwap($current, $settingsBefore);
            }
        );
        if (empty($restoreResult['success']) || empty($restoreResult['value']['success'])) {
            $failure ??= new RuntimeException('The exact Memberships settings snapshot could not be restored.');
        }
    }
}

$residueChecks = [];
if ($userId > 0) {
    $residueChecks['user'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} WHERE ID = %d", $userId));
    $residueChecks['usermeta'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $userId));
    $residueChecks['community_pivot'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pivotTable} WHERE user_id = %d", $userId));
    $residueChecks['community_profile'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fcom_xprofile WHERE user_id = %d", $userId));
    foreach ([
        'community_activities' => "SELECT COUNT(*) FROM {$wpdb->prefix}fcom_user_activities WHERE user_id = %d",
        'community_notifications' => "SELECT COUNT(*) FROM {$wpdb->prefix}fcom_notification_users WHERE user_id = %d",
        'community_comments' => "SELECT COUNT(*) FROM {$wpdb->prefix}fcom_post_comments WHERE user_id = %d",
        'community_reactions' => "SELECT COUNT(*) FROM {$wpdb->prefix}fcom_post_reactions WHERE user_id = %d",
        'community_posts' => "SELECT COUNT(*) FROM {$wpdb->prefix}fcom_posts WHERE user_id = %d",
    ] as $key => $query) {
        $residueChecks[$key] = (int) $wpdb->get_var($wpdb->prepare($query, $userId));
    }
    $residueChecks['community_meta'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fcom_meta WHERE object_type = 'user' AND object_id = %d",
        $userId
    ));
    $residueChecks['crm_contact'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers WHERE user_id = %d OR email = %s",
        $userId,
        $email
    ));
    $residueChecks['token_users'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = %s OR user_email = %s",
        $token,
        $email
    ));
}
$residueChecks['token_plans'] = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_plans
     WHERE title LIKE %s OR slug LIKE %s OR meta LIKE %s",
    '%' . $wpdb->esc_like($token) . '%',
    '%' . $wpdb->esc_like($token) . '%',
    '%' . $wpdb->esc_like($tokenDigest) . '%'
));
$residueChecks['token_audit'] = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_audit_log
     WHERE old_value LIKE %s OR new_value LIKE %s OR context LIKE %s",
    '%' . $wpdb->esc_like($token) . '%',
    '%' . $wpdb->esc_like($token) . '%',
    '%' . $wpdb->esc_like($token) . '%'
));
$residueChecks['token_settings'] = str_contains((string) $settingsRaw(), $token) ? 1 : 0;
$ownedIdChecks = [
    [$actionLogTable, 'log_id', $actionLogIds],
    [$actionTable, 'action_id', $actionIds],
    [$operationTable, 'id', $operationIds],
    [$edgeTable, 'id', $edgeIds],
    [$wpdb->prefix . 'fchub_membership_drip_notifications', 'id', $dripIds],
    [$wpdb->prefix . 'fchub_membership_grant_sources', 'id', $sourceRowIds],
    [$wpdb->prefix . 'fchub_membership_audit_log', 'id', $auditIds],
    [$wpdb->prefix . 'fchub_membership_grants', 'id', $grantIds],
    [$wpdb->prefix . 'fchub_membership_plan_rules', 'id', array_values($ruleIds)],
    [$wpdb->prefix . 'fchub_membership_plans', 'id', array_values($planIds)],
];
foreach ($ownedIdChecks as [$table, $column, $ids]) {
    if ($ids === []) {
        continue;
    }
    $residueChecks['owned_' . basename(str_replace('\\', '/', $table)) . '_' . $column] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$table} WHERE {$column} IN (" . implode(',', array_map('intval', $ids)) . ')'
    );
}
foreach ($residueChecks as $residue => $count) {
    if ($count !== 0) {
        $failure ??= new RuntimeException("Disposable {$residue} residue remained after cleanup.");
        break;
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
    $failure ??= new RuntimeException('Global runtime counts did not return to baseline: ' . implode(', ', $differences));
}

$settingsRawAfter = $settingsRaw();
if (hash('sha256', (string) $settingsRawAfter) !== $settingsHashBefore
    || strlen((string) $settingsRawAfter) !== $settingsLengthBefore
) {
    $failure ??= new RuntimeException('Memberships settings hash/length did not return to the exact baseline.');
}
$spaceAfter = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$spaceTable} WHERE id = %d", $spaceId), ARRAY_A);
$courseAfter = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$spaceTable} WHERE id = %d", $courseId), ARRAY_A);
if (hash('sha256', wp_json_encode($spaceAfter)) !== $spaceHashBefore
    || hash('sha256', wp_json_encode($courseAfter)) !== $courseHashBefore
) {
    $failure ??= new RuntimeException('Existing FluentCommunity resources changed during the smoke.');
}
foreach ([$spaceId, $courseId] as $resourceId) {
    $after = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$pivotTable} WHERE space_id = %d",
        $resourceId
    ));
    if ($after !== $memberCountsBefore[$resourceId]) {
        $failure ??= new RuntimeException("Provider membership count for resource {$resourceId} did not return to baseline.");
    }
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    fwrite(STDERR, "Runtime token digest: {$tokenDigest}\n");
    exit(1);
}

echo "FluentCommunity entitlement smoke passed: token digest {$tokenDigest}; stacked space grant/revoke, deferred course unlock, pause/resume, seven durable operations, exact Action Scheduler cleanup, 0/0 edge-operation restoration, settings hash {$settingsHashBefore}/{$settingsLengthBefore}, and full baseline counts restored. LearnDash remains unverified; optional active FluentCommunity Pro state was not mutated.\n";
