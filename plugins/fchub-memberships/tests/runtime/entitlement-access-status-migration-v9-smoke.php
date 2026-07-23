<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run through the mounted WordPress runtime.\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/app/Support/MigrationV9.php';

use FChubMemberships\Support\MigrationV9;

global $wpdb;

$originalPrefix = (string) $wpdb->prefix;
$databaseVersionBefore = get_option('fchub_memberships_db_version', null);
$liveTables = [
    'edges' => $originalPrefix . 'fchub_membership_entitlement_edges',
    'grants' => $originalPrefix . 'fchub_membership_grants',
];
$token = str_replace('-', '', wp_generate_uuid4());
$scratchPrefix = 'wp_fcv9_' . substr($token, 0, 12) . '_';
$scratchTables = [
    'edges' => $scratchPrefix . 'fchub_membership_entitlement_edges',
    'grants' => $scratchPrefix . 'fchub_membership_grants',
];
$failure = null;
$assertions = 0;

$fail = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$quoteIdentifier = static function (string $identifier): string {
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new RuntimeException('Unsafe SQL identifier rejected.');
    }

    return '`' . $identifier . '`';
};

$tableExists = static function (string $table) use ($wpdb): bool {
    return $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
    )) === $table;
};

$showCreate = static function (string $table) use ($wpdb, $quoteIdentifier): string {
    $row = $wpdb->get_row('SHOW CREATE TABLE ' . $quoteIdentifier($table), ARRAY_N);
    if (!is_array($row) || !isset($row[1]) || !is_string($row[1])) {
        throw new RuntimeException("SHOW CREATE TABLE failed for {$table}.");
    }

    return $row[1];
};

$tableSnapshot = static function (string $table) use ($wpdb, $quoteIdentifier, $showCreate): array {
    $checksum = $wpdb->get_row('CHECKSUM TABLE ' . $quoteIdentifier($table), ARRAY_N);
    if (!is_array($checksum) || !array_key_exists(1, $checksum)) {
        throw new RuntimeException("CHECKSUM TABLE failed for {$table}.");
    }

    return [
        'create' => $showCreate($table),
        'count' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $quoteIdentifier($table)),
        'checksum' => $checksum[1] === null ? null : (string) $checksum[1],
    ];
};

$columnContract = static function (string $table, string $column) use ($wpdb, $quoteIdentifier): ?array {
    $rows = $wpdb->get_results('SHOW COLUMNS FROM ' . $quoteIdentifier($table), ARRAY_A);
    foreach (is_array($rows) ? $rows : [] as $position => $row) {
        if (($row['Field'] ?? '') !== $column) {
            continue;
        }

        return [
            'type' => strtolower((string) $row['Type']),
            'null' => (string) $row['Null'],
            'default' => $row['Default'] ?? null,
            'position' => $position,
            'previous' => $position > 0 ? (string) $rows[$position - 1]['Field'] : null,
        ];
    }

    return null;
};

