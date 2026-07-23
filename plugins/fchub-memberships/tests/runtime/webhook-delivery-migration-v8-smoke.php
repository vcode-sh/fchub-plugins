<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run through the mounted WordPress runtime.\n");
    exit(1);
}

$pluginRoot = dirname(__DIR__, 2);
foreach ([
    '/app/Http/AccessApiCredential.php',
    '/app/Integration/MembershipSettingsOptionCoordinator.php',
    '/app/Support/Migrations.php',
    '/app/Support/MigrationV8.php',
] as $relativeFile) {
    require_once $pluginRoot . $relativeFile;
}

use FChubMemberships\Http\AccessApiCredential;
use FChubMemberships\Support\MigrationV8;

global $wpdb;

$failure = null;
$originalPrefix = (string) $wpdb->prefix;
$originalBasePrefix = (string) $wpdb->base_prefix;
$originalOptionsTable = (string) $wpdb->options;
$originalBlogId = (int) $wpdb->blogid;
$token = str_replace('-', '', wp_generate_uuid4());
$scratchPrefix = 'wp_fcv8_' . substr($token, 0, 12) . '_';
$scratchTables = [
    'options' => $scratchPrefix . 'options',
    'events' => $scratchPrefix . 'fchub_membership_webhook_events',
    'deliveries' => $scratchPrefix . 'fchub_membership_webhook_deliveries',
    'grants' => $scratchPrefix . 'fchub_membership_grants',
    'event_locks' => $scratchPrefix . 'fchub_membership_event_locks',
    'mutation_requests' => $scratchPrefix . 'fchub_membership_mutation_requests',
];
$liveTables = [
    'grants' => $originalPrefix . 'fchub_membership_grants',
    'event_locks' => $originalPrefix . 'fchub_membership_event_locks',
    'mutation_requests' => $originalPrefix . 'fchub_membership_mutation_requests',
];
$settingsOption = 'fchub_memberships_settings';
$versionOption = 'fchub_memberships_db_version';
$prefixSwitched = false;
$liveOptionsBefore = [];
$liveTablesBefore = [];

$fail = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$recordFailure = static function (bool $condition, string $message) use (&$failure): void {
    if (!$condition && $failure === null) {
        $failure = new RuntimeException($message);
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

$optionRow = static function (string $table, string $optionName) use ($wpdb, $quoteIdentifier): ?array {
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT option_value, autoload FROM ' . $quoteIdentifier($table) . ' WHERE option_name = %s LIMIT 1',
        $optionName
    ), ARRAY_A);

    return is_array($row) ? $row : null;
};

$showCreate = static function (string $table) use ($wpdb, $quoteIdentifier): string {
    $row = $wpdb->get_row('SHOW CREATE TABLE ' . $quoteIdentifier($table), ARRAY_N);
    if (!is_array($row) || !isset($row[1]) || !is_string($row[1])) {
        throw new RuntimeException("SHOW CREATE TABLE failed for {$table}.");
    }

    return $row[1];
};

$tableSnapshot = static function (string $table) use ($wpdb, $quoteIdentifier, $showCreate): array {
    $checksumRow = $wpdb->get_row('CHECKSUM TABLE ' . $quoteIdentifier($table), ARRAY_N);
    if (!is_array($checksumRow) || !array_key_exists(1, $checksumRow)) {
        throw new RuntimeException("CHECKSUM TABLE failed for {$table}.");
    }

    return [
        'create' => $showCreate($table),
        'count' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $quoteIdentifier($table)),
        'checksum' => $checksumRow[1] === null ? null : (string) $checksumRow[1],
    ];
};

$sentinelSnapshot = static function (string $table) use ($wpdb, $quoteIdentifier, $showCreate): array {
    return [
        'create' => $showCreate($table),
        'rows' => $wpdb->get_results(
            'SELECT id, sentinel FROM ' . $quoteIdentifier($table) . ' ORDER BY id ASC',
            ARRAY_A
        ),
    ];
};

