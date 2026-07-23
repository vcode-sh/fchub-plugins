<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipSettingsOptionCoordinatorTest extends PluginTestCase
{
    public function test_shared_settings_option_coordinator_exists(): void
    {
        self::assertTrue(class_exists(MembershipSettingsOptionCoordinator::class));
    }

    public function test_mutation_reads_inside_the_shared_lock_and_preserves_fresh_keys(): void
    {
        $state = ['fc_space_mappings' => ['5' => '31'], 'concurrent_key' => 'fresh'];
        $locked = false;
        $coordinator = $this->coordinator($state, $locked);

        $result = $coordinator->mutate(static function (array $settings): array {
            $settings['fc_space_mappings'] = [];
            return $settings;
        });

        self::assertTrue($result['success']);
        self::assertTrue($result['changed']);
        self::assertSame(['fc_space_mappings' => [], 'concurrent_key' => 'fresh'], $state);
        self::assertFalse($locked);
    }

    public function test_default_reader_bypasses_a_pre_lock_option_cache_snapshot(): void
    {
        $cached = ['fc_space_mappings' => ['5' => '31'], 'cached_key' => 'stale'];
        $database = ['fc_space_mappings' => ['5' => '31'], 'concurrent_key' => 'fresh'];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $cached;
        self::assertSame($cached, get_option('fchub_memberships_settings'));
        $GLOBALS['_fchub_test_cache']['options:fchub_memberships_settings'] = $cached;
        $GLOBALS['_fchub_test_cache']['options:alloptions'] = ['fchub_memberships_settings' => $cached];
        $GLOBALS['_fchub_test_cache']['options:notoptions'] = ['fchub_memberships_settings' => true];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (&$database): int|string|null {
            if (str_contains($query, 'GET_LOCK(') || str_contains($query, 'RELEASE_LOCK(')) {
                return 1;
            }

            if (str_contains($query, 'FROM wp_options')) {
                return serialize($database);
            }

            return null;
        };

        $coordinator = new MembershipSettingsOptionCoordinator(
            null,
            null,
            null,
            static function (array $next) use (&$database): bool {
                $database = $next;
                return true;
            }
        );
        $result = $coordinator->mutate(static function (array $settings): array {
            $settings['fc_space_mappings'] = [];
            return $settings;
        });

        self::assertTrue($result['success']);
        self::assertSame([
            'fc_space_mappings' => [],
            'concurrent_key' => 'fresh',
        ], $database);
        self::assertSame($database, wp_cache_get('fchub_memberships_settings', 'options'));
        self::assertArrayNotHasKey('options:alloptions', $GLOBALS['_fchub_test_cache']);
        self::assertArrayNotHasKey('options:notoptions', $GLOBALS['_fchub_test_cache']);
    }

    public function test_compare_and_swap_rejects_an_interleaved_stale_settings_snapshot(): void
    {
        $state = ['fc_space_mappings' => ['5' => '31']];
        $locked = false;
        $coordinator = $this->coordinator($state, $locked);

        $result = $coordinator->synchronized(function (MembershipSettingsOptionCoordinator $lockedCoordinator) use (&$state): array {
            $expected = $lockedCoordinator->read();
            $state['admin_save'] = 'preserve-me';
            $next = $expected;
            $next['fc_space_mappings'] = [];
            return $lockedCoordinator->compareAndSwap($expected, $next);
        });

        self::assertTrue($result['success']);
        self::assertFalse($result['value']['success']);
        self::assertSame('conflict', $result['value']['reason']);
        self::assertSame(['fc_space_mappings' => ['5' => '31'], 'admin_save' => 'preserve-me'], $state);
    }

    public function test_no_change_is_success_even_when_wordpress_would_return_false(): void
    {
        $state = ['fc_enabled' => 'yes'];
        $locked = false;
        $writes = 0;
        $coordinator = $this->coordinator($state, $locked, $writes);

        $result = $coordinator->mutate(static fn(array $settings): array => $settings);

        self::assertTrue($result['success']);
        self::assertFalse($result['changed']);
        self::assertSame(0, $writes);
    }

    public function test_failed_option_write_is_reported_without_changing_the_snapshot(): void
    {
        $state = ['fc_enabled' => 'yes'];
        $locked = false;
        $coordinator = new MembershipSettingsOptionCoordinator(
            static function () use (&$locked): bool {
                if ($locked) {
                    return false;
                }
                $locked = true;
                return true;
            },
            static function () use (&$locked): void {
                $locked = false;
            },
            static function () use (&$state): array {
                return $state;
            },
            static fn(array $next): bool => false
        );

        $result = $coordinator->mutate(static function (array $settings): array {
            $settings['fc_enabled'] = 'no';
            return $settings;
        });

        self::assertFalse($result['success']);
        self::assertSame('write_failed', $result['reason']);
        self::assertSame(['fc_enabled' => 'yes'], $state);
    }

    public function test_lock_timeout_is_fail_closed_and_does_not_run_the_mutation(): void
    {
        $ran = false;
        $coordinator = new MembershipSettingsOptionCoordinator(
            static fn(): bool => false,
            static function (): void {
            },
            static fn(): array => [],
            static fn(array $next): bool => true
        );

        $result = $coordinator->mutate(static function (array $settings) use (&$ran): array {
            $ran = true;
            return $settings;
        });

        self::assertFalse($result['success']);
        self::assertSame('lock_unavailable', $result['reason']);
        self::assertFalse($ran);
    }

    public function test_default_database_lock_timeout_is_fail_closed(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int {
            return str_contains($query, 'GET_LOCK(') ? 0 : 1;
        };
        $ran = false;

        $result = (new MembershipSettingsOptionCoordinator())->mutate(
            static function (array $settings) use (&$ran): array {
                $ran = true;
                return $settings;
            }
        );

        self::assertFalse($result['success']);
        self::assertSame('lock_unavailable', $result['reason']);
        self::assertFalse($ran);
    }

    public function test_every_membership_settings_writer_uses_the_shared_coordinator(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = [];
        foreach ([$root . '/app', $root . '/tests/runtime'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        sort($paths);
        foreach ($paths as $path) {
            $relativePath = ltrim(substr($path, strlen($root)), '/');
            if ($relativePath === 'app/Integration/MembershipSettingsOptionCoordinator.php') {
                continue;
            }

            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                '/\\b(?:add|update)_option\\s*\\(\\s*[\'\"]fchub_memberships_settings[\'\"]/',
                $source,
                $relativePath
            );
            if ($relativePath !== 'app/Support/Migrations.php') {
                self::assertDoesNotMatchRegularExpression(
                    '/\\bdelete_option\\s*\\(\\s*[\'\"]fchub_memberships_settings[\'\"]/',
                    $source,
                    $relativePath
                );
            }
        }
    }

    private function coordinator(array &$state, bool &$locked, ?int &$writes = null): MembershipSettingsOptionCoordinator
    {
        $writes ??= 0;

        return new MembershipSettingsOptionCoordinator(
            static function () use (&$locked): bool {
                if ($locked) {
                    return false;
                }
                $locked = true;
                return true;
            },
            static function () use (&$locked): void {
                $locked = false;
            },
            static function () use (&$state): array {
                return $state;
            },
            static function (array $next) use (&$state, &$writes): bool {
                $writes++;
                $state = $next;
                return true;
            }
        );
    }
}
