<?php

use FChubMemberships\Support\MigrationV6;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run through the mounted WordPress runtime.\n");
    exit(1);
}

global $wpdb;

$originalPrefix = $wpdb->prefix;
$databaseVersionBefore = get_option('fchub_memberships_db_version', null);
$token = str_replace('-', '', wp_generate_uuid4());
$scratchPrefix = 'wp_fcv6_' . substr($token, 0, 12) . '_';
$table = $scratchPrefix . 'fchub_membership_crm_projection_jobs';
$failure = null;

$fail = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$tableExists = static fn(string $name): bool => $wpdb->get_var($wpdb->prepare(
    'SHOW TABLES LIKE %s',
    $wpdb->esc_like($name)
)) === $name;
$columnExists = static fn(string $name): bool => is_array($wpdb->get_row($wpdb->prepare(
    "SHOW COLUMNS FROM `{$table}` LIKE %s",
    $name
), ARRAY_A));
$indexExists = static function (string $name) use ($wpdb, $table): bool {
    $rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
    return is_array($rows) && array_filter(
        $rows,
        static fn(array $row): bool => ($row['Key_name'] ?? '') === $name
    ) !== [];
};

try {
    $fail(!$tableExists($table), 'Scratch V6 table already existed.');

    $wpdb->prefix = $scratchPrefix;
    $fail(MigrationV6::run() === [], 'Empty V6 migration reported a failure.');
    $fail($tableExists($table), 'Empty V6 migration did not create the projection job table.');
    foreach (['user_id', 'request_version', 'last_attempt_at', 'last_success_at'] as $column) {
        $fail($columnExists($column), "Empty V6 migration omitted {$column}.");
    }
    foreach (['PRIMARY', 'status_due', 'status_lease', 'last_success'] as $index) {
        $fail($indexExists($index), "Empty V6 migration omitted {$index}.");
    }

    $now = current_time('mysql');
    $inserted = $wpdb->insert($table, [
        'user_id' => 987654321,
        'status' => 'succeeded',
        'request_version' => 7,
        'attempt_count' => 2,
        'last_attempt_at' => $now,
        'last_success_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $fail($inserted === 1, 'V6 scratch sentinel could not be inserted.');

    $fail(MigrationV6::run() === [], 'Intact V6 replay reported a failure.');
    $fail(
        (int) $wpdb->get_var("SELECT request_version FROM `{$table}` WHERE user_id = 987654321") === 7,
        'Intact V6 replay changed the sentinel request version.'
    );

    $fail($wpdb->query("ALTER TABLE `{$table}` DROP INDEX status_due") !== false, 'Could not remove status_due.');
    $fail($wpdb->query("ALTER TABLE `{$table}` DROP COLUMN last_attempt_at") !== false, 'Could not remove last_attempt_at.');

    $fail(MigrationV6::run() === [], 'Partial V6 replay reported a failure.');
    $fail($indexExists('status_due'), 'Partial V6 replay did not repair status_due.');
    $fail($columnExists('last_attempt_at'), 'Partial V6 replay did not repair last_attempt_at.');
    $fail(
        (int) $wpdb->get_var("SELECT request_version FROM `{$table}` WHERE user_id = 987654321") === 7,
        'Partial V6 replay changed the sentinel request version.'
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $wpdb->prefix = $originalPrefix;
    if ($tableExists($table) && $wpdb->query("DROP TABLE `{$table}`") === false) {
        $failure ??= new RuntimeException('Scratch V6 table cleanup failed.');
    }
}

if ($wpdb->prefix !== $originalPrefix) {
    $failure ??= new RuntimeException('The WordPress table prefix was not restored.');
}
if ($tableExists($table)) {
    $failure ??= new RuntimeException('Scratch V6 table remained after cleanup.');
}
if (get_option('fchub_memberships_db_version', null) !== $databaseVersionBefore) {
    $failure ??= new RuntimeException('The production Memberships database version changed during the scratch smoke.');
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}

echo "V6 MariaDB smoke passed: empty install, intact replay, partial schema repair, sentinel preservation, exact prefix restoration, unchanged production version, and zero scratch tables.\n";