$columns = static function (string $table) use ($wpdb, $quoteIdentifier): array {
    $rows = $wpdb->get_results('SHOW COLUMNS FROM ' . $quoteIdentifier($table), ARRAY_A);
    $result = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $result[(string) $row['Field']] = [
            'type' => strtolower((string) $row['Type']),
            'null' => (string) $row['Null'],
            'default' => $row['Default'] ?? null,
            'extra' => strtolower((string) ($row['Extra'] ?? '')),
        ];
    }

    return $result;
};

$indexes = static function (string $table) use ($wpdb, $quoteIdentifier): array {
    $rows = $wpdb->get_results('SHOW INDEX FROM ' . $quoteIdentifier($table), ARRAY_A);
    $result = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $name = (string) $row['Key_name'];
        $result[$name]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
        $result[$name]['non_unique'] = (int) $row['Non_unique'];
    }
    foreach ($result as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);

    return $result;
};

$assertColumn = static function (
    array $actual,
    string $name,
    string $typePattern,
    string $nullable,
    mixed $default,
    string $extra = ''
) use ($fail): void {
    $fail(isset($actual[$name]), "V8 schema omitted {$name}.");
    $fail(
        preg_match($typePattern, $actual[$name]['type']) === 1,
        "V8 {$name} type drifted: {$actual[$name]['type']}."
    );
    $fail($actual[$name]['null'] === $nullable, "V8 {$name} nullability drifted.");
    $fail($actual[$name]['default'] === $default, "V8 {$name} default drifted.");
    $fail($actual[$name]['extra'] === $extra, "V8 {$name} extra metadata drifted.");
};

$assertIndexes = static function (array $actual, array $expected, string $tableLabel) use ($fail): void {
    ksort($actual);
    ksort($expected);
    $fail($actual === $expected, "V8 {$tableLabel} indexes drifted.");
};

