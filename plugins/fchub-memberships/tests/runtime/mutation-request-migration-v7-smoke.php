<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run through the mounted WordPress runtime.\n");
    exit(1);
}

$pluginRoot = dirname(__DIR__, 2);
foreach ([
    '/app/Support/Clock.php',
    '/app/Support/MigrationV7.php',
    '/app/Storage/MutationRequestRepository.php',
    '/app/Http/IdempotentMutation.php',
] as $relativeFile) {
    require_once $pluginRoot . $relativeFile;
}

use FChubMemberships\Http\IdempotentMutation;
use FChubMemberships\Storage\MutationRequestRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Support\MigrationV7;

global $wpdb;

$originalPrefix = $wpdb->prefix;
$databaseVersionBefore = get_option('fchub_memberships_db_version', null);
$productionCountBefore = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$originalPrefix}fchub_membership_mutation_requests"
);
$token = str_replace('-', '', wp_generate_uuid4());
$scratchPrefix = 'wp_fcv7_' . substr($token, 0, 12) . '_';
$table = $scratchPrefix . 'fchub_membership_mutation_requests';
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
$column = static fn(string $name): ?array => $wpdb->get_row($wpdb->prepare(
    "SHOW COLUMNS FROM `{$table}` LIKE %s",
    $name
), ARRAY_A) ?: null;
$indexes = static function () use ($wpdb, $table): array {
    $rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
    $grouped = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $name = (string) $row['Key_name'];
        $grouped[$name]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
        $grouped[$name]['non_unique'] = (int) $row['Non_unique'];
    }
    foreach ($grouped as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);
    return $grouped;
};

