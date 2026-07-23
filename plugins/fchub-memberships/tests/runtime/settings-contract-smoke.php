<?php

use FChubMemberships\Http\Controllers\SettingsController;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run through the mounted WordPress runtime.\n");
    exit(1);
}

$expectedRoutePairs = [
    'DELETE /admin/content/{id}',
    'DELETE /admin/plans/{id}',
    'DELETE /admin/plans/{id}/unlink-product/{feed_id}',
    'GET /admin/content',
    'GET /admin/content/resource-types',
    'GET /admin/content/search-resources',
    'GET /admin/dashboard',
    'GET /admin/drip/calendar',
    'GET /admin/drip/notifications',
    'GET /admin/drip/overview',
    'GET /admin/drip/stats',
    'GET /admin/email-notifications',
    'GET /admin/fc-badges',
    'GET /admin/fc-spaces',
    'GET /admin/fluentcrm-lists',
    'GET /admin/fluentcrm-tags',
    'GET /admin/integrations/fluentcrm/health',
    'GET /admin/members',
    'GET /admin/members/export',
    'GET /admin/members/{user_id}',
    'GET /admin/members/{user_id}/activity',
    'GET /admin/members/{user_id}/audit-log',
    'GET /admin/members/{user_id}/drip-timeline',
    'GET /admin/plans',
    'GET /admin/plans/export-all',
    'GET /admin/plans/options',
    'GET /admin/plans/search-products',
    'GET /admin/plans/{id}',
    'GET /admin/plans/{id}/drip-schedule',
    'GET /admin/plans/{id}/export',
    'GET /admin/plans/{id}/linked-products',
    'GET /admin/provider-reconciliation',
    'GET /admin/providers',
    'GET /admin/reports/churn',
    'GET /admin/reports/content-popularity',
    'GET /admin/reports/expiring-soon',
    'GET /admin/reports/members-over-time',
    'GET /admin/reports/overview',
    'GET /admin/reports/plan-distribution',
    'GET /admin/reports/renewal-rate',
    'GET /admin/reports/retention-cohort',
    'GET /admin/reports/revenue',
    'GET /admin/reports/trial-conversion',
    'GET /admin/resource-types',
    'GET /admin/settings',
    'GET /admin/webhooks/deliveries',
    'GET /admin/webhooks/health',
    'GET /check-access',
    'GET /my-access',
    'PATCH /admin/content/{id}',
    'PATCH /admin/plans/{id}',
    'POST /admin/content/bulk-protect',
    'POST /admin/content/bulk-unprotect',
    'POST /admin/content/protect',
    'POST /admin/content/unprotect',
    'POST /admin/drip/notifications/{id}/retry',
    'POST /admin/email-notifications/brand-template',
    'POST /admin/email-notifications/preview',
    'POST /admin/email-notifications/test',
    'POST /admin/email-notifications/{key}',
    'POST /admin/import/execute',
    'POST /admin/import/parse',
    'POST /admin/import/prepare',
    'POST /admin/integrations/fluentcrm/reconcile',
    'POST /admin/members/bulk-export',
    'POST /admin/members/bulk-extend',
    'POST /admin/members/bulk-grant',
    'POST /admin/members/bulk-revoke',
    'POST /admin/members/extend',
    'POST /admin/members/grant',
    'POST /admin/members/pause',
    'POST /admin/members/resume',
    'POST /admin/members/revoke',
    'POST /admin/plans',
    'POST /admin/plans/import',
    'POST /admin/plans/resolve-resources',
    'POST /admin/plans/{id}/duplicate',
    'POST /admin/plans/{id}/link-product',
    'POST /admin/plans/{id}/schedule',
    'POST /admin/provider-reconciliation/repair',
    'POST /admin/settings',
    'POST /admin/settings/generate-api-key',
    'POST /admin/settings/regenerate-webhook-secret',
    'POST /admin/settings/revoke-api-key',
    'POST /admin/settings/test-webhook',
    'POST /admin/webhooks/deliveries/{id}/retry',
    'POST /admin/webhooks/test',
    'PUT /admin/content/{id}',
    'PUT /admin/plans/{id}',
    'PUT /admin/settings',
];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$request = static function (string $method, array $payload = []): WP_REST_Request {
    $request = new WP_REST_Request($method, '/fchub-memberships/v1/admin/settings');
    if ($payload !== []) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($payload, JSON_THROW_ON_ERROR));
    }
    return $request;
};

