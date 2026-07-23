<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http;

use FChubMemberships\Http\AccessApiCredential;
use FChubMemberships\Support\Migrations;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AccessApiCredentialTest extends PluginTestCase
{
    public function test_generation_returns_one_time_prefixed_secret_and_hash_only_storage_fields(): void
    {
        $credential = AccessApiCredential::generate();

        self::assertMatchesRegularExpression('/^fchub_[a-f0-9]{48}$/', $credential['secret']);
        self::assertNotSame($credential['secret'], $credential['hash']);
        self::assertTrue(wp_check_password($credential['secret'], $credential['hash']));
        self::assertSame(substr($credential['secret'], 0, 12), $credential['prefix']);
        self::assertSame('2026-03-13 22:00:00', $credential['rotated_at']);
    }

    public function test_plaintext_migration_preserves_the_client_value_and_is_replayable(): void
    {
        $legacySecret = str_repeat('L', 40);
        $migrated = AccessApiCredential::migratePlaintext([
            'api_key' => $legacySecret,
            'unrelated' => 'preserve-me',
        ]);

        self::assertArrayNotHasKey('api_key', $migrated);
        self::assertSame('preserve-me', $migrated['unrelated']);
        self::assertTrue(AccessApiCredential::verify($legacySecret, $migrated));
        self::assertFalse(AccessApiCredential::verify('wrong-secret', $migrated));
        self::assertFalse(AccessApiCredential::verify('', $migrated));

        $replayed = AccessApiCredential::migratePlaintext($migrated);
        self::assertSame($migrated, $replayed);
    }

    public function test_metadata_and_revoke_never_return_secret_or_hash_material(): void
    {
        $credential = AccessApiCredential::generate();
        $stored = [
            'access_api_key_hash' => $credential['hash'],
            'access_api_key_prefix' => $credential['prefix'],
            'access_api_key_rotated_at' => $credential['rotated_at'],
            'unrelated' => 'preserve-me',
        ];

        self::assertSame([
            'configured' => true,
            'prefix' => $credential['prefix'],
            'rotated_at' => $credential['rotated_at'],
        ], AccessApiCredential::metadata($stored));
        self::assertArrayNotHasKey('access_api_key_hash', AccessApiCredential::metadata($stored));

        $revoked = AccessApiCredential::revoke($stored + ['api_key' => 'stale-legacy']);
        self::assertSame('preserve-me', $revoked['unrelated']);
        self::assertSame([
            'configured' => false,
            'prefix' => null,
            'rotated_at' => null,
        ], AccessApiCredential::metadata($revoked));
        self::assertArrayNotHasKey('api_key', $revoked);
        self::assertArrayNotHasKey('access_api_key_hash', $revoked);
    }

    public function test_migration_entrypoint_rewrites_the_option_once_without_advancing_db_version(): void
    {
        $legacySecret = str_repeat('M', 40);
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'api_key' => $legacySecret,
            'webhook_enabled' => 'no',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = '1.7.0';

        $first = Migrations::migrateAccessApiCredential();
        $stored = $GLOBALS['_fchub_test_options']['fchub_memberships_settings'];
        $second = Migrations::migrateAccessApiCredential();

        self::assertTrue($first['success']);
        self::assertTrue($first['changed']);
        self::assertTrue($second['success']);
        self::assertFalse($second['changed']);
        self::assertTrue(AccessApiCredential::verify($legacySecret, $stored));
        self::assertArrayNotHasKey('api_key', $stored);
        self::assertSame('no', $stored['webhook_enabled']);
        self::assertSame('1.7.0', $GLOBALS['_fchub_test_options']['fchub_memberships_db_version']);
    }
}
