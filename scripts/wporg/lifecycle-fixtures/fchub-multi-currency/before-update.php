<?php

defined('ABSPATH') || exit;

global $wpdb;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$settings = [
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
];

$assert(
    !array_key_exists('rate_provider', $settings),
    'The legacy Multi-Currency settings fixture must omit rate_provider.'
);
update_option('fchub_mc_settings', $settings);
update_user_meta(1, '_fchub_mc_currency', 'EUR');

$rateTable = $wpdb->prefix . 'fchub_mc_rate_history';
$eventTable = $wpdb->prefix . 'fchub_mc_event_log';

$rateInserted = $wpdb->insert(
    $rateTable,
    [
        'base_currency' => 'GBP',
        'quote_currency' => 'EUR',
        'rate' => '1.17000000',
        'provider' => 'manual',
        'fetched_at' => '2026-07-01 09:30:00',
    ],
    ['%s', '%s', '%s', '%s', '%s']
);
$assert($rateInserted === 1, 'Could not seed the Multi-Currency rate history fixture.');

$eventInserted = $wpdb->insert(
    $eventTable,
    [
        'event' => 'wporg.lifecycle.selection',
        'user_id' => 1,
        'ip_hash' => hash('sha256', 'wporg-lifecycle'),
        'payload' => wp_json_encode(['currency' => 'EUR', 'source' => 'fixture']),
        'created_at' => '2026-07-01 09:31:00',
    ],
    ['%s', '%d', '%s', '%s', '%s']
);
$assert($eventInserted === 1, 'Could not seed the Multi-Currency event fixture.');

$snapshot = [];
foreach ([$eventTable, $rateTable] as $table) {
    $row = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N);
    $assert(is_array($row) && isset($row[1]), "Could not snapshot the {$table} schema.");
    $snapshot[$table] = (string) $row[1];
}
ksort($snapshot);
update_option('fchub_wporg_multi_schema_snapshot', $snapshot, false);

$assert(get_option('fchub_mc_settings') === $settings, 'The Multi-Currency settings fixture did not persist.');
$assert(
    get_user_meta(1, '_fchub_mc_currency', true) === 'EUR',
    'The Multi-Currency user selection fixture did not persist.'
);

echo "Prepared Multi-Currency migration preservation fixture.\n";
