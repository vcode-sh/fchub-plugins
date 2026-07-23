<?php

use FChubMemberships\Adapters\FluentCommunityAdapter;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Support\ResourceTypeRegistry;
use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\XProfile;
use FluentCommunity\App\Services\Helper;
use FluentCommunityPro\App\Modules\LeaderBoard\Services\LeaderBoardHelper;

defined('ABSPATH') || exit;

global $wpdb;

$fail = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$table = static fn(string $suffix): string => $GLOBALS['wpdb']->prefix . $suffix;
$rawOption = static function (string $option): ?string {
    global $wpdb;

    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $option
    ));

    return $value === null ? null : (string) $value;
};
$badgeSlugs = static function (int $userId): array {
    $profile = XProfile::where('user_id', $userId)->first();
    $meta = is_object($profile) ? (array) ($profile->meta ?? []) : [];
    $slugs = array_values(array_filter(
        array_map('strval', (array) ($meta['badge_slug'] ?? [])),
        static fn(string $slug): bool => $slug !== ''
    ));
    sort($slugs, SORT_STRING);

    return array_values(array_unique($slugs));
};
$hasBadge = static function (int $userId, string $slug) use ($badgeSlugs): bool {
    return in_array($slug, $badgeSlugs($userId), true);
};
$fingerprint = static function () use ($rawOption, $table): array {
    global $wpdb;
    $providerOption = static function (string $key) use ($wpdb, $table): ?array {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, value FROM {$table('fcom_meta')} WHERE object_type = %s AND meta_key = %s LIMIT 1",
            'option',
            $key
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    };
    $catalogue = $providerOption('user_badges');
    $features = $providerOption('fluent_community_features');

    return [
        'membership_settings' => [
            'bytes' => strlen((string) $rawOption('fchub_memberships_settings')),
            'sha256' => hash('sha256', (string) $rawOption('fchub_memberships_settings')),
        ],
        'badge_catalogue' => [
            'exists' => is_array($catalogue),
            'bytes' => is_array($catalogue) ? strlen((string) $catalogue['value']) : 0,
            'sha256' => is_array($catalogue) ? hash('sha256', (string) $catalogue['value']) : null,
        ],
        'feature_config' => [
            'exists' => is_array($features),
            'bytes' => is_array($features) ? strlen((string) $features['value']) : 0,
            'sha256' => is_array($features) ? hash('sha256', (string) $features['value']) : null,
        ],
        'counts' => [
            'users' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
            'user_meta' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta}"),
            'xprofiles' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fcom_xprofile')}"),
            'plans' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_plans')}"),
            'rules' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_plan_rules')}"),
            'grants' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_grants')}"),
            'grant_sources' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_grant_sources')}"),
            'drip_notifications' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_drip_notifications')}"),
            'audit_log' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_audit_log')}"),
            'edges' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_entitlement_edges')}"),
            'operations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_provider_operations')}"),
            'provider_actions' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table('actionscheduler_actions')} WHERE hook = %s",
                ProviderOperationWorker::HOOK
            )),
            'provider_action_logs' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table('actionscheduler_logs')} logs
                 INNER JOIN {$table('actionscheduler_actions')} actions
                    ON actions.action_id = logs.action_id
                 WHERE actions.hook = %s",
                ProviderOperationWorker::HOOK
            )),
        ],
    ];
};
$createUser = static function (string $token, string $suffix): int {
    $userId = wp_insert_user([
        'user_login' => $token . '-' . $suffix,
        'user_email' => $token . '-' . $suffix . '@example.test',
        'user_pass' => wp_generate_password(32, true, true),
        'role' => 'subscriber',
    ]);
    if (is_wp_error($userId) || (int) $userId <= 0) {
        throw new RuntimeException("Unable to create disposable {$suffix} user.");
    }

    return (int) $userId;
};
$syncXProfile = static function (int $userId, string $suffix): void {
    $communityUser = \FluentCommunity\App\Models\User::find($userId);
    if (!is_object($communityUser)) {
        throw new RuntimeException("Unable to load the disposable {$suffix} Community user.");
    }

    // In FluentCommunity 2.7.0 this model declares user_id as its primary key
    // while the table auto-increments id. Re-read by the immutable WP user ID
    // instead of trusting the returned model's post-insert primary-key value.
    $communityUser->syncXProfile(false);
    $profile = XProfile::where('user_id', $userId)->first();
    if (!is_object($profile)) {
        throw new RuntimeException("Unable to synchronise the disposable {$suffix} Community XProfile.");
    }
};
$createPlan = static function (string $token, string $suffix, string $badgeSlug): int {
    $plan = (new PlanService())->create([
        'title' => $token . '-' . $suffix,
        'slug' => $token . '-' . $suffix,
        'status' => 'active',
        'level' => 0,
        'duration_type' => 'lifetime',
        'trial_days' => 0,
        'grace_period_days' => 0,
        'rules' => [[
            'provider' => 'fluent_community',
            'resource_type' => 'fc_badge',
            'resource_id' => $badgeSlug,
            'drip_type' => 'immediate',
        ]],
    ]);
    if (!empty($plan['error']) || (int) ($plan['id'] ?? 0) <= 0) {
        throw new RuntimeException("Unable to create disposable {$suffix} badge plan.");
    }

    return (int) $plan['id'];
};
$cleanupFixtures = static function (array $fixtures): void {
    global $wpdb;

    $ids = static fn(array $values): string => implode(',', array_values(array_filter(
        array_map('intval', $values),
        static fn(int $id): bool => $id > 0
    )));
    $plans = $ids($fixtures['plans'] ?? []);
    $users = $ids($fixtures['users'] ?? []);
    if ($plans === '' && $users === '') {
        return;
    }

    $prefix = $wpdb->prefix;
    $rules = $plans === '' ? [] : array_map('intval', $wpdb->get_col(
        "SELECT id FROM {$prefix}fchub_membership_plan_rules WHERE plan_id IN ({$plans})"
    ));
    $grants = ($plans === '' || $users === '') ? [] : array_map('intval', $wpdb->get_col(
        "SELECT id FROM {$prefix}fchub_membership_grants WHERE user_id IN ({$users}) AND plan_id IN ({$plans})"
    ));
    $edges = ($plans === '' || $users === '') ? [] : array_map('intval', $wpdb->get_col(
        "SELECT id FROM {$prefix}fchub_membership_entitlement_edges WHERE user_id IN ({$users}) AND plan_id IN ({$plans})"
    ));
    $operations = $edges === [] ? [] : array_map('intval', $wpdb->get_col(
        'SELECT id FROM ' . $prefix . 'fchub_membership_provider_operations WHERE edge_id IN (' . $ids($edges) . ')'
    ));
    $actions = $operations === [] ? [] : array_map('intval', $wpdb->get_col(
        'SELECT action_id FROM ' . $prefix . 'actionscheduler_actions WHERE hook = ' . $wpdb->prepare('%s', ProviderOperationWorker::HOOK)
        . ' AND args IN (' . implode(',', array_map(
            static fn(int $operationId): string => $wpdb->prepare('%s', wp_json_encode(['operation_id' => $operationId])),
            $operations
        )) . ')'
    ));
    $auditWhere = [];
    foreach ([
        'plan' => $fixtures['plans'] ?? [],
        'plan_rule' => $rules,
        'grant' => $grants,
        'entitlement_edge' => $edges,
        'provider_operation' => $operations,
    ] as $entityType => $entityIds) {
        $entityIds = $ids($entityIds);
        if ($entityIds !== '') {
            $auditWhere[] = '(entity_type = ' . $wpdb->prepare('%s', $entityType) . " AND entity_id IN ({$entityIds}))";
        }
    }
    $auditIds = $auditWhere === [] ? [] : array_map('intval', $wpdb->get_col(
        'SELECT id FROM ' . $prefix . 'fchub_membership_audit_log WHERE ' . implode(' OR ', $auditWhere)
    ));

    if ($actions !== []) {
        $actionIds = $ids($actions);
        $wpdb->query("DELETE FROM {$prefix}actionscheduler_logs WHERE action_id IN ({$actionIds})");
        $wpdb->query("DELETE FROM {$prefix}actionscheduler_actions WHERE action_id IN ({$actionIds})");
    }
    if ($operations !== []) {
        $wpdb->query('DELETE FROM ' . $prefix . 'fchub_membership_provider_operations WHERE id IN (' . $ids($operations) . ')');
    }
    if ($edges !== []) {
        $wpdb->query('DELETE FROM ' . $prefix . 'fchub_membership_entitlement_edges WHERE id IN (' . $ids($edges) . ')');
    }
    if ($grants !== []) {
        $grantIds = $ids($grants);
        $wpdb->query("DELETE FROM {$prefix}fchub_membership_drip_notifications WHERE grant_id IN ({$grantIds})");
        $wpdb->query("DELETE FROM {$prefix}fchub_membership_grant_sources WHERE grant_id IN ({$grantIds})");
        $wpdb->query("DELETE FROM {$prefix}fchub_membership_grants WHERE id IN ({$grantIds})");
    }
    if ($auditIds !== []) {
        $wpdb->query('DELETE FROM ' . $prefix . 'fchub_membership_audit_log WHERE id IN (' . $ids($auditIds) . ')');
    }
    if ($rules !== []) {
        $wpdb->query('DELETE FROM ' . $prefix . 'fchub_membership_plan_rules WHERE id IN (' . $ids($rules) . ')');
    }
    if ($plans !== '') {
        $wpdb->query("DELETE FROM {$prefix}fchub_membership_plans WHERE id IN ({$plans})");
    }

    if ($users !== '') {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach (array_map('intval', $fixtures['users']) as $userId) {
            if (get_userdata($userId) !== false && !wp_delete_user($userId)) {
                throw new RuntimeException("Unable to remove disposable user {$userId}.");
            }
        }
    }
};