$clearSettingsCaches = static function () use ($settingsOption, $versionOption): void {
    wp_cache_delete($settingsOption, 'options');
    wp_cache_delete($versionOption, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
};

try {
    $fail(!is_multisite(), 'The V8 scratch smoke supports single-site WordPress only.');
    $fail(!wp_using_ext_object_cache(), 'External object caching must be disabled for the V8 scratch smoke.');
    $fail($originalPrefix === $originalBasePrefix, 'The active and base table prefixes differ.');
    $fail($originalOptionsTable === $originalPrefix . 'options', 'The live options table does not match the active prefix.');
    $fail(preg_match('/^[a-z0-9_]+$/', $scratchPrefix) === 1, 'The generated V8 scratch prefix is invalid.');

    foreach ($liveTables as $label => $table) {
        $fail($tableExists($table), "The live {$label} table is missing.");
        $liveTablesBefore[$label] = $tableSnapshot($table);
    }
    $liveOptionsBefore = [
        $settingsOption => $optionRow($originalOptionsTable, $settingsOption),
        $versionOption => $optionRow($originalOptionsTable, $versionOption),
    ];
    $fail(is_array($liveOptionsBefore[$settingsOption]), 'The live Memberships settings option is missing.');
    $fail(is_array($liveOptionsBefore[$versionOption]), 'The live Memberships database version option is missing.');

    foreach ($scratchTables as $table) {
        $fail(!$tableExists($table), "Scratch table collision: {$table}.");
    }

    $charset = $wpdb->get_charset_collate();
    $fail(
        $wpdb->query(
            'CREATE TABLE ' . $quoteIdentifier($scratchTables['options'])
            . ' LIKE ' . $quoteIdentifier($originalOptionsTable)
        ) !== false,
        'The scratch options table could not be cloned.'
    );

    foreach (['grants', 'event_locks', 'mutation_requests'] as $label) {
        $table = $quoteIdentifier($scratchTables[$label]);
        $fail($wpdb->query("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL,
            sentinel CHAR(64) NOT NULL,
            PRIMARY KEY (id)
        ) {$charset}") !== false, "The scratch {$label} sentinel table could not be created.");
        $fail($wpdb->insert($scratchTables[$label], [
            'id' => 1,
            'sentinel' => hash('sha256', $token . '|' . $label),
        ], ['%d', '%s']) === 1, "The scratch {$label} sentinel row could not be inserted.");
    }

    $legacySecret = 'fchub_' . bin2hex(random_bytes(24));
    $webhookSecret = bin2hex(random_bytes(32));
    $scratchSettings = [
        'api_key' => $legacySecret,
        'webhook_enabled' => 'no',
        'webhook_urls' => 'https://example.test/runtime-v8',
        'webhook_secret' => $webhookSecret,
        'runtime_v8_sentinel' => hash('sha256', $token . '|settings'),
    ];
    $fail($wpdb->insert($scratchTables['options'], [
        'option_name' => $settingsOption,
        'option_value' => maybe_serialize($scratchSettings),
        'autoload' => 'auto',
    ], ['%s', '%s', '%s']) === 1, 'The scratch Memberships settings option could not be inserted.');
    $fail($wpdb->insert($scratchTables['options'], [
        'option_name' => $versionOption,
        'option_value' => '1.7.0',
        'autoload' => 'auto',
    ], ['%s', '%s', '%s']) === 1, 'The scratch Memberships database version could not be inserted.');

    $sentinelsBefore = [];
    foreach (['grants', 'event_locks', 'mutation_requests'] as $label) {
        $sentinelsBefore[$label] = $sentinelSnapshot($scratchTables[$label]);
    }

    $setPrefixResult = $wpdb->set_prefix($scratchPrefix);
    $fail(!is_wp_error($setPrefixResult), 'WordPress rejected the V8 scratch prefix.');
    $prefixSwitched = true;
    $fail($wpdb->prefix === $scratchPrefix, 'The active table prefix did not switch to the V8 scratch prefix.');
    $fail($wpdb->options === $scratchTables['options'], 'The options table did not switch to the V8 scratch table.');
    $clearSettingsCaches();

    $firstRun = MigrationV8::run();
    $fail($firstRun === [], 'The first V8 scratch migration reported a failure.');
    $fail($tableExists($scratchTables['events']), 'V8 did not create the scratch webhook events table.');
    $fail($tableExists($scratchTables['deliveries']), 'V8 did not create the scratch webhook deliveries table.');

    $eventsCreate = $showCreate($scratchTables['events']);
    $deliveriesCreate = $showCreate($scratchTables['deliveries']);
    echo "V8 scratch SHOW CREATE webhook_events:\n{$eventsCreate}\n";
    echo "V8 scratch SHOW CREATE webhook_deliveries:\n{$deliveriesCreate}\n";
    $fail(str_contains($eventsCreate, 'ENGINE=InnoDB'), 'V8 webhook_events is not InnoDB.');
    $fail(str_contains($deliveriesCreate, 'ENGINE=InnoDB'), 'V8 webhook_deliveries is not InnoDB.');

    $eventsColumns = $columns($scratchTables['events']);
    $fail(array_keys($eventsColumns) === [
        'id',
        'event_id',
        'event_type',
        'schema_version',
        'body',
        'occurred_at',
        'created_at',
    ], 'V8 webhook_events columns drifted.');
    $assertColumn($eventsColumns, 'id', '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment');
    $assertColumn($eventsColumns, 'event_id', '/^char\(36\)$/', 'NO', null);
    $assertColumn($eventsColumns, 'event_type', '/^varchar\(64\)$/', 'NO', null);
    $assertColumn($eventsColumns, 'schema_version', '/^varchar\(10\)$/', 'NO', '1.0');
    $assertColumn($eventsColumns, 'body', '/^longtext$/', 'NO', null);
    $assertColumn($eventsColumns, 'occurred_at', '/^datetime$/', 'NO', null);
    $assertColumn($eventsColumns, 'created_at', '/^datetime$/', 'NO', null);
    $assertIndexes($indexes($scratchTables['events']), [
        'PRIMARY' => ['columns' => ['id'], 'non_unique' => 0],
        'event_id' => ['columns' => ['event_id'], 'non_unique' => 0],
        'type_occurred' => ['columns' => ['event_type', 'occurred_at'], 'non_unique' => 1],
    ], 'webhook_events');

    $deliveriesColumns = $columns($scratchTables['deliveries']);
    $fail(array_keys($deliveriesColumns) === [
        'id',
        'event_id',
        'destination_url',
        'destination_hash',
        'status',
        'attempt_count',
        'lease_owner',
        'lease_expires_at',
        'response_code',
        'response_body',
        'error_message',
        'next_attempt_at',
        'last_attempt_at',
        'delivered_at',
        'created_at',
        'updated_at',
    ], 'V8 webhook_deliveries columns drifted.');
    $assertColumn($deliveriesColumns, 'id', '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment');
    $assertColumn($deliveriesColumns, 'event_id', '/^char\(36\)$/', 'NO', null);
    $assertColumn($deliveriesColumns, 'destination_url', '/^varchar\(2048\)$/', 'NO', null);
    $assertColumn($deliveriesColumns, 'destination_hash', '/^char\(64\)$/', 'NO', null);
    $assertColumn($deliveriesColumns, 'status', '/^varchar\(20\)$/', 'NO', 'pending');
    $assertColumn($deliveriesColumns, 'attempt_count', '/^smallint(?:\(5\))? unsigned$/', 'NO', '0');
    $assertColumn($deliveriesColumns, 'lease_owner', '/^varchar\(64\)$/', 'YES', null);
    $assertColumn($deliveriesColumns, 'lease_expires_at', '/^datetime$/', 'YES', null);
    $assertColumn($deliveriesColumns, 'response_code', '/^smallint(?:\(5\))? unsigned$/', 'YES', null);
    $assertColumn($deliveriesColumns, 'response_body', '/^text$/', 'YES', null);
    $assertColumn($deliveriesColumns, 'error_message', '/^text$/', 'YES', null);
    $assertColumn($deliveriesColumns, 'next_attempt_at', '/^datetime$/', 'YES', null);
    $assertColumn($deliveriesColumns, 'last_attempt_at', '/^datetime$/', 'YES', null);
    $assertColumn($deliveriesColumns, 'delivered_at', '/^datetime$/', 'YES', null);
    $assertColumn($deliveriesColumns, 'created_at', '/^datetime$/', 'NO', null);
    $assertColumn($deliveriesColumns, 'updated_at', '/^datetime$/', 'NO', null);
    $assertIndexes($indexes($scratchTables['deliveries']), [
        'PRIMARY' => ['columns' => ['id'], 'non_unique' => 0],
        'event_destination' => ['columns' => ['event_id', 'destination_hash'], 'non_unique' => 0],
        'status_next' => ['columns' => ['status', 'next_attempt_at'], 'non_unique' => 1],
        'status_lease' => ['columns' => ['status', 'lease_expires_at'], 'non_unique' => 1],
        'created_at' => ['columns' => ['created_at'], 'non_unique' => 1],
    ], 'webhook_deliveries');

    $foreignKeyCount = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME IN (%s, %s)',
        $wpdb->dbname,
        $scratchTables['events'],
        $scratchTables['deliveries']
    ));
    $fail($foreignKeyCount === 0, 'V8 created an unexpected webhook foreign key.');

    $scratchSettingsRow = $optionRow($scratchTables['options'], $settingsOption);
    $fail(is_array($scratchSettingsRow), 'The migrated scratch settings option is missing.');
    $migratedSettings = maybe_unserialize($scratchSettingsRow['option_value']);
    $fail(is_array($migratedSettings), 'The migrated scratch settings are invalid.');
    $fail(!array_key_exists('api_key', $migratedSettings), 'V8 retained the scratch plaintext access key.');
    $fail(!empty($migratedSettings['access_api_key_hash']), 'V8 omitted the scratch access-key hash.');
    $fail(AccessApiCredential::verify($legacySecret, $migratedSettings), 'The migrated scratch access key no longer verifies.');
    $fail(
        ($migratedSettings['access_api_key_prefix'] ?? null) === substr($legacySecret, 0, 12),
        'V8 stored the wrong scratch access-key prefix.'
    );
    $fail(!empty($migratedSettings['access_api_key_rotated_at']), 'V8 omitted the scratch access-key rotation time.');
    $fail(
        ($migratedSettings['runtime_v8_sentinel'] ?? null) === $scratchSettings['runtime_v8_sentinel'],
        'V8 changed an unrelated scratch setting.'
    );
    $fail(
        ($migratedSettings['webhook_secret'] ?? null) === $webhookSecret,
        'V8 changed the stored scratch webhook secret.'
    );
    $fail(
        !str_contains((string) $scratchSettingsRow['option_value'], $legacySecret),
        'The full scratch plaintext access key remains in option storage.'
    );
    $fail(
        $optionRow($scratchTables['options'], $versionOption) === [
            'option_value' => '1.7.0',
            'autoload' => 'auto',
        ],
        'MigrationV8 changed the scratch database version.'
    );

    foreach (['grants', 'event_locks', 'mutation_requests'] as $label) {
        $fail(
            $sentinelSnapshot($scratchTables[$label]) === $sentinelsBefore[$label],
            "The first V8 run changed the scratch {$label} sentinel table."
        );
    }

    $eventId = wp_generate_uuid4();
    $occurredAt = '2026-07-23 12:00:00';
    $eventBody = wp_json_encode([
        'id' => $eventId,
        'event_type' => 'runtime_v8_sentinel',
        'sentinel' => hash('sha256', $token . '|event-body'),
    ]);
    $fail(is_string($eventBody), 'The V8 scratch event body could not be encoded.');
    $fail($wpdb->insert($scratchTables['events'], [
        'event_id' => $eventId,
        'event_type' => 'runtime_v8_sentinel',
        'schema_version' => '1.0',
        'body' => $eventBody,
        'occurred_at' => $occurredAt,
        'created_at' => $occurredAt,
    ]) === 1, 'The V8 scratch event sentinel could not be inserted.');

    $destination = 'https://example.test/runtime-v8';
    $fail($wpdb->insert($scratchTables['deliveries'], [
        'event_id' => $eventId,
        'destination_url' => $destination,
        'destination_hash' => hash('sha256', $destination),
        'status' => 'pending',
        'attempt_count' => 0,
        'created_at' => $occurredAt,
        'updated_at' => $occurredAt,
    ]) === 1, 'The V8 scratch delivery sentinel could not be inserted.');

    $eventsBeforeReplay = $wpdb->get_results(
        'SELECT * FROM ' . $quoteIdentifier($scratchTables['events']) . ' ORDER BY id ASC',
        ARRAY_A
    );
    $deliveriesBeforeReplay = $wpdb->get_results(
        'SELECT * FROM ' . $quoteIdentifier($scratchTables['deliveries']) . ' ORDER BY id ASC',
        ARRAY_A
    );
    $eventsCreateBeforeReplay = $showCreate($scratchTables['events']);
    $deliveriesCreateBeforeReplay = $showCreate($scratchTables['deliveries']);
    $settingsBeforeReplay = $optionRow($scratchTables['options'], $settingsOption);

    $secondRun = MigrationV8::run();
    $fail($secondRun === [], 'The intact V8 scratch replay reported a failure.');
    $fail(
        $showCreate($scratchTables['events']) === $eventsCreateBeforeReplay,
        'V8 replay changed the webhook events schema.'
    );
    $fail(
        $showCreate($scratchTables['deliveries']) === $deliveriesCreateBeforeReplay,
        'V8 replay changed the webhook deliveries schema.'
    );
    $fail(
        $wpdb->get_results(
            'SELECT * FROM ' . $quoteIdentifier($scratchTables['events']) . ' ORDER BY id ASC',
            ARRAY_A
        ) === $eventsBeforeReplay,
        'V8 replay changed the webhook event sentinel.'
    );
    $fail(
        $wpdb->get_results(
            'SELECT * FROM ' . $quoteIdentifier($scratchTables['deliveries']) . ' ORDER BY id ASC',
            ARRAY_A
        ) === $deliveriesBeforeReplay,
        'V8 replay changed the webhook delivery sentinel.'
    );
    $fail(
        $optionRow($scratchTables['options'], $settingsOption) === $settingsBeforeReplay,
        'V8 replay changed the migrated scratch credential bytes.'
    );
    foreach (['grants', 'event_locks', 'mutation_requests'] as $label) {
        $fail(
            $sentinelSnapshot($scratchTables[$label]) === $sentinelsBefore[$label],
            "V8 replay changed the scratch {$label} sentinel table."
        );
    }
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if (
        $prefixSwitched
        || $wpdb->prefix !== $originalPrefix
        || $wpdb->base_prefix !== $originalBasePrefix
        || $wpdb->options !== $originalOptionsTable
    ) {
        $restoreResult = $wpdb->set_prefix($originalBasePrefix);
        if (is_wp_error($restoreResult)) {
            $failure ??= new RuntimeException('WordPress rejected the original table prefix during cleanup.');
        }
        if (is_multisite()) {
            $wpdb->set_blog_id($originalBlogId);
        }
    }
    $clearSettingsCaches();

    $releasedSettingsLock = $wpdb->get_var($wpdb->prepare(
        'SELECT RELEASE_LOCK(%s)',
        'fchub_memberships_settings'
    ));
    if ((string) $releasedSettingsLock === '1') {
        $failure ??= new RuntimeException('MigrationV8 leaked the Memberships settings lock.');
    }

    foreach (['deliveries', 'events', 'mutation_requests', 'event_locks', 'grants', 'options'] as $label) {
        $table = $scratchTables[$label];
        try {
            if ($tableExists($table) && $wpdb->query('DROP TABLE ' . $quoteIdentifier($table)) === false) {
                $failure ??= new RuntimeException("Scratch V8 {$label} cleanup failed.");
            }
        } catch (Throwable $cleanupException) {
            $failure ??= $cleanupException;
        }
    }
}