global $wpdb;
$rawSettings = static function () use ($wpdb): ?string {
    $stored = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        'fchub_memberships_settings'
    ));

    return $stored === null ? null : (string) $stored;
};

$rawBefore = $rawSettings();
if ($rawBefore === null) {
    fwrite(STDERR, "STOP: the mounted Memberships settings option does not exist.\n");
    exit(1);
}
$snapshot = maybe_unserialize($rawBefore);
if (!is_array($snapshot)) {
    fwrite(STDERR, "STOP: the mounted Memberships settings option is not an array.\n");
    exit(1);
}

$hashBefore = hash('sha256', $rawBefore);
$originalUserId = get_current_user_id();
$administrators = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ID',
]);
if ($administrators === []) {
    fwrite(STDERR, "STOP: no administrator is available for the mounted settings contract.\n");
    exit(1);
}

$coordinator = new MembershipSettingsOptionCoordinator();
$failure = null;
$cleanupFailure = null;
$routeCount = 0;

try {
    wp_set_current_user((int) $administrators[0]);
    $assert(SettingsController::adminPermission(), 'Administrator settings permission was not available.');

    $server = rest_get_server();
    if (did_action('rest_api_init') === 0) {
        do_action('rest_api_init', $server);
    }
    $routePairs = [];
    foreach ($server->get_routes() as $route => $endpoints) {
        $prefix = '/fchub-memberships/v1';
        // WordPress exposes the namespace root for discovery; it is not a registered plugin endpoint.
        if ($route === $prefix || $route === $prefix . '/') {
            continue;
        }
        if (!str_starts_with($route, $prefix . '/')) {
            continue;
        }
        $normalisedRoute = preg_replace(
            '/\(\?P<([^>]+)>[^)]+\)/',
            '{$1}',
            substr($route, strlen($prefix))
        );
        foreach ($endpoints as $endpoint) {
            foreach ((array) ($endpoint['methods'] ?? []) as $method => $enabled) {
                if ($enabled && in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $routePairs[] = $method . ' ' . $normalisedRoute;
                }
            }
        }
    }
    $routePairs = array_values(array_unique($routePairs));
    sort($routePairs, SORT_STRING);
    sort($expectedRoutePairs, SORT_STRING);
    $routeCount = count($routePairs);
    $missingRoutePairs = array_values(array_diff($expectedRoutePairs, $routePairs));
    $extraRoutePairs = array_values(array_diff($routePairs, $expectedRoutePairs));
    $assert(
        $routeCount === 90 && $missingRoutePairs === [] && $extraRoutePairs === [],
        sprintf(
            'Mounted Memberships REST discovery differs from the 90-pair inventory; count=%d missing=%s extra=%s.',
            $routeCount,
            wp_json_encode($missingRoutePairs),
            wp_json_encode($extraRoutePairs)
        )
    );

    $opaqueToken = 'settings-contract-' . wp_generate_uuid4();
    $webhookSecret = wp_generate_password(48, true, true);
    $fixture = [
        'settings_contract_unknown' => $opaqueToken,
        'expiry_notice_days' => 41,
        'fc_badge_mappings' => ['legacy-plan' => $opaqueToken . '-badge'],
        'fc_remove_badge_on_revoke' => 'yes',
        'webhook_secret' => $webhookSecret,
    ];
    $setup = $coordinator->mutate(static function (array $settings) use ($fixture): array {
        foreach ($fixture as $key => $value) {
            $settings[$key] = $value;
        }
        return $settings;
    });
    $assert($setup['success'], 'The reversible settings fixture could not be installed.');
    $working = $setup['settings'];
    $assert(
        isset($working['access_api_key_hash']) && is_string($working['access_api_key_hash'])
            && $working['access_api_key_hash'] !== '',
        'The mounted hashed access credential is not configured.'
    );
    $managedValues = [
        'access_api_key_hash' => $working['access_api_key_hash'],
        'webhook_secret' => $working['webhook_secret'],
    ];

    $saveResponse = SettingsController::save($request('POST', [
        'expiry_warning_days' => 9,
        'trial_expiry_notice_days' => 2,
        'hide_protected_in_archive' => 'yes',
        'uninstall_remove_data' => 'no',
    ]));
    $assert($saveResponse->get_status() === 200, 'Canonical settings save did not return 200.');

    $reloaded = SettingsController::getSettings();
    $assert(($reloaded['expiry_warning_days'] ?? null) === 9, 'Canonical expiry warning did not reload as 9.');
    $assert(($reloaded['trial_expiry_notice_days'] ?? null) === 2, 'Trial expiry notice did not reload as 2.');
    $assert(($reloaded['hide_protected_in_archive'] ?? null) === 'yes', 'Archive visibility did not reload as yes.');
    $assert(($reloaded['uninstall_remove_data'] ?? null) === 'no', 'Uninstall removal was not kept safely off.');

    foreach ($fixture as $key => $value) {
        $assert(array_key_exists($key, $reloaded) && $reloaded[$key] === $value, "Stored compatibility value {$key} was not preserved.");
    }
    foreach ($managedValues as $key => $value) {
        $assert(array_key_exists($key, $reloaded) && $reloaded[$key] === $value, "Managed value {$key} was not preserved.");
    }

    $publicResponse = SettingsController::get($request('GET'));
    $assert($publicResponse->get_status() === 200, 'Public settings reload did not return 200.');
    $public = (array) ($publicResponse->get_data()['data'] ?? []);
    foreach (array_merge(array_keys($fixture), array_keys($managedValues)) as $privateKey) {
        $assert(!array_key_exists($privateKey, $public), "Private setting {$privateKey} was exposed.");
    }
    $publicJson = wp_json_encode($public, JSON_THROW_ON_ERROR);
    $assert(!str_contains($publicJson, $opaqueToken), 'An opaque compatibility value was exposed.');
    $assert(!str_contains($publicJson, $managedValues['access_api_key_hash']), 'The hashed access credential was exposed.');
    $assert(!str_contains($publicJson, $managedValues['webhook_secret']), 'The webhook secret was exposed.');

    $hashBeforeInvalid = hash('sha256', (string) $rawSettings());
    $invalidResponse = SettingsController::save($request('POST', [
        'expiry_warning_days' => 10,
        'trial_expiry_notice_days' => 999,
    ]));
    $assert($invalidResponse->get_status() === 422, 'Mixed invalid settings did not return 422.');
    $assert(
        hash('sha256', (string) $rawSettings()) === $hashBeforeInvalid,
        'Mixed invalid settings changed the raw option.'
    );

    $hashBeforeDeadOnly = hash('sha256', (string) $rawSettings());
    $deadOnlyResponse = SettingsController::save($request('POST', [
        'cron_validity_interval' => 'daily',
        'fc_badge_mappings' => ['ignored' => 'ignored'],
    ]));
    $assert($deadOnlyResponse->get_status() === 422, 'Dead-only settings did not return 422.');
    $assert(
        hash('sha256', (string) $rawSettings()) === $hashBeforeDeadOnly,
        'Dead-only settings changed the raw option.'
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $restore = $coordinator->mutate(static fn(array $settings): array => $snapshot);
    $rawAfter = $rawSettings();
    if (!$restore['success'] || $rawAfter === null || hash('sha256', $rawAfter) !== $hashBefore || $rawAfter !== $rawBefore) {
        $cleanupFailure = new RuntimeException('The exact raw Memberships settings option was not restored.');
    }
    wp_set_current_user($originalUserId);
}

if ($cleanupFailure instanceof Throwable) {
    fwrite(STDERR, "FAIL: cleanup residue detected; exact settings restoration failed.\n");
    exit(1);
}
if ($failure instanceof Throwable) {
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
    exit(1);
}

printf(
    "Settings contract smoke: PASS routes=%d canonical=9/2/yes/no raw_hash_restored=yes residue=none\n",
    $routeCount
);
