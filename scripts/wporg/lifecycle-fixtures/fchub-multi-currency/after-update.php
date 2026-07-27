<?php

defined('ABSPATH') || exit;

global $wpdb;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expectedSettings = [
    'enabled' => 'yes',
    'base_currency' => 'GBP',
    'display_currencies' => [
        [
            'code' => 'EUR',
            'symbol' => '€',
            'decimal_separator' => ',',
            'thousand_separator' => '.',
        ],
    ],
    'rate_refresh_interval_hrs' => 12,
    'uninstall_remove_data' => 'no',
    'rate_provider' => 'manual',
];

$assert(
    get_option('fchub_mc_settings') === $expectedSettings,
    'Multi-Currency settings changed during the update or the absent provider was not migrated to manual.'
);
$assert(
    get_user_meta(1, '_fchub_mc_currency', true) === 'EUR',
    'The Multi-Currency user selection changed during the update.'
);

$rateTable = $wpdb->prefix . 'fchub_mc_rate_history';
$eventTable = $wpdb->prefix . 'fchub_mc_event_log';

$rate = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT base_currency, quote_currency, rate, provider, fetched_at
         FROM %i
         WHERE base_currency = %s AND quote_currency = %s AND fetched_at = %s",
        $rateTable,
        'GBP',
        'EUR',
        '2026-07-01 09:30:00'
    ),
    ARRAY_A
);
$assert(
    $rate === [
        'base_currency' => 'GBP',
        'quote_currency' => 'EUR',
        'rate' => '1.17000000',
        'provider' => 'manual',
        'fetched_at' => '2026-07-01 09:30:00',
    ],
    'The Multi-Currency rate history fixture changed during the update.'
);

$event = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT event, user_id, ip_hash, payload, created_at
         FROM %i
         WHERE event = %s AND created_at = %s",
        $eventTable,
        'wporg.lifecycle.selection',
        '2026-07-01 09:31:00'
    ),
    ARRAY_A
);
$assert(
    $event === [
        'event' => 'wporg.lifecycle.selection',
        'user_id' => '1',
        'ip_hash' => hash('sha256', 'wporg-lifecycle'),
        'payload' => wp_json_encode(['currency' => 'EUR', 'source' => 'fixture']),
        'created_at' => '2026-07-01 09:31:00',
    ],
    'The Multi-Currency event fixture changed during the update.'
);

$beforeSchema = get_option('fchub_wporg_multi_schema_snapshot', []);
$afterSchema = [];
foreach ([$eventTable, $rateTable] as $table) {
    $row = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N);
    $assert(is_array($row) && isset($row[1]), "Could not inspect the {$table} schema after update.");
    $afterSchema[$table] = (string) $row[1];
}
ksort($afterSchema);
$assert($beforeSchema === $afterSchema, 'The Multi-Currency table schema drifted during the update.');

delete_option('fchub_wporg_multi_schema_snapshot');

echo "Verified Multi-Currency migration preservation fixture.\n";