try {
    $recordFailure($wpdb->prefix === $originalPrefix, 'The active WordPress prefix was not restored.');
    $recordFailure($wpdb->base_prefix === $originalBasePrefix, 'The WordPress base prefix was not restored.');
    $recordFailure($wpdb->options === $originalOptionsTable, 'The live options table was not restored.');

    $residue = $wpdb->get_col($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($scratchPrefix) . '%'
    ));
    $recordFailure($residue === [], 'V8 scratch tables remain after cleanup.');

    if ($liveOptionsBefore !== []) {
        $recordFailure(
            $optionRow($originalOptionsTable, $settingsOption) === $liveOptionsBefore[$settingsOption],
            'The live Memberships settings option changed during the V8 scratch smoke.'
        );
        $recordFailure(
            $optionRow($originalOptionsTable, $versionOption) === $liveOptionsBefore[$versionOption],
            'The live Memberships database version changed during the V8 scratch smoke.'
        );
    }
    foreach ($liveTablesBefore as $label => $snapshot) {
        $recordFailure(
            $tableSnapshot($liveTables[$label]) === $snapshot,
            "The live {$label} table changed during the V8 scratch smoke."
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
    "V8 MariaDB smoke passed: scratch_prefix=%s schema_digest=%s live_state_unchanged=yes residue=0.\n",
    $scratchPrefix,
    hash('sha256', $eventsCreate . "\n" . $deliveriesCreate)
);
