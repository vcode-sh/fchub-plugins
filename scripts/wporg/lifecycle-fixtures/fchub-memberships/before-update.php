<?php

defined('ABSPATH') || exit;

global $wpdb;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$timestamp = '2026-07-02 10:00:00';
$eventId = '8f14b86a-b3ec-4fe5-a8f7-8a01b694bf15';
$destinationUrl = 'https://hooks.example.test/memberships';
$destinationHash = hash('sha256', $destinationUrl);

$settings = [
    'email_from_name' => 'Vibe Code',
    'webhook_enabled' => 'yes',
    'webhook_endpoints' => [
        [
            'id' => 'wporg_lifecycle_endpoint',
            'name' => 'Lifecycle receiver',
            'url' => $destinationUrl,
            'secret' => 'wporg-lifecycle-secret',
            'status' => 'active',
            'requires_rotation' => false,
            'last_test_status' => 'success',
            'last_tested_at' => '2026-07-02 09:55:00',
        ],
    ],
];
update_option('fchub_memberships_settings', $settings);

$prefix = $wpdb->prefix . 'fchub_membership_';

$assert(
    $wpdb->insert(
        $prefix . 'plans',
        [
            'title' => 'WordPress.org preserved plan',
            'slug' => 'wporg-preserved-plan',
            'description' => 'Lifecycle preservation fixture',
            'status' => 'active',
            'level' => 7,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]
    ) === 1,
    'Could not seed the Memberships plan fixture.'
);
$planId = (int) $wpdb->insert_id;

$assert(
    $wpdb->insert(
        $prefix . 'plan_rules',
        [
            'plan_id' => $planId,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '42',
            'drip_delay_days' => 3,
            'drip_type' => 'relative',
            'sort_order' => 4,
            'meta' => wp_json_encode(['fixture' => 'wporg']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]
    ) === 1,
    'Could not seed the Memberships plan rule fixture.'
);
$ruleId = (int) $wpdb->insert_id;

$grantKey = hash('sha256', 'wporg-lifecycle-grant');
$assert(
    $wpdb->insert(
        $prefix . 'grants',
        [
            'user_id' => 1,
            'plan_id' => $planId,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '42',
            'source_type' => 'manual',
            'source_id' => 701,
            'grant_key' => $grantKey,
            'status' => 'active',
            'source_ids' => wp_json_encode([701]),
            'meta' => wp_json_encode(['fixture' => 'wporg']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]
    ) === 1,
    'Could not seed the Memberships grant fixture.'
);
$grantId = (int) $wpdb->insert_id;

$assert(
    $wpdb->insert(
        $prefix . 'entitlement_edges',
        [
            'user_id' => 1,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '42',
            'plan_id' => $planId,
            'feed_id' => 0,
            'feed_scope' => 'none',
            'source_type' => 'manual',
            'source_id' => 701,
            'owner' => 'memberships',
            'assignment_provenance' => 'direct',
            'lifecycle' => 'active',
            'access_status' => 'active',
            'policy' => wp_json_encode(['grant_id' => $grantId]),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]
    ) === 1,
    'Could not seed the Memberships entitlement edge fixture.'
);
$edgeId = (int) $wpdb->insert_id;

$operationKey = hash('sha256', 'wporg-lifecycle-operation');
$assert(
    $wpdb->insert(
        $prefix . 'provider_operations',
        [
            'edge_id' => $edgeId,
            'operation_key' => $operationKey,
            'desired_action' => 'grant',
            'origin_event' => 'wporg.lifecycle',
            'state' => 'completed',
            'attempt_count' => 1,
            'retryable' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'completed_at' => $timestamp,
        ]
    ) === 1,
    'Could not seed the Memberships provider operation fixture.'
);
$operationId = (int) $wpdb->insert_id;

$assert(
    $wpdb->insert(
        $prefix . 'audit_log',
        [
            'entity_type' => 'grant',
            'entity_id' => $grantId,
            'action' => 'created',
            'actor_id' => 1,
            'actor_type' => 'user',
            'new_value' => wp_json_encode(['grant_key' => $grantKey]),
            'context' => 'wporg.lifecycle',
            'created_at' => $timestamp,
        ]
    ) === 1,
    'Could not seed the Memberships audit fixture.'
);
$auditId = (int) $wpdb->insert_id;

$assert(
    $wpdb->insert(
        $prefix . 'webhook_events',
        [
            'event_id' => $eventId,
            'event_type' => 'grant_created',
            'schema_version' => '1.0',
            'body' => wp_json_encode(['grant_id' => $grantId, 'plan_id' => $planId]),
            'occurred_at' => $timestamp,
            'created_at' => $timestamp,
        ]
    ) === 1,
    'Could not seed the Memberships webhook event fixture.'
);

$assert(
    $wpdb->insert(
        $prefix . 'webhook_deliveries',
        [
            'event_id' => $eventId,
            'destination_url' => $destinationUrl,
            'destination_hash' => $destinationHash,
            'status' => 'delivered',
            'attempt_count' => 1,
            'response_code' => 202,
            'response_body' => 'accepted',
            'last_attempt_at' => $timestamp,
            'delivered_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]
    ) === 1,
    'Could not seed the Memberships webhook delivery fixture.'
);
$deliveryId = (int) $wpdb->insert_id;

$ids = [
    'plan' => $planId,
    'rule' => $ruleId,
    'grant' => $grantId,
    'edge' => $edgeId,
    'operation' => $operationId,
    'audit' => $auditId,
    'delivery' => $deliveryId,
];
update_option('fchub_wporg_memberships_fixture_ids', $ids, false);

$dataSnapshot = [
    'plan' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'plans', $planId),
        ARRAY_A
    ),
    'rule' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'plan_rules', $ruleId),
        ARRAY_A
    ),
    'grant' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'grants', $grantId),
        ARRAY_A
    ),
    'edge' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'entitlement_edges', $edgeId),
        ARRAY_A
    ),
    'operation' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'provider_operations', $operationId),
        ARRAY_A
    ),
    'audit' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'audit_log', $auditId),
        ARRAY_A
    ),
    'webhook_event' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE event_id = %s', $prefix . 'webhook_events', $eventId),
        ARRAY_A
    ),
    'webhook_delivery' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'webhook_deliveries', $deliveryId),
        ARRAY_A
    ),
];
foreach ($dataSnapshot as $fixtureName => $fixtureRow) {
    $assert(is_array($fixtureRow), "Could not snapshot the Memberships {$fixtureName} fixture.");
}
update_option('fchub_wporg_memberships_data_snapshot', $dataSnapshot, false);

$tables = $wpdb->get_col(
    $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%')
);
sort($tables);
$snapshot = [];
foreach ($tables as $table) {
    $row = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N);
    $assert(is_array($row) && isset($row[1]), "Could not snapshot the {$table} schema.");
    $snapshot[$table] = (string) $row[1];
}
update_option('fchub_wporg_memberships_schema_snapshot', $snapshot, false);

$assert(get_option('fchub_memberships_settings') === $settings, 'The Memberships settings fixture did not persist.');
$assert(count($snapshot) === 16, 'The Memberships schema fixture did not find all 16 plugin tables.');

echo "Prepared Memberships migration preservation fixture.\n";