$disabledFeature = getenv('FCHUB_PLAN020_DISABLED_FEATURE');
if (is_string($disabledFeature) && $disabledFeature !== '') {
    $fail(
        in_array($disabledFeature, ['course_module', 'user_badge', 'leader_board_module'], true),
        'Feature-off probe received an unsupported FluentCommunity feature.'
    );
    $beforeProbe = $fingerprint();
    $fail(!Helper::isFeatureEnabled($disabledFeature), "{$disabledFeature} was not disabled in the fresh probe process.");

    $capabilities = new CommunityCapabilityRegistry();
    $states = $capabilities->capabilities();
    $expectedCapabilities = match ($disabledFeature) {
        'course_module' => ['courses'],
        'user_badge' => ['badges'],
        'leader_board_module' => ['points', 'leaderboard_levels'],
    };
    foreach ($expectedCapabilities as $capability) {
        $state = $states[$capability] ?? [];
        $fail(
            ($state['status'] ?? null) === 'disabled'
                && ($state['available'] ?? true) === false
                && ($state['reason'] ?? null) === $disabledFeature . '_disabled',
            "{$capability} health did not truthfully report {$disabledFeature} as disabled."
        );
    }
    $fail(
        ($states['profile_verification_read']['status'] ?? null) === 'available',
        'Core profile verification was degraded by an unrelated Pro feature-off probe.'
    );

    $resourceTypes = new ResourceTypeRegistry($capabilities);
    if ($disabledFeature === 'course_module') {
        $fail($resourceTypes->get('fc_course') === null, 'The disabled course control remains selectable.');
    }
    if ($disabledFeature === 'user_badge') {
        $fail($resourceTypes->get('fc_badge') === null, 'The disabled badge control remains selectable.');
        $fail(!(new FluentCommunityAdapter($capabilities))->supports('fc_badge'), 'Disabled badges still permit provider mutation.');
    }

    $afterProbe = $fingerprint();
    $fail($afterProbe === $beforeProbe, 'Feature-off verification attempted a provider or Memberships mutation.');
    echo wp_json_encode([
        'success' => true,
        'disabled_feature' => $disabledFeature,
        'capabilities' => array_intersect_key($states, array_flip($expectedCapabilities)),
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$requiredTables = [
    $wpdb->users,
    $wpdb->usermeta,
    $table('fcom_meta'),
    $table('fcom_xprofile'),
    $table('fchub_membership_plans'),
    $table('fchub_membership_plan_rules'),
    $table('fchub_membership_grants'),
    $table('fchub_membership_entitlement_edges'),
    $table('fchub_membership_provider_operations'),
    $table('actionscheduler_actions'),
    $table('actionscheduler_logs'),
];
$placeholders = implode(', ', array_fill(0, count($requiredTables), '%s'));
$engines = $wpdb->get_results($wpdb->prepare(
    "SELECT table_name, engine FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})",
    ...$requiredTables
), ARRAY_A);
$fail(
    count($engines) === count($requiredTables)
        && array_reduce($engines, static fn(bool $allInnoDb, array $row): bool => $allInnoDb && ($row['engine'] ?? '') === 'InnoDB', true),
    'Plan 020 requires transactional Memberships tables; an affected table is missing or not InnoDB.'
);
$fail(
    defined('FLUENT_COMMUNITY_PLUGIN_VERSION') && FLUENT_COMMUNITY_PLUGIN_VERSION === '2.7.0'
        && defined('FLUENT_COMMUNITY_PRO') && FLUENT_COMMUNITY_PRO
        && defined('FLUENT_COMMUNITY_PRO_VERSION') && FLUENT_COMMUNITY_PRO_VERSION === '2.7.0',
    'Plan 020 requires active FluentCommunity core and Pro at exactly 2.7.0.'
);
foreach (['course_module', 'user_badge', 'leader_board_module'] as $feature) {
    $fail(Helper::isFeatureEnabled($feature), "Plan 020 requires {$feature} to be enabled.");
}
$fail((array) Utility::getOption('user_badges', []) === [], 'Plan 020 requires the audited empty badge catalogue baseline.');
$fail((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_entitlement_edges')}") === 0, 'Plan 020 requires a zero-edge baseline.');
$fail((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table('fchub_membership_provider_operations')}") === 0, 'Plan 020 requires a zero-operation baseline.');

$before = $fingerprint();
$fail(!$before['feature_config']['exists'], 'Plan 020 requires the audited default FluentCommunity feature configuration baseline.');
$token = 'fchub-plan020-' . wp_generate_uuid4();
$badgeSlug = $token . '-badge';
$previousUser = get_current_user_id();
$failure = null;
$cleanupFailures = [];
$badgeCatalogueMutationAttempted = false;
$featureConfigMutationAttempted = false;
$fixtures = ['users' => [], 'plans' => []];

try {
    $admin = get_user_by('id', 1);
    $fail($admin instanceof WP_User && user_can($admin, 'manage_options'), 'An administrator user is required for the Pro badge API.');
    wp_set_current_user(1);

    $badgeRequest = new WP_REST_Request('POST', '/fluent-community/v2/admin/user-badges');
    $badgeRequest->set_body_params(['badges' => [[
        'title' => $token . ' badge',
        'slug' => $badgeSlug,
        'show_label' => 'yes',
        'config' => ['emoji' => ''],
    ]]]);
    $badgeCatalogueMutationAttempted = true;
    $badgeResponse = rest_get_server()->dispatch($badgeRequest);
    if (getenv('FCHUB_PLAN020_FAIL_AFTER_BADGE_MUTATION') === '1') {
        throw new RuntimeException('Injected Plan 020 post-badge-mutation failure.');
    }
    $fail($badgeResponse->get_status() >= 200 && $badgeResponse->get_status() < 300, 'The registered Pro badge API rejected the disposable badge.');
    $catalogue = (array) Utility::getOption('user_badges', []);
    $fail(isset($catalogue[$badgeSlug]), 'The registered Pro badge API did not persist the exact disposable slug.');

    $ownedUser = $createUser($token, 'owned');
    $fixtures['users'][] = $ownedUser;
    $syncXProfile($ownedUser, 'owned');
    $manualUser = $createUser($token, 'manual');
    $fixtures['users'][] = $manualUser;
    $syncXProfile($manualUser, 'manual');
    $stackedUser = $createUser($token, 'stacked');
    $fixtures['users'][] = $stackedUser;
    $syncXProfile($stackedUser, 'stacked');
    $ownedPlan = $createPlan($token, 'owned', $badgeSlug);
    $fixtures['plans'][] = $ownedPlan;
    $manualPlan = $createPlan($token, 'manual', $badgeSlug);
    $fixtures['plans'][] = $manualPlan;
    $stackedPlanA = $createPlan($token, 'stack-a', $badgeSlug);
    $fixtures['plans'][] = $stackedPlanA;
    $stackedPlanB = $createPlan($token, 'stack-b', $badgeSlug);
    $fixtures['plans'][] = $stackedPlanB;

    $access = new AccessGrantService();
    $adapter = new FluentCommunityAdapter();
    $edges = new EntitlementEdgeRepository();
    $operations = new ProviderOperationRepository();

    $ownedGrant = $access->manualGrant($ownedUser, $ownedPlan);
    $fail(empty($ownedGrant['failed']) && $hasBadge($ownedUser, $badgeSlug), 'Owned badge grant did not assign the badge.');
    $ownedEdges = array_values(array_filter(
        $edges->getActiveByUserProvider($ownedUser, 'fluent_community'),
        static fn(array $edge): bool => ($edge['resource_type'] ?? '') === 'fc_badge' && ($edge['resource_id'] ?? '') === $badgeSlug
    ));
    $fail(count($ownedEdges) === 1, 'Owned badge grant did not create exactly one active typed edge.');

    $replay = $access->manualGrant($ownedUser, $ownedPlan);
    $fail(empty($replay['failed']) && count(array_filter(
        $edges->getActiveByUserProvider($ownedUser, 'fluent_community'),
        static fn(array $edge): bool => ($edge['resource_type'] ?? '') === 'fc_badge' && ($edge['resource_id'] ?? '') === $badgeSlug
    )) === 1, 'Badge replay created a duplicate active edge.');

    $grantOperation = $operations->findLatestForResource($ownedEdges[0]);
    $fail(is_array($grantOperation), 'Owned badge grant did not persist a provider operation.');
    $replayOutcome = (new ProviderOperationWorker())->process((int) $grantOperation['id']);
    $fail(in_array($replayOutcome->status, ['applied', 'already-applied'], true), 'Provider-operation replay was not idempotent.');

    $ownedRevoke = $access->revokePlan($ownedUser, $ownedPlan, ['reason' => 'plan020-owned-revoke']);
    $fail(empty($ownedRevoke['failed']) && !$hasBadge($ownedUser, $badgeSlug), 'Final owned badge revoke did not remove the badge.');

    $manualProviderGrant = $adapter->grant($manualUser, 'fc_badge', $badgeSlug);
    $fail(!empty($manualProviderGrant['success']) && $hasBadge($manualUser, $badgeSlug), 'Manual provider badge setup failed.');
    $manualGrant = $access->manualGrant($manualUser, $manualPlan);
    $fail(empty($manualGrant['failed']), 'Manual-preservation membership grant failed.');
    $manualRevoke = $access->revokePlan($manualUser, $manualPlan, ['reason' => 'plan020-manual-preserve']);
    $fail(empty($manualRevoke['failed']) && $hasBadge($manualUser, $badgeSlug), 'Pre-existing manual badge was removed by FChub revoke.');

    $stackA = $access->manualGrant($stackedUser, $stackedPlanA);
    $stackB = $access->manualGrant($stackedUser, $stackedPlanB);
    $fail(empty($stackA['failed']) && empty($stackB['failed']) && $hasBadge($stackedUser, $badgeSlug), 'Stacked badge grants failed.');
    $firstStackedRevoke = $access->revokePlan($stackedUser, $stackedPlanA, ['reason' => 'plan020-stack-a']);
    $fail(empty($firstStackedRevoke['failed']) && $hasBadge($stackedUser, $badgeSlug), 'First stacked revoke removed the shared badge.');
    $secondStackedRevoke = $access->revokePlan($stackedUser, $stackedPlanB, ['reason' => 'plan020-stack-b']);
    $fail(empty($secondStackedRevoke['failed']) && !$hasBadge($stackedUser, $badgeSlug), 'Final stacked revoke retained the FChub-owned badge.');

    $profile = XProfile::where('user_id', $ownedUser)->first();
    $fail(is_object($profile), 'The owned user has no Community XProfile.');
    $points = (int) ($profile->total_points ?? 0);
    $level = LeaderBoardHelper::getLevelByPoint($points);
    $fail(is_array($level) && isset($level['slug']), 'Points-to-level lookup failed without calling the cache-writing leaderboard aggregate.');

    $profileCapability = (new CommunityCapabilityRegistry())->capabilities()['profile_verification_read'] ?? [];
    $fail(
        ($profileCapability['status'] ?? null) === 'available'
            && is_int((int) ($profile->is_verified ?? 0)),
        'Core profile verification could not be read independently of Pro badge and leaderboard features.'
    );

    $featureConfig = (array) Utility::getFeaturesConfig();
    $featureProbePath = ABSPATH . 'wp-content/plugins/fchub-memberships/tests/runtime/fluentcommunity-pro-runtime-certification.php';
    foreach (['course_module', 'user_badge', 'leader_board_module'] as $disabledFeature) {
        $disabledConfig = $featureConfig;
        $disabledConfig[$disabledFeature] = 'no';
        $featureRequest = new WP_REST_Request('POST', '/fluent-community/v2/settings/features');
        $featureRequest->set_body_params(['features' => $disabledConfig]);
        $featureConfigMutationAttempted = true;
        $featureResponse = rest_get_server()->dispatch($featureRequest);
        $fail(
            $featureResponse->get_status() >= 200 && $featureResponse->get_status() < 300,
            "The FluentCommunity feature API rejected the {$disabledFeature} probe."
        );
        $command = 'FCHUB_PLAN020_DISABLED_FEATURE=' . escapeshellarg($disabledFeature)
            . ' /usr/local/bin/wp eval-file ' . escapeshellarg($featureProbePath)
            . ' --path=' . escapeshellarg(ABSPATH) . ' 2>&1';
        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
        $fail(is_resource($process), "Unable to start the fresh {$disabledFeature} feature-off probe.");
        $probeOutput = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $probeStatus = proc_close($process);

        $restoreRequest = new WP_REST_Request('POST', '/fluent-community/v2/settings/features');
        $restoreRequest->set_body_params(['features' => $featureConfig]);
        $restoreResponse = rest_get_server()->dispatch($restoreRequest);
        $fail(
            $restoreResponse->get_status() >= 200 && $restoreResponse->get_status() < 300,
            "The FluentCommunity feature API could not restore {$disabledFeature}."
        );
        $fail(
            $probeStatus === 0,
            "The fresh {$disabledFeature} feature-off probe failed: " . trim((string) $probeOutput)
        );
    }

} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        // Entitlement edge transitions own nested commits, so this mounted
        // smoke removes only IDs created by this token. Provider writes stay
        // on supported APIs/models; this is limited cleanup of FChub fixtures.
        $cleanupFixtures($fixtures);
    } catch (Throwable $exception) {
        $cleanupFailures[] = $exception;
    }
    if ($badgeCatalogueMutationAttempted) {
        try {
            // The audited baseline has no badge catalogue row. This is the
            // provider's supported option API, never a raw SQL cleanup.
            Utility::deleteOption('user_badges');
        } catch (Throwable $exception) {
            $cleanupFailures[] = $exception;
        }
    }
    if ($featureConfigMutationAttempted) {
        try {
            // The audited baseline resolves from defaults and has no
            // persisted feature-option row. Restore that exact provider
            // option state.
            Utility::deleteOption('fluent_community_features');
        } catch (Throwable $exception) {
            $cleanupFailures[] = $exception;
        }
    }
    wp_set_current_user($previousUser);
}

$after = $fingerprint();
$cleanupVerified = $after === $before;
$tokenPattern = '%' . $wpdb->esc_like($token) . '%';
$tokenResidue = [
    'users' => (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s OR user_email LIKE %s",
        $tokenPattern,
        $tokenPattern
    )),
    'plans' => (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table('fchub_membership_plans')} WHERE title LIKE %s OR slug LIKE %s",
        $tokenPattern,
        $tokenPattern
    )),
    'rules' => (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table('fchub_membership_plan_rules')} WHERE resource_id LIKE %s",
        $tokenPattern
    )),
    'edges' => (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table('fchub_membership_entitlement_edges')} WHERE resource_id LIKE %s",
        $tokenPattern
    )),
    'audit' => (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table('fchub_membership_audit_log')}
         WHERE old_value LIKE %s OR new_value LIKE %s OR context LIKE %s",
        $tokenPattern,
        $tokenPattern,
        $tokenPattern
    )),
];
$residueVerified = count(array_filter(
    $tokenResidue,
    static fn(int $count): bool => $count !== 0
)) === 0;

$cleanupProblems = array_map(
    static fn(Throwable $exception): string => $exception->getMessage(),
    $cleanupFailures
);
if (!$cleanupVerified) {
    $cleanupProblems[] = 'Plan 020 cleanup did not restore the exact runtime fingerprint.';
}
if (!$residueVerified) {
    $cleanupProblems[] = 'Plan 020 token-scoped residue remained after cleanup.';
}
if ($cleanupProblems !== []) {
    throw new RuntimeException(
        implode(' ', $cleanupProblems),
        0,
        $failure instanceof Throwable ? $failure : null
    );
}
if ($failure instanceof Throwable) {
    throw $failure;
}

echo wp_json_encode([
    'success' => true,
    'token_digest' => hash('sha256', $token),
    'baseline' => $before,
    'restored' => $after,
    'note' => 'Badge catalogue restoration used the FluentCommunity provider option API; token-owned FChub fixtures were removed by exact ID.',
], JSON_PRETTY_PRINT) . PHP_EOL;
