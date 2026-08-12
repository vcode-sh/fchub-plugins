<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$GLOBALS['cartshift_contract_spies'] ??= [
    'action_scheduler' => 0,
    'events' => 0,
    'mail' => 0,
    'http' => 0,
    'payment' => 0,
    'stock' => 0,
];
$GLOBALS['cartshift_contract_action_scheduler_traces'] ??= [];

add_action('action_scheduler_stored_action', static function ($actionId): void {
    $GLOBALS['cartshift_contract_spies']['action_scheduler']++;
    $trace = [];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
        $function = (string) ($frame['function'] ?? '');
        if ($function === '' || $function === '{closure}') {
            continue;
        }
        $trace[] = (string) ($frame['class'] ?? '') . (string) ($frame['type'] ?? '') . $function;
    }
    $GLOBALS['cartshift_contract_action_scheduler_traces'][] = [
        'action_id' => (int) $actionId,
        'trace' => array_values(array_unique($trace)),
    ];
}, PHP_INT_MIN);

add_filter('pre_wp_mail', static function (): bool {
    $GLOBALS['cartshift_contract_spies']['mail']++;

    return true;
}, PHP_INT_MIN);

add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    $GLOBALS['cartshift_contract_spies']['http']++;

    return new WP_Error('cartshift_contract_http_blocked', 'Outbound HTTP is disabled in installed contracts.');
}, PHP_INT_MIN, 3);

foreach ([
    'fluent_cart/order_paid_done',
    'fluent_cart/order_fully_refunded',
    'fluent_cart/subscription_activated',
    'fluent_cart/subscription_renewed',
] as $hook) {
    add_action($hook, static function (): void {
        $GLOBALS['cartshift_contract_spies']['events']++;
    }, PHP_INT_MIN);
}

foreach ([
    'fluent_cart/product_stock_changed',
    'woocommerce_reduce_order_stock',
    'woocommerce_restore_order_stock',
] as $hook) {
    add_action($hook, static function (): void {
        $GLOBALS['cartshift_contract_spies']['stock']++;
    }, PHP_INT_MIN);
}

foreach ([
    'fluent_cart/payment_success',
    'fluent_cart/payment_failed',
    'fluent_cart/order_refunded',
    'fluent_cart/subscriptions/system_charge_succeeded',
    'fluent_cart/subscriptions/system_charge_failed',
] as $hook) {
    add_action($hook, static function (): void {
        $GLOBALS['cartshift_contract_spies']['payment']++;
    }, PHP_INT_MIN);
}

register_shutdown_function(static function (): void {
    if (!defined('CARTSHIFT_CONTRACT_EVIDENCE_DIR')) {
        return;
    }

    $path = rtrim((string) CARTSHIFT_CONTRACT_EVIDENCE_DIR, '/') . '/spies.json';
    $handle = fopen($path, 'c+');
    if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Installed-contract spy evidence could not be locked.');
    }
    $previous = stream_get_contents($handle);
    $totals = is_string($previous) && trim($previous) !== '' ? json_decode($previous, true) : [];
    if (!is_array($totals)) {
        $totals = [];
    }
    foreach ($GLOBALS['cartshift_contract_spies'] as $sink => $count) {
        $totals[$sink] = (int) ($totals[$sink] ?? 0) + (int) $count;
    }
    ksort($totals, SORT_STRING);
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, wp_json_encode($totals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    chmod($path, 0600);
});