try {
    $fail(!$tableExists($table), 'Scratch V7 table already existed.');

    $wpdb->prefix = $scratchPrefix;
    $fail(MigrationV7::run() === [], 'Empty V7 migration reported a failure.');
    $fail($tableExists($table), 'Empty V7 migration did not create the mutation receipt table.');

    $expectedColumns = [
        'lease_token' => ['varchar(64)', 'YES', null],
        'lease_expires_at' => ['datetime', 'YES', null],
        'attempt_count' => ['int(10) unsigned', 'NO', '1'],
        'completed_at' => ['datetime', 'YES', null],
    ];
    foreach ($expectedColumns as $name => [$type, $nullable, $default]) {
        $metadata = $column($name);
        $fail(is_array($metadata), "Empty V7 migration omitted {$name}.");
        $fail(strtolower((string) $metadata['Type']) === $type, "V7 {$name} type drifted.");
        $fail((string) $metadata['Null'] === $nullable, "V7 {$name} nullability drifted.");
        $fail(($metadata['Default'] ?? null) === $default, "V7 {$name} default drifted.");
    }
    $expectedIndexes = [
        'request_key' => [['request_key'], 0],
        'state_updated' => [['state', 'updated_at'], 1],
        'state_lease' => [['state', 'lease_expires_at'], 1],
        'retention_completed' => [['completed_at', 'state', 'id'], 1],
    ];
    foreach ($expectedIndexes as $name => [$columns, $nonUnique]) {
        $fail(
            ($indexes()[$name] ?? null) === ['columns' => $columns, 'non_unique' => $nonUnique],
            "V7 {$name} index drifted."
        );
    }

    $sentinelKey = hash('sha256', $token . '|sentinel');
    $sentinelFingerprint = hash('sha256', $token . '|sentinel-fingerprint');
    $inserted = $wpdb->insert($table, [
        'request_key' => $sentinelKey,
        'fingerprint' => $sentinelFingerprint,
        'user_id' => 1,
        'state' => 'complete',
        'response_status' => 207,
        'response_body' => wp_json_encode(['sentinel' => true]),
        'attempt_count' => 3,
        'created_at' => '2026-07-23 10:00:00',
        'updated_at' => '2026-07-23 10:00:00',
        'completed_at' => '2026-07-23 10:00:00',
    ]);
    $fail($inserted === 1, 'V7 scratch sentinel could not be inserted.');

    $fail(MigrationV7::run() === [], 'Intact V7 replay reported a failure.');
    $fail(
        (int) $wpdb->get_var($wpdb->prepare("SELECT attempt_count FROM `{$table}` WHERE request_key = %s", $sentinelKey)) === 3,
        'Intact V7 replay changed the sentinel attempt count.'
    );

    $fail($wpdb->query("ALTER TABLE `{$table}` DROP INDEX state_lease") !== false, 'Could not remove state_lease.');
    $fail($wpdb->query("ALTER TABLE `{$table}` DROP COLUMN lease_token") !== false, 'Could not remove lease_token.');
    $fail(MigrationV7::run() === [], 'Partial V7 replay reported a failure.');
    $fail(is_array($column('lease_token')), 'Partial V7 replay did not repair lease_token.');
    $fail(
        ($indexes()['state_lease'] ?? null) === [
            'columns' => ['state', 'lease_expires_at'],
            'non_unique' => 1,
        ],
        'Partial V7 replay did not repair state_lease.'
    );
    $fail(
        (int) $wpdb->get_var($wpdb->prepare("SELECT attempt_count FROM `{$table}` WHERE request_key = %s", $sentinelKey)) === 3,
        'Partial V7 replay changed the sentinel attempt count.'
    );

    wp_set_current_user(1);
    $clock = new Clock(
        new DateTimeImmutable('2026-07-23 12:00:00', new DateTimeZone('UTC')),
        new DateTimeZone('UTC')
    );
    $repository = new MutationRequestRepository($clock);
    $request = new WP_REST_Request('POST', '/fchub-memberships/v1/admin/members/grant', [
        'user_id' => 1,
        'plan_id' => 1,
    ]);
    $requestKey = hash('sha256', $token . '|expired-receipt');
    $request->set_header('Idempotency-Key', $requestKey);
    $coordinator = new IdempotentMutation($repository);
    $fingerprint = $coordinator->fingerprint($request, 'grant');
    $oldToken = hash('sha256', $token . '|old-lease');
    $inserted = $wpdb->insert($table, [
        'request_key' => $requestKey,
        'fingerprint' => $fingerprint,
        'user_id' => 1,
        'state' => 'reserved',
        'lease_token' => $oldToken,
        'lease_expires_at' => '2026-07-23 11:59:59',
        'attempt_count' => 1,
        'created_at' => '2026-07-23 11:55:00',
        'updated_at' => '2026-07-23 11:55:00',
    ]);
    $fail($inserted === 1, 'Expired V7 scratch receipt could not be inserted.');

    $newToken = $repository->reserve($requestKey, $fingerprint, 1);
    $fail(is_string($newToken) && $newToken !== $oldToken, 'Expired V7 receipt was not reclaimed with a new token.');
    $reclaimed = $repository->find($requestKey);
    $fail(($reclaimed['attempt_count'] ?? 0) === 2, 'Expired V7 receipt did not increment attempt_count.');
    $fail(($reclaimed['lease_expires_at'] ?? null) === '2026-07-23 12:05:00', 'Reclaimed V7 lease is not exactly five minutes.');
    $fail(!$repository->complete($requestKey, $oldToken, 200, ['stale' => true]), 'Stale V7 worker completed a reclaimed receipt.');
    $fail($repository->complete($requestKey, $newToken, 207, ['reclaimed' => true]), 'Active V7 lease could not complete.');

    $runs = 0;
    $replay = $coordinator->execute($request, 'grant', static function () use (&$runs): WP_REST_Response {
        $runs++;
        return new WP_REST_Response(['unexpected' => true], 500);
    });
    $fail($runs === 0, 'Completed V7 response replay reran the mutation.');
    $fail($replay->get_status() === 207, 'Completed V7 replay changed the stored status.');
    $fail($replay->get_data() === ['reclaimed' => true], 'Completed V7 replay changed the stored body.');
    $fail(($replay->get_headers()['Idempotency-Replayed'] ?? null) === 'true', 'Completed V7 replay omitted its marker.');

    printf(
        "V7 runtime audit: scratch_table=%s request_digest=%s sentinel_digest=%s\n",
        $table,
        hash('sha256', $requestKey),
        hash('sha256', $sentinelKey)
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $wpdb->prefix = $originalPrefix;
    if ($tableExists($table) && $wpdb->query("DROP TABLE `{$table}`") === false) {
        $failure ??= new RuntimeException('Scratch V7 table cleanup failed.');
    }
}

if ($wpdb->prefix !== $originalPrefix) {
    $failure ??= new RuntimeException('The WordPress table prefix was not restored.');
}
if ($tableExists($table)) {
    $failure ??= new RuntimeException('Scratch V7 table remained after cleanup.');
}
if (get_option('fchub_memberships_db_version', null) !== $databaseVersionBefore) {
    $failure ??= new RuntimeException('The production Memberships database version changed during the scratch smoke.');
}
if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$originalPrefix}fchub_membership_mutation_requests") !== $productionCountBefore) {
    $failure ??= new RuntimeException('The production mutation receipt count changed during the scratch smoke.');
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}

echo "V7 MariaDB smoke passed: fresh install, intact replay, partial repair, sentinel preservation, expired lease reclaim, stale-token refusal, completed response replay, exact prefix restoration, unchanged production version/count, and zero scratch tables.\n";
