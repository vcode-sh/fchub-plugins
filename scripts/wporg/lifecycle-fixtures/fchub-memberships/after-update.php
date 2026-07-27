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
$grantKey = hash('sha256', 'wporg-lifecycle-grant');
$operationKey = hash('sha256', 'wporg-lifecycle-operation');
$prefix = $wpdb->prefix . 'fchub_membership_';
$ids = get_option('fchub_wporg_memberships_fixture_ids', []);

$assert(
    is_array($ids) && array_keys($ids) === [
        'plan', 'rule', 'grant', 'edge', 'operation', 'audit', 'delivery',
    ],
    'The Memberships fixture identifiers were not preserved.'
);

$expectedSettings = [
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
$assert(
    get_option('fchub_memberships_settings') === $expectedSettings,
    'The Memberships settings or webhook endpoint changed during the update.'
);

$plan = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT id, title, slug, status, level FROM %i WHERE id = %d',
        $prefix . 'plans',
        $ids['plan']
    ),
    ARRAY_A
);
$assert(
    $plan === [
        'id' => (string) $ids['plan'],
        'title' => 'WordPress.org preserved plan',
        'slug' => 'wporg-preserved-plan',
        'status' => 'active',
        'level' => '7',
    ],
    'The Memberships plan changed during the update.'
);

$rule = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT id, plan_id, provider, resource_type, resource_id, drip_delay_days, drip_type
         FROM %i WHERE id = %d',
        $prefix . 'plan_rules',
        $ids['rule']
    ),
    ARRAY_A
);
$assert(
    $rule === [
        'id' => (string) $ids['rule'],
        'plan_id' => (string) $ids['plan'],
        'provider' => 'wordpress_core',
        'resource_type' => 'post',
        'resource_id' => '42',
        'drip_delay_days' => '3',
        'drip_type' => 'relative',
    ],
    'The Memberships plan rule changed during the update.'
);

$grant = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT id, user_id, plan_id, provider, resource_type, resource_id, source_type,
                source_id, grant_key, status
         FROM %i WHERE id = %d',
        $prefix . 'grants',
        $ids['grant']
    ),
    ARRAY_A
);
$assert(
    $grant === [
        'id' => (string) $ids['grant'],
        'user_id' => '1',
        'plan_id' => (string) $ids['plan'],
        'provider' => 'wordpress_core',
        'resource_type' => 'post',
        'resource_id' => '42',
        'source_type' => 'manual',
        'source_id' => '701',
        'grant_key' => $grantKey,
        'status' => 'active',
    ],
    'The Memberships grant changed during the update.'
);

$operation = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT operation.id, operation.edge_id, operation.operation_key,
                operation.desired_action, operation.origin_event, operation.state,
                edge.plan_id, edge.user_id, edge.access_status, edge.lifecycle
         FROM %i operation
         INNER JOIN %i edge ON edge.id = operation.edge_id
         WHERE operation.id = %d',
        $prefix . 'provider_operations',
        $prefix . 'entitlement_edges',
        $ids['operation']
    ),
    ARRAY_A
);
$assert(
    $operation === [
        'id' => (string) $ids['operation'],
        'edge_id' => (string) $ids['edge'],
        'operation_key' => $operationKey,
        'desired_action' => 'grant',
        'origin_event' => 'wporg.lifecycle',
        'state' => 'completed',
        'plan_id' => (string) $ids['plan'],
        'user_id' => '1',
        'access_status' => 'active',
        'lifecycle' => 'active',
    ],
    'The Memberships provider operation or entitlement edge changed during the update.'
);

$audit = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT id, entity_type, entity_id, action, actor_id, actor_type, context, created_at
         FROM %i WHERE id = %d',
        $prefix . 'audit_log',
        $ids['audit']
    ),
    ARRAY_A
);
$assert(
    $audit === [
        'id' => (string) $ids['audit'],
        'entity_type' => 'grant',
        'entity_id' => (string) $ids['grant'],
        'action' => 'created',
        'actor_id' => '1',
        'actor_type' => 'user',
        'context' => 'wporg.lifecycle',
        'created_at' => $timestamp,
    ],
    'The Memberships audit record changed during the update.'
);

$webhook = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT event.event_id, event.event_type, event.schema_version,
                delivery.destination_url, delivery.destination_hash, delivery.status,
                delivery.attempt_count, delivery.response_code
         FROM %i event
         INNER JOIN %i delivery ON delivery.event_id = event.event_id
         WHERE event.event_id = %s AND delivery.id = %d',
        $prefix . 'webhook_events',
        $prefix . 'webhook_deliveries',
        $eventId,
        $ids['delivery']
    ),
    ARRAY_A
);
$assert(
    $webhook === [
        'event_id' => $eventId,
        'event_type' => 'grant_created',
        'schema_version' => '1.0',
        'destination_url' => $destinationUrl,
        'destination_hash' => $destinationHash,
        'status' => 'delivered',
        'attempt_count' => '1',
        'response_code' => '202',
    ],
    'The Memberships webhook event or delivery changed during the update.'
);

$beforeData = get_option('fchub_wporg_memberships_data_snapshot', []);
$afterData = [
    'plan' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'plans', $ids['plan']),
        ARRAY_A
    ),
    'rule' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'plan_rules', $ids['rule']),
        ARRAY_A
    ),
    'grant' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'grants', $ids['grant']),
        ARRAY_A
    ),
    'edge' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'entitlement_edges', $ids['edge']),
        ARRAY_A
    ),
    'operation' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'provider_operations', $ids['operation']),
        ARRAY_A
    ),
    'audit' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'audit_log', $ids['audit']),
        ARRAY_A
    ),
    'webhook_event' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE event_id = %s', $prefix . 'webhook_events', $eventId),
        ARRAY_A
    ),
    'webhook_delivery' => $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'webhook_deliveries', $ids['delivery']),
        ARRAY_A
    ),
];
$assert($beforeData === $afterData, 'Representative Memberships rows drifted during the update.');

$beforeSchema = get_option('fchub_wporg_memberships_schema_snapshot', []);
$tables = $wpdb->get_col(
    $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%')
);
sort($tables);
$afterSchema = [];
foreach ($tables as $table) {
    $row = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N);
    $assert(is_array($row) && isset($row[1]), "Could not inspect the {$table} schema after update.");
    $afterSchema[$table] = (string) $row[1];
}
$assert(count($afterSchema) === 16, 'The Memberships schema no longer contains all 16 plugin tables.');
$assert($beforeSchema === $afterSchema, 'The Memberships table schema drifted during the update.');
$assert(
    get_option('fchub_memberships_db_version') === '1.9.0',
    'The Memberships database version changed during the update.'
);
$assert(
    \FChubMemberships\Support\Migrations::verifySchema() === [],
    'The Memberships schema verifier reported drift after the update.'
);

delete_option('fchub_wporg_memberships_fixture_ids');
delete_option('fchub_wporg_memberships_data_snapshot');
delete_option('fchub_wporg_memberships_schema_snapshot');

echo "Verified Memberships migration preservation fixture.\n";
