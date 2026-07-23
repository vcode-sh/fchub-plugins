<?php

use FChubMemberships\Support\MigrationV5;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run through the mounted WordPress runtime.\n");
    exit(1);
}

global $wpdb;

$originalPrefix = $wpdb->prefix;
$databaseVersionBefore = get_option('fchub_memberships_db_version', null);
$token = str_replace('-', '', wp_generate_uuid4());
$scratchPrefix = 'wp_fcv5_' . substr($token, 0, 12) . '_';
$edgesTable = $scratchPrefix . 'fchub_membership_entitlement_edges';
$operationsTable = $scratchPrefix . 'fchub_membership_provider_operations';
$failure = null;

$fail = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tableExists = static function (string $table) use ($wpdb): bool {
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
};

$indexExists = static function (string $table, string $index) use ($wpdb): bool {
    $rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
    return is_array($rows) && array_filter(
        $rows,
        static fn(array $row): bool => ($row['Key_name'] ?? '') === $index
    ) !== [];
};

$columnExists = static function (string $table, string $column) use ($wpdb): bool {
    $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column), ARRAY_A);
    return is_array($row);
};

$foreignKeyContract = static function () use ($wpdb, $operationsTable, $edgesTable): ?array {
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE kcu
         INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
           ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
          AND rc.TABLE_NAME = kcu.TABLE_NAME
          AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         WHERE kcu.CONSTRAINT_SCHEMA = %s
           AND kcu.TABLE_NAME = %s
           AND kcu.CONSTRAINT_NAME = 'fk_provider_operations_edge'",
        $wpdb->dbname,
        $operationsTable
    ), ARRAY_A);

    if (!is_array($row)) {
        return null;
    }

    return [
        'column' => (string) $row['COLUMN_NAME'],
        'table' => (string) $row['REFERENCED_TABLE_NAME'],
        'referenced_column' => (string) $row['REFERENCED_COLUMN_NAME'],
        'delete_rule' => (string) $row['DELETE_RULE'],
        'expected_table' => $edgesTable,
    ];
};

try {
    $fail(!$tableExists($edgesTable) && !$tableExists($operationsTable), 'Scratch V5 tables already existed.');

    $wpdb->prefix = $scratchPrefix;
    $fail(MigrationV5::run() === [], 'Empty V5 migration reported a failure.');
    $fail($tableExists($edgesTable) && $tableExists($operationsTable), 'Empty V5 migration did not create both tables.');
    $fail($columnExists($operationsTable, 'completed_at'), 'Empty V5 migration omitted completed_at.');
    $fail($indexExists($operationsTable, 'state_eligible'), 'Empty V5 migration omitted state_eligible.');
    $fail($indexExists($edgesTable, 'entitlement_identity'), 'Empty V5 migration omitted entitlement_identity.');

    $foreignKey = $foreignKeyContract();
    $fail(
        $foreignKey === [
            'column' => 'edge_id',
            'table' => $edgesTable,
            'referenced_column' => 'id',
            'delete_rule' => 'RESTRICT',
            'expected_table' => $edgesTable,
        ],
        'Empty V5 migration did not create the exact RESTRICT foreign key.'
    );

    $now = current_time('mysql');
    $insertedEdge = $wpdb->insert($edgesTable, [
        'user_id' => 987654321,
        'provider' => 'fluent_community',
        'resource_type' => 'fc_space',
        'resource_id' => 'runtime-sentinel',
        'plan_id' => 7654321,
        'feed_id' => 0,
        'feed_scope' => 'external_unknown',
        'source_type' => 'manual',
        'source_id' => 0,
        'owner' => 'external_unknown',
        'assignment_provenance' => 'unknown',
        'lifecycle' => 'ended',
        'end_reason' => 'runtime_sentinel',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $fail($insertedEdge === 1, 'V5 scratch sentinel edge could not be inserted.');
    $edgeId = (int) $wpdb->insert_id;

    $insertedOperation = $wpdb->insert($operationsTable, [
        'edge_id' => $edgeId,
        'operation_key' => hash('sha256', $token . '|operation'),
        'desired_action' => 'revoke',
        'origin_event' => 'runtime_migration_smoke',
        'state' => 'applied',
        'attempt_count' => 1,
        'retryable' => 0,
        'eligible_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => $now,
    ]);
    $fail($insertedOperation === 1, 'V5 scratch sentinel operation could not be inserted.');

    $fail(MigrationV5::run() === [], 'Intact V5 replay reported a failure.');
    $fail((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$edgesTable}` WHERE id = {$edgeId}") === 1, 'Intact replay lost the sentinel edge.');
    $fail((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$operationsTable}` WHERE edge_id = {$edgeId}") === 1, 'Intact replay lost the sentinel operation.');

    $fail($wpdb->query("ALTER TABLE `{$operationsTable}` DROP FOREIGN KEY fk_provider_operations_edge") !== false, 'Could not remove the scratch foreign key.');
    $fail($wpdb->query("ALTER TABLE `{$operationsTable}` DROP INDEX state_eligible") !== false, 'Could not remove the scratch state_eligible index.');
    $fail($wpdb->query("ALTER TABLE `{$operationsTable}` DROP COLUMN completed_at") !== false, 'Could not remove the scratch completed_at column.');
    $fail($wpdb->query("ALTER TABLE `{$edgesTable}` DROP INDEX lifecycle_drip") !== false, 'Could not remove the scratch lifecycle_drip index.');

    $fail(MigrationV5::run() === [], 'Partial V5 replay reported a failure.');
    $fail($columnExists($operationsTable, 'completed_at'), 'Partial V5 replay did not repair completed_at.');
    $fail($indexExists($operationsTable, 'state_eligible'), 'Partial V5 replay did not repair state_eligible.');
    $fail($indexExists($edgesTable, 'lifecycle_drip'), 'Partial V5 replay did not repair lifecycle_drip.');
    $repairedForeignKey = $foreignKeyContract();
    $fail(
        $repairedForeignKey === [
            'column' => 'edge_id',
            'table' => $edgesTable,
            'referenced_column' => 'id',
            'delete_rule' => 'RESTRICT',
            'expected_table' => $edgesTable,
        ],
        'Partial V5 replay did not repair the exact RESTRICT foreign key.'
    );
    $fail((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$edgesTable}` WHERE id = {$edgeId}") === 1, 'Partial replay lost the sentinel edge.');
    $fail((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$operationsTable}` WHERE edge_id = {$edgeId}") === 1, 'Partial replay lost the sentinel operation.');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $wpdb->prefix = $originalPrefix;
    if ($tableExists($operationsTable) && $wpdb->query("DROP TABLE `{$operationsTable}`") === false) {
        $failure ??= new RuntimeException('Scratch provider_operations cleanup failed.');
    }
    if ($tableExists($edgesTable) && $wpdb->query("DROP TABLE `{$edgesTable}`") === false) {
        $failure ??= new RuntimeException('Scratch entitlement_edges cleanup failed.');
    }
}

if ($wpdb->prefix !== $originalPrefix) {
    $failure ??= new RuntimeException('The WordPress table prefix was not restored.');
}
if ($tableExists($operationsTable) || $tableExists($edgesTable)) {
    $failure ??= new RuntimeException('Scratch V5 tables remained after cleanup.');
}
if (get_option('fchub_memberships_db_version', null) !== $databaseVersionBefore) {
    $failure ??= new RuntimeException('The production Memberships database version changed during the scratch smoke.');
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}

echo "V5 MariaDB smoke passed: empty install, intact replay, partial schema repair, sentinel preservation, RESTRICT foreign key, exact prefix restoration, and zero scratch tables.\n";