$indexContract = static function (string $table, string $index) use ($wpdb, $quoteIdentifier): ?array {
    $rows = $wpdb->get_results(
        'SHOW INDEX FROM ' . $quoteIdentifier($table)
        . ' WHERE Key_name = \'' . esc_sql($index) . '\'',
        ARRAY_A
    );
    if (!is_array($rows) || $rows === []) {
        return null;
    }

    usort($rows, static fn(array $left, array $right): int =>
        (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index']
    );

    return [
        'columns' => array_map(static fn(array $row): string => (string) $row['Column_name'], $rows),
        'unique' => (int) $rows[0]['Non_unique'] === 0,
    ];
};

$rowsSnapshot = static function (string $table) use ($wpdb, $quoteIdentifier): array {
    $rows = $wpdb->get_results(
        'SELECT id, lifecycle, access_status FROM ' . $quoteIdentifier($table) . ' ORDER BY id ASC',
        ARRAY_A
    );

    return is_array($rows) ? $rows : [];
};

$liveBefore = [];
$scratchCreateAfterMigration = '';
$scratchRowsAfterMigration = [];

try {
    $fail(preg_match('/^[a-z0-9_]+$/', $scratchPrefix) === 1, 'The generated V9 scratch prefix is invalid.');
    foreach ($liveTables as $label => $table) {
        $fail($tableExists($table), "The live {$label} table is missing.");
        $liveBefore[$label] = $tableSnapshot($table);
    }
    foreach ($scratchTables as $table) {
        $fail(!$tableExists($table), "Scratch V9 table {$table} already existed.");
    }

    $charset = $wpdb->get_charset_collate();
    $edges = $quoteIdentifier($scratchTables['edges']);
    $grants = $quoteIdentifier($scratchTables['grants']);
    $fail($wpdb->query("CREATE TABLE {$edges} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(50) NOT NULL,
        resource_type VARCHAR(50) NOT NULL,
        resource_id VARCHAR(100) NOT NULL,
        plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lifecycle VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) {$charset}") !== false, 'The legacy V9 scratch entitlement table could not be created.');
    $fail($wpdb->query("CREATE TABLE {$grants} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(50) NOT NULL,
        resource_type VARCHAR(50) NOT NULL,
        resource_id VARCHAR(100) NOT NULL,
        status VARCHAR(20) NOT NULL,
        PRIMARY KEY (id)
    ) {$charset}") !== false, 'The V9 scratch aggregate table could not be created.');

    $fail($columnContract($scratchTables['edges'], 'access_status') === null, 'Legacy scratch schema already had access_status.');
    $fail(
        $indexContract($scratchTables['edges'], 'plan_access_lifecycle_user') === null,
        'Legacy scratch schema already had the V9 access index.'
    );

    $now = current_time('mysql');
    foreach ([
        [900001, 'post', 'paused-resource', 'active'],
        [900002, 'post', 'active-resource', 'active'],
        [900003, 'post', 'ended-resource', 'ended'],
    ] as [$userId, $resourceType, $resourceId, $lifecycle]) {
        $fail($wpdb->insert($scratchTables['edges'], [
            'user_id' => $userId,
            'provider' => 'wordpress_core',
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'plan_id' => 77,
            'lifecycle' => $lifecycle,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1, "The V9 scratch edge {$resourceId} could not be inserted.");
    }
    foreach ([
        [900001, 'post', 'paused-resource', 'paused'],
        [900002, 'post', 'active-resource', 'active'],
        [900003, 'post', 'ended-resource', 'paused'],
    ] as [$userId, $resourceType, $resourceId, $status]) {
        $fail($wpdb->insert($scratchTables['grants'], [
            'user_id' => $userId,
            'provider' => 'wordpress_core',
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'status' => $status,
        ]) === 1, "The V9 scratch aggregate {$resourceId} could not be inserted.");
    }

    $wpdb->prefix = $scratchPrefix;
    $fail(MigrationV9::run() === [], 'The first V9 scratch migration reported a failure.');

    $fail($columnContract($scratchTables['edges'], 'access_status') === [
        'type' => 'varchar(20)',
        'null' => 'NO',
        'default' => 'active',
        'position' => 7,
        'previous' => 'lifecycle',
    ], 'The V9 access_status column contract drifted.');
    $fail($indexContract($scratchTables['edges'], 'plan_access_lifecycle_user') === [
        'columns' => ['plan_id', 'access_status', 'lifecycle', 'user_id'],
        'unique' => false,
    ], 'The V9 plan access index contract drifted.');

    $scratchRowsAfterMigration = $rowsSnapshot($scratchTables['edges']);
    $fail(array_column($scratchRowsAfterMigration, 'access_status', 'id') === [
        1 => 'paused',
        2 => 'active',
        3 => 'active',
    ], 'V9 did not backfill only the proved paused active lineage.');

    $scratchCreateAfterMigration = $showCreate($scratchTables['edges']);
    $fail(MigrationV9::run() === [], 'The intact V9 scratch replay reported a failure.');
    $fail(
        $showCreate($scratchTables['edges']) === $scratchCreateAfterMigration,
        'The intact V9 replay changed scratch schema bytes.'
    );
    $fail(
        $rowsSnapshot($scratchTables['edges']) === $scratchRowsAfterMigration,
        'The intact V9 replay changed scratch lineage state.'
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $wpdb->prefix = $originalPrefix;
    foreach (array_reverse($scratchTables) as $table) {
        try {
            if ($tableExists($table) && $wpdb->query('DROP TABLE ' . $quoteIdentifier($table)) === false) {
                $failure ??= new RuntimeException("Scratch V9 cleanup failed for {$table}.");
            }
        } catch (Throwable $cleanupException) {
            $failure ??= $cleanupException;
        }
    }
}

try {
    $fail($wpdb->prefix === $originalPrefix, 'The WordPress table prefix was not restored.');
    $residue = $wpdb->get_col($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($scratchPrefix) . '%'
    ));
    $fail($residue === [], 'V9 scratch tables remain after cleanup.');
    $fail(
        get_option('fchub_memberships_db_version', null) === $databaseVersionBefore,
        'The live Memberships database version changed during the V9 scratch smoke.'
    );
    foreach ($liveBefore as $label => $snapshot) {
        $fail(
            $tableSnapshot($liveTables[$label]) === $snapshot,
            "The live {$label} table changed during the V9 scratch smoke."
        );
    }
} catch (Throwable $postconditionException) {
    $failure ??= $postconditionException;
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}

printf(
    "V9 MariaDB smoke passed: assertions=%d scratch_prefix=%s schema_digest=%s rows_digest=%s live_state_unchanged=yes residue=0.\n",
    $assertions,
    $scratchPrefix,
    hash('sha256', $scratchCreateAfterMigration),
    hash('sha256', wp_json_encode($scratchRowsAfterMigration))
);
