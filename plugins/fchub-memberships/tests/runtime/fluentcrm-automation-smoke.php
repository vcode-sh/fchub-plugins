<?php

use FChubMemberships\FluentCRM\FluentCrmAutomation;
use FChubMemberships\FluentCRM\Filters\MembershipFilters;

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

$smartCodeGroups = apply_filters('fluent_crm_funnel_context_smart_codes', []);
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
$grantIds = [];
$contactId = 0;
global $wpdb;
$wpdb->query('START TRANSACTION');
try {
    $settings = get_option('fchub_memberships_settings', []);
    update_option('fchub_memberships_settings', array_merge($settings, [
        'membership_mode' => 'stack',
        'email_access_granted' => 'no',
        'email_access_revoked' => 'no',
        'email_membership_paused' => 'no',
        'email_membership_resumed' => 'no',
        'fluentcrm_enabled' => 'yes',
        'fluentcrm_default_list' => '',
        'fluentcrm_auto_create_tags' => 'no',
    ]));
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
    (new \FChubMemberships\Storage\PlanRuleRepository())->create([
        'plan_id' => $planId,
        'provider' => 'wordpress_core',
        'resource_type' => 'post',
        'resource_id' => (string) $postId,
        'drip_type' => 'immediate',
    ]);

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
    if ($grantId <= 0 || (int) ($grants[0]['renewal_count'] ?? 0) !== 1) {
        throw new RuntimeException('Disposable renewal repository truth was not visible.');
    }
    if (empty($access->pauseGrant($grantId, 'runtime smoke')['success'])) {
        throw new RuntimeException('Disposable membership could not be paused.');
    }
    if (empty($access->resumeGrant($grantId)['success'])) {
        throw new RuntimeException('Disposable membership could not be resumed.');
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
} finally {
    $wpdb->query('ROLLBACK');
    if ($contactId > 0) {
        $wpdb->delete($wpdb->prefix . 'fc_subscriber_pivot', ['subscriber_id' => $contactId]);
        $wpdb->delete($wpdb->prefix . 'fc_subscribers', ['id' => $contactId]);
    } else {
        $wpdb->delete($wpdb->prefix . 'fc_subscribers', ['email' => $email]);
    }
    foreach ($grantIds as $grantId) {
        $wpdb->delete($wpdb->prefix . 'fchub_membership_audit_log', ['entity_type' => 'grant', 'entity_id' => $grantId]);
        $wpdb->delete($wpdb->prefix . 'fchub_membership_grant_sources', ['grant_id' => $grantId]);
        $wpdb->delete($wpdb->prefix . 'fchub_membership_drip_notifications', ['grant_id' => $grantId]);
    }
    if ($userId > 0) {
        $wpdb->delete($wpdb->prefix . 'fchub_membership_grants', ['user_id' => $userId]);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userId);
    }
    if ($planId > 0) {
        $wpdb->delete($wpdb->prefix . 'fchub_membership_plan_rules', ['plan_id' => $planId]);
        $wpdb->delete($wpdb->prefix . 'fchub_membership_plans', ['id' => $planId]);
    }
    if ($postId > 0) {
        wp_delete_post($postId, true);
    }
}

echo "FluentCRM automation smoke passed: 16 triggers, 7 actions, 7 benchmarks, 25 smart codes, 6 executable filters, every editor schema, and a cleaned disposable lifecycle/projection.\n";
