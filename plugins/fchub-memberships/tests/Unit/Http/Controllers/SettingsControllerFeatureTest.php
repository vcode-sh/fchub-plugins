<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\AccessApiCredential;
use FChubMemberships\Http\Controllers\SettingsController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class SettingsControllerFeatureTest extends PluginTestCase
{
    public function test_register_routes_get_save_and_secret_generation_cover_settings_controller(): void
    {
        SettingsController::registerRoutes();

        foreach ([
            'fchub-memberships/v1/admin/settings',
            'fchub-memberships/v1/admin/settings/generate-api-key',
            'fchub-memberships/v1/admin/settings/revoke-api-key',
            'fchub-memberships/v1/admin/settings/regenerate-webhook-secret',
            'fchub-memberships/v1/admin/settings/test-webhook',
        ] as $route) {
            self::assertArrayHasKey($route, $GLOBALS['_fchub_test_routes']);
        }

        $save = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'default_protection_mode' => 'redirect',
            'default_redirect_url' => 'https://example.com/join',
            'membership_mode' => 'exclusive',
            'restriction_message_logged_out' => 'Log in',
            'restriction_message_no_access' => 'No access',
            'debug_mode' => 'yes',
            'expiry_warning_days' => 0,
            'trial_expiry_notice_days' => 4,
            'fc_space_mappings' => ['5' => 'space-1'],
            'webhook_urls' => "https://example.com/hook\nhttps://example.com/hook-two",
        ]))->get_data();

        $get = SettingsController::get(new \WP_REST_Request('GET', '/settings'))->get_data();

        self::assertSame('redirect', $save['data']['default_protection_mode']);
        self::assertSame('https://example.com/join', $save['data']['default_redirect_url']);
        self::assertSame('exclusive', $save['data']['membership_mode']);
        self::assertSame('yes', $save['data']['debug_mode']);
        self::assertSame(0, $save['data']['expiry_warning_days']);
        self::assertSame(4, $save['data']['trial_expiry_notice_days']);
        self::assertSame(['5' => 'space-1'], $save['data']['fc_space_mappings']);
        self::assertSame($save['data']['default_redirect_url'], $get['data']['default_redirect_url']);
    }

    public function test_access_key_is_returned_once_then_only_metadata_is_public(): void
    {
        $generated = SettingsController::generateApiKey(
            new \WP_REST_Request('POST', '/settings/generate-api-key')
        )->get_data();
        $secret = $generated['data']['api_key'];
        $stored = SettingsController::getSettings();
        $public = SettingsController::get(new \WP_REST_Request('GET', '/settings'))->get_data()['data'];

        self::assertMatchesRegularExpression('/^fchub_[a-f0-9]{48}$/', $secret);
        self::assertArrayNotHasKey('api_key', $stored);
        self::assertArrayHasKey('access_api_key_hash', $stored);
        self::assertTrue(AccessApiCredential::verify($secret, $stored));
        self::assertArrayNotHasKey('api_key', $public);
        self::assertArrayNotHasKey('access_api_key_hash', $public);
        self::assertArrayNotHasKey('access_api_key_prefix', $public);
        self::assertArrayNotHasKey('access_api_key_rotated_at', $public);
        self::assertSame(AccessApiCredential::metadata($stored), $public['access_api']);
        $encodedPublic = wp_json_encode($public);
        self::assertIsString($encodedPublic);
        self::assertStringNotContainsString($secret, $encodedPublic);
        self::assertStringNotContainsString($stored['access_api_key_hash'], $encodedPublic);
    }

    public function test_unrelated_save_preserves_hidden_credential_and_never_returns_it(): void
    {
        SettingsController::generateApiKey(new \WP_REST_Request('POST', '/settings/generate-api-key'));
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['webhook_secret'] = 'preserve-webhook-secret';
        $before = SettingsController::getSettings();
        $hiddenBefore = array_intersect_key($before, array_flip([
            'access_api_key_hash', 'access_api_key_prefix', 'access_api_key_rotated_at',
        ]));

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'debug_mode' => 'yes',
        ]))->get_data()['data'];
        $after = SettingsController::getSettings();

        self::assertSame($hiddenBefore, array_intersect_key($after, $hiddenBefore));
        self::assertSame('preserve-webhook-secret', $after['webhook_secret']);
        self::assertArrayNotHasKey('api_key', $response);
        self::assertArrayNotHasKey('access_api_key_hash', $response);
        self::assertSame(AccessApiCredential::metadata($after), $response['access_api']);
    }

    public function test_revoke_removes_legacy_and_hashed_credential_material(): void
    {
        SettingsController::generateApiKey(new \WP_REST_Request('POST', '/settings/generate-api-key'));

        $response = SettingsController::revokeApiKey(
            new \WP_REST_Request('POST', '/settings/revoke-api-key')
        )->get_data();
        $stored = SettingsController::getSettings();

        self::assertSame([
            'configured' => false,
            'prefix' => null,
            'rotated_at' => null,
        ], $response['data']['access_api']);
        self::assertArrayNotHasKey('api_key', $stored);
        self::assertArrayNotHasKey('access_api_key_hash', $stored);
    }

    public function test_webhook_secret_is_returned_once_and_redacted_from_all_later_responses(): void
    {
        $timeline = [];
        $this->installWebhookStorageSummary([
            'pending' => 0,
            'processing' => 0,
            'retrying' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'last_success_at' => null,
        ], $timeline);
        SettingsController::generateApiKey(new \WP_REST_Request('POST', '/settings/generate-api-key'));
        $accessBefore = array_intersect_key(SettingsController::getSettings(), array_flip([
            'access_api_key_hash', 'access_api_key_prefix', 'access_api_key_rotated_at',
        ]));

        $generated = SettingsController::regenerateWebhookSecret(
            new \WP_REST_Request('POST', '/settings/regenerate-webhook-secret')
        )->get_data();
        $secret = $generated['data']['webhook_secret'];
        $stored = SettingsController::getSettings();
        $get = SettingsController::get(new \WP_REST_Request('GET', '/settings'))->get_data();
        $save = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'debug_mode' => 'yes',
            'webhook_secret' => 'attacker-submitted-secret',
        ]))->get_data();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secret);
        self::assertSame($secret, $stored['webhook_secret']);
        self::assertTrue($get['data']['webhook_secret_configured']);
        self::assertTrue($save['data']['webhook_secret_configured']);
        self::assertArrayNotHasKey('webhook_secret', $get['data']);
        self::assertArrayNotHasKey('webhook_secret', $save['data']);
        self::assertSame($secret, SettingsController::getSettings()['webhook_secret']);
        self::assertSame($accessBefore, array_intersect_key(SettingsController::getSettings(), $accessBefore));

        $laterResponses = wp_json_encode([$get, $save]);
        self::assertIsString($laterResponses);
        self::assertStringNotContainsString($secret, $laterResponses);
        self::assertStringNotContainsString('attacker-submitted-secret', $laterResponses);
        self::assertStringNotContainsString($accessBefore['access_api_key_hash'], $laterResponses);
    }

    public function test_webhook_enablement_requires_safe_destinations_and_a_stored_secret(): void
    {
        $missing = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'webhook_enabled' => 'yes',
        ]));

        self::assertSame(422, $missing->get_status());
        self::assertSame('no', SettingsController::getSettings()['webhook_enabled']);

        $timeline = [];
        $this->installWebhookStorageSummary([
            'pending' => 0,
            'processing' => 0,
            'retrying' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'last_success_at' => null,
        ], $timeline);
        SettingsController::regenerateWebhookSecret(
            new \WP_REST_Request('POST', '/settings/regenerate-webhook-secret')
        );
        $enabled = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'webhook_enabled' => 'yes',
            'webhook_urls' => " HTTPS://EXAMPLE.COM:443/hook \nhttps://example.com/hook",
        ]));

        self::assertSame(200, $enabled->get_status());
        self::assertSame('yes', SettingsController::getSettings()['webhook_enabled']);
        self::assertSame('https://example.com/hook', SettingsController::getSettings()['webhook_urls']);
        self::assertSame('ready', $enabled->get_data()['data']['webhook_status']);
        self::assertTrue($enabled->get_data()['data']['webhook_destinations_configured']);
    }

    public function test_invalid_submitted_destinations_are_rejected_without_partial_save(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'debug_mode' => 'no',
            'webhook_enabled' => 'no',
            'webhook_urls' => 'https://example.com/original',
        ];

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'debug_mode' => 'yes',
            'webhook_urls' => 'https://127.0.0.1/private',
        ]));

        self::assertSame(422, $response->get_status());
        self::assertSame('no', SettingsController::getSettings()['debug_mode']);
        self::assertSame('https://example.com/original', SettingsController::getSettings()['webhook_urls']);
    }

    public function test_url_only_update_cannot_break_an_enabled_webhook_configuration(): void
    {
        $before = [
            'webhook_enabled' => 'yes',
            'webhook_urls' => 'https://example.com/original',
            'webhook_secret' => 'preserve-secret',
            'future_setting' => 'preserve',
        ];

        foreach (['', 'https://127.0.0.1/private'] as $urls) {
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $before;

            $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
                'webhook_urls' => $urls,
            ]));

            self::assertSame(422, $response->get_status(), $urls);
            self::assertSame($before, $GLOBALS['_fchub_test_options']['fchub_memberships_settings'], $urls);
        }
    }

    public function test_url_only_update_uses_the_stored_enabled_state_and_secret(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_enabled' => 'yes',
            'webhook_urls' => 'https://example.com/original',
            'webhook_secret' => 'preserve-secret',
        ];

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'webhook_urls' => ' HTTPS://EXAMPLE.COM:443/memberships ',
        ]));

        self::assertSame(200, $response->get_status());
        self::assertSame('yes', SettingsController::getSettings()['webhook_enabled']);
        self::assertSame(
            'https://example.com/memberships',
            SettingsController::getSettings()['webhook_urls']
        );
        self::assertSame('ready', $response->get_data()['data']['webhook_status']);
    }

    public function test_url_only_update_rejects_an_enabled_configuration_without_a_secret(): void
    {
        $before = [
            'webhook_enabled' => 'yes',
            'webhook_urls' => 'https://example.com/original',
            'future_setting' => 'preserve',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $before;

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'webhook_urls' => 'https://example.com/memberships',
        ]));

        self::assertSame(422, $response->get_status());
        self::assertSame($before, $GLOBALS['_fchub_test_options']['fchub_memberships_settings']);
    }

    public function test_unsafe_disabled_legacy_configuration_is_preserved_as_needs_setup(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_enabled' => 'no',
            'webhook_urls' => 'http://127.0.0.1/legacy',
        ];

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'debug_mode' => 'yes',
        ]));
        $stored = SettingsController::getSettings();

        self::assertSame(200, $response->get_status());
        self::assertSame('http://127.0.0.1/legacy', $stored['webhook_urls']);
        self::assertArrayNotHasKey('webhook_secret', $stored);
        self::assertSame('needs_setup', $response->get_data()['data']['webhook_status']);
        self::assertFalse($response->get_data()['data']['webhook_secret_configured']);
        self::assertFalse($response->get_data()['data']['webhook_destinations_configured']);
    }

    public function test_full_ui_can_resubmit_a_canonical_equivalent_unsafe_legacy_url_only_while_disabling(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_enabled' => 'yes',
            'webhook_urls' => 'http://127.0.0.1/legacy',
        ];

        $disabled = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'webhook_enabled' => 'no',
            'webhook_urls' => ' HTTP://127.0.0.1:80/legacy ',
        ]));

        self::assertSame(200, $disabled->get_status());
        self::assertSame('no', SettingsController::getSettings()['webhook_enabled']);
        self::assertSame('http://127.0.0.1/legacy', SettingsController::getSettings()['webhook_urls']);
        self::assertSame('needs_setup', $disabled->get_data()['data']['webhook_status']);

        $changed = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'webhook_enabled' => 'no',
            'webhook_urls' => 'http://127.0.0.1/changed',
        ]));
        self::assertSame(422, $changed->get_status());

        $enable = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'webhook_enabled' => 'yes',
            'webhook_urls' => 'http://127.0.0.1/legacy',
        ]));
        self::assertSame(422, $enable->get_status());
    }

    public function test_webhook_secret_rotation_failure_returns_503_without_exposing_or_storing_a_secret(): void
    {
        $before = [
            'access_api_key_hash' => '$wp$task-one-hash',
            'access_api_key_prefix' => 'fchub_abc123',
            'access_api_key_rotated_at' => '2026-07-22 12:00:00',
            'webhook_secret' => 'existing-internal-secret',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $before;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): mixed =>
            str_contains($query, 'GET_LOCK(') ? 0 : null;

        $response = SettingsController::regenerateWebhookSecret(
            new \WP_REST_Request('POST', '/settings/regenerate-webhook-secret')
        );

        self::assertSame(503, $response->get_status());
        self::assertSame($before, array_intersect_key(SettingsController::getSettings(), $before));
        $encodedResponse = wp_json_encode($response->get_data());
        self::assertIsString($encodedResponse);
        self::assertStringNotContainsString('existing-internal-secret', $encodedResponse);
        self::assertStringNotContainsString('$wp$task-one-hash', $encodedResponse);
    }

    public function test_webhook_secret_rotation_is_blocked_by_every_active_delivery_under_the_settings_lock(): void
    {
        $before = [
            'webhook_secret' => 'existing-secret-sentinel',
            'access_api_key_hash' => 'access-hash-sentinel',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $before;
        $timeline = [];
        $this->installWebhookStorageSummary([
            'pending' => 2,
            'processing' => 3,
            'retrying' => 4,
            'succeeded' => 5,
            'failed' => 6,
            'last_success_at' => '2026-07-22 12:00:00',
        ], $timeline);

        $response = SettingsController::regenerateWebhookSecret(
            new \WP_REST_Request('POST', '/settings/regenerate-webhook-secret')
        );

        self::assertSame(409, $response->get_status());
        self::assertSame('fchub_webhook_rotation_blocked', $response->get_data()['code']);
        self::assertSame(['blocking_count' => 9], $response->get_data()['data']);
        self::assertSame($before, array_intersect_key(SettingsController::getSettings(), $before));
        self::assertSame('lock:acquire', $timeline[0]);
        self::assertSame('summary', $timeline[count($timeline) - 2]);
        self::assertSame('lock:release', $timeline[count($timeline) - 1]);
        self::assertStringNotContainsString('existing-secret-sentinel', serialize($response->get_data()));
        self::assertStringNotContainsString('access-hash-sentinel', serialize($response->get_data()));
    }

    public function test_terminal_failures_allow_atomic_rotation_and_the_secret_is_returned_once(): void
    {
        $before = [
            'webhook_secret' => 'old-secret-sentinel',
            'access_api_key_hash' => 'access-hash-sentinel',
            'debug_mode' => 'yes',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $before;
        $timeline = [];
        $this->installWebhookStorageSummary([
            'pending' => 0,
            'processing' => 0,
            'retrying' => 0,
            'succeeded' => 12,
            'failed' => 7,
            'last_success_at' => '2026-07-22 12:00:00',
        ], $timeline);

        $response = SettingsController::regenerateWebhookSecret(
            new \WP_REST_Request('POST', '/settings/regenerate-webhook-secret')
        );
        $secret = $response->get_data()['data']['webhook_secret'];

        self::assertSame(200, $response->get_status());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secret);
        self::assertNotSame('old-secret-sentinel', $secret);
        self::assertSame($secret, SettingsController::getSettings()['webhook_secret']);
        self::assertSame('access-hash-sentinel', SettingsController::getSettings()['access_api_key_hash']);
        self::assertSame('yes', SettingsController::getSettings()['debug_mode']);
        self::assertSame([
            'lock:acquire',
            'settings:read',
            'table:webhook_events',
            'table:webhook_deliveries',
            'summary',
            'settings:read',
            'settings:read',
            'lock:release',
        ], $timeline);
        $later = SettingsController::get(new \WP_REST_Request('GET', '/settings'))->get_data();
        self::assertStringNotContainsString($secret, serialize($later));
        self::assertArrayNotHasKey('webhook_secret', $later['data']);
    }

    public function test_webhook_secret_rotation_fails_closed_when_durable_storage_is_not_ready_or_readable(): void
    {
        foreach (['old_version', 'missing_table', 'partial_schema', 'summary_failure'] as $failure) {
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
                'webhook_secret' => 'preserved-secret-sentinel',
            ];
            $timeline = [];
            $this->installWebhookStorageSummary([
                'pending' => 0,
                'processing' => 0,
                'retrying' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'last_success_at' => null,
            ], $timeline, $failure);

            $response = SettingsController::regenerateWebhookSecret(
                new \WP_REST_Request('POST', '/settings/regenerate-webhook-secret')
            );

            self::assertSame(503, $response->get_status(), $failure);
            self::assertSame(
                'preserved-secret-sentinel',
                SettingsController::getSettings()['webhook_secret'],
                $failure
            );
            self::assertStringNotContainsString(
                'preserved-secret-sentinel',
                serialize($response->get_data()),
                $failure
            );
            self::assertSame('lock:release', $timeline[count($timeline) - 1], $failure);
            $GLOBALS['wpdb']->last_error = '';
        }
    }

    public function test_webhook_secret_rotation_never_returns_an_unverified_compare_and_swap_value(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_secret' => 'preserved-secret-sentinel',
        ];
        $timeline = [];
        $this->installWebhookStorageSummary([
            'pending' => 0,
            'processing' => 0,
            'retrying' => 0,
            'succeeded' => 0,
            'failed' => 4,
            'last_success_at' => null,
        ], $timeline, 'cas_mismatch');

        $response = SettingsController::regenerateWebhookSecret(
            new \WP_REST_Request('POST', '/settings/regenerate-webhook-secret')
        );

        self::assertSame(503, $response->get_status());
        self::assertArrayNotHasKey('data', $response->get_data());
        self::assertArrayNotHasKey('webhook_secret', $response->get_data());
        self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', serialize($response->get_data()));
        self::assertSame('lock:release', $timeline[count($timeline) - 1]);
    }

    public function test_test_webhook_is_only_a_thin_production_controller_alias(): void
    {
        $method = new \ReflectionMethod(SettingsController::class, 'testWebhook');
        $source = file($method->getFileName());
        self::assertIsArray($source);
        $body = implode('', array_slice(
            $source,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('return WebhookController::test($request);', $body);
        self::assertStringNotContainsString('WebhookDispatcher', $body);
        self::assertStringNotContainsString('sendTest', $body);
        self::assertStringNotContainsString('wp_remote_post', $body);
    }

    public function test_community_badge_settings_are_preserved_as_legacy_read_only_data(): void
    {
        $legacy = [
            'fc_badge_mappings' => [
                '5' => 42,
                '6' => '042',
                '7' => 'founder<script>',
            ],
            'fc_remove_badge_on_revoke' => 'yes',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $legacy;

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'fc_badge_mappings' => [
                '5' => 99,
            ],
            'fc_remove_badge_on_revoke' => 'no',
            'debug_mode' => 'yes',
        ]));

        self::assertSame(200, $response->get_status());
        self::assertSame($legacy, array_intersect_key(SettingsController::getSettings(), $legacy));
        self::assertArrayNotHasKey('fc_badge_mappings', $response->get_data()['data']);
        self::assertArrayNotHasKey('fc_remove_badge_on_revoke', $response->get_data()['data']);
        self::assertSame('yes', $response->get_data()['data']['debug_mode']);
    }

    /**
     * @param array<string, int|string|null> $summary
     * @param list<string> $timeline
     */
    private function installWebhookStorageSummary(
        array $summary,
        array &$timeline,
        ?string $failure = null
    ): void {
        $settingsReads = 0;
        $metadata = $this->webhookStorageMetadata($failure === 'partial_schema');
        $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] =
            $failure === 'old_version' ? '1.7.0' : '1.8.0';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (
            &$timeline,
            $failure,
            &$settingsReads
        ): string|int|null {
            if (str_contains($query, 'GET_LOCK(')) {
                $timeline[] = 'lock:acquire';
                self::assertStringContainsString('fchub_memberships_settings', $query);
                return 1;
            }
            if (str_contains($query, 'RELEASE_LOCK(')) {
                $timeline[] = 'lock:release';
                return 1;
            }
            if (str_contains($query, 'FROM wp_options')) {
                $timeline[] = 'settings:read';
                $settingsReads++;
                if ($failure === 'cas_mismatch' && $settingsReads >= 3) {
                    return serialize(['webhook_secret' => 'different-stored-secret']);
                }
                return serialize($GLOBALS['_fchub_test_options']['fchub_memberships_settings'] ?? []);
            }
            foreach (['webhook_events', 'webhook_deliveries'] as $table) {
                if (str_contains($query, 'SHOW TABLES LIKE') && str_contains($query, $table)) {
                    $timeline[] = 'table:' . $table;
                    if ($failure === 'missing_table' && $table === 'webhook_deliveries') {
                        return null;
                    }
                    return 'wp_fchub_membership_' . $table;
                }
            }

            return 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (
            $summary,
            &$timeline,
            $failure
        ): ?array {
            if (str_contains($query, "SUM(status = 'pending')")) {
                $timeline[] = 'summary';
                if ($failure === 'summary_failure') {
                    $GLOBALS['wpdb']->last_error = 'summary body secret must not leak';
                    return null;
                }
                return $summary;
            }

            return null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($metadata): array {
            foreach (['webhook_events', 'webhook_deliveries'] as $table) {
                if (str_contains($query, "SHOW TABLE STATUS LIKE 'wp_fchub_membership_{$table}'")) {
                    return [['Name' => "wp_fchub_membership_{$table}", 'Engine' => 'InnoDB']];
                }
                if (str_contains($query, "SHOW COLUMNS FROM wp_fchub_membership_{$table}")) {
                    return $metadata[$table]['columns'];
                }
                if (str_contains($query, "SHOW INDEX FROM wp_fchub_membership_{$table}")) {
                    return $metadata[$table]['indexes'];
                }
            }

            return [];
        };
    }

    /** @return array<string, array{columns:list<array<string, mixed>>, indexes:list<array<string, mixed>>}> */
    private function webhookStorageMetadata(bool $partial): array
    {
        $definitions = [
            'webhook_events' => [
                'id' => ['bigint unsigned', 'NO', null],
                'event_id' => ['char(36)', 'NO', null],
                'event_type' => ['varchar(64)', 'NO', null],
                'schema_version' => ['varchar(10)', 'NO', '1.0'],
                'body' => ['longtext', 'NO', null],
                'occurred_at' => ['datetime', 'NO', null],
                'created_at' => ['datetime', 'NO', null],
            ],
            'webhook_deliveries' => [
                'id' => ['bigint unsigned', 'NO', null],
                'event_id' => ['char(36)', 'NO', null],
                'destination_url' => ['varchar(2048)', 'NO', null],
                'destination_hash' => ['char(64)', 'NO', null],
                'status' => ['varchar(20)', 'NO', 'pending'],
                'attempt_count' => ['smallint unsigned', 'NO', '0'],
                'lease_owner' => ['varchar(64)', 'YES', null],
                'lease_expires_at' => ['datetime', 'YES', null],
                'response_code' => ['smallint unsigned', 'YES', null],
                'response_body' => ['text', 'YES', null],
                'error_message' => ['text', 'YES', null],
                'next_attempt_at' => ['datetime', 'YES', null],
                'last_attempt_at' => ['datetime', 'YES', null],
                'delivered_at' => ['datetime', 'YES', null],
                'created_at' => ['datetime', 'NO', null],
                'updated_at' => ['datetime', 'NO', null],
            ],
        ];
        if ($partial) {
            unset($definitions['webhook_deliveries']['destination_hash']);
        }

        $indexDefinitions = [
            'webhook_events' => [
                'PRIMARY' => [0, ['id']],
                'event_id' => [0, ['event_id']],
                'type_occurred' => [1, ['event_type', 'occurred_at']],
            ],
            'webhook_deliveries' => [
                'PRIMARY' => [0, ['id']],
                'event_destination' => [0, ['event_id', 'destination_hash']],
                'status_next' => [1, ['status', 'next_attempt_at']],
                'status_lease' => [1, ['status', 'lease_expires_at']],
                'created_at' => [1, ['created_at']],
            ],
        ];

        $metadata = [];
        foreach ($definitions as $table => $columns) {
            $metadata[$table] = ['columns' => [], 'indexes' => []];
            foreach ($columns as $field => [$type, $nullable, $default]) {
                $metadata[$table]['columns'][] = [
                    'Field' => $field,
                    'Type' => $type,
                    'Null' => $nullable,
                    'Default' => $default,
                ];
            }
            foreach ($indexDefinitions[$table] as $name => [$nonUnique, $indexColumns]) {
                foreach ($indexColumns as $position => $column) {
                    $metadata[$table]['indexes'][] = [
                        'Key_name' => $name,
                        'Column_name' => $column,
                        'Seq_in_index' => $position + 1,
                        'Non_unique' => $nonUnique,
                    ];
                }
            }
        }

        return $metadata;
    }
}
