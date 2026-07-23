<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http;

use FChubMemberships\Http\AccessApiRateLimiter;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AccessApiRateLimiterTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_fchub_test_transient_expirations'] = [];
        unset($GLOBALS['_fchub_test_current_timestamp']);
    }

    public function test_default_window_allows_300_requests_then_returns_retry_after(): void
    {
        $limiter = new AccessApiRateLimiter();
        $result = [];

        for ($request = 1; $request <= 300; $request++) {
            $result = $limiter->consume('fchub_a1b2c3');
            self::assertTrue($result['allowed']);
        }

        self::assertSame(0, $result['remaining']);
        self::assertSame([
            'allowed' => false,
            'limit' => 300,
            'remaining' => 0,
            'retry_after' => 60,
        ], $limiter->consume('fchub_a1b2c3'));
        self::assertCount(1, $GLOBALS['_fchub_test_transients']);
        self::assertStringNotContainsString('fchub_a1b2c3', array_key_first($GLOBALS['_fchub_test_transients']));
    }

    public function test_limit_filter_and_expired_window_are_deterministic(): void
    {
        add_filter('fchub_memberships/access_api_rate_limit', static fn(int $limit): int => 2);
        $GLOBALS['_fchub_test_current_timestamp'] = 1000;
        $limiter = new AccessApiRateLimiter();

        self::assertSame(1, $limiter->consume('prefix')['remaining']);
        self::assertSame(0, $limiter->consume('prefix')['remaining']);
        self::assertSame(60, $limiter->consume('prefix')['retry_after']);

        $GLOBALS['_fchub_test_current_timestamp'] = 1061;
        self::assertSame([
            'allowed' => true,
            'limit' => 2,
            'remaining' => 1,
            'retry_after' => 0,
        ], $limiter->consume('prefix'));
        self::assertSame(60, current($GLOBALS['_fchub_test_transient_expirations']));
    }

    public function test_transient_read_modify_write_is_guarded_by_one_advisory_lock(): void
    {
        $result = (new AccessApiRateLimiter())->consume('fchub_atomic');
        $queries = array_values(array_map(
            static fn(array $entry): string => (string) ($entry[1] ?? ''),
            $GLOBALS['_fchub_test_queries']
        ));

        self::assertTrue($result['allowed']);
        self::assertCount(2, $queries);
        self::assertStringContainsString('GET_LOCK(', $queries[0]);
        self::assertStringContainsString('RELEASE_LOCK(', $queries[1]);
    }

    public function test_lock_acquisition_failure_rejects_the_request_without_mutating_state(): void
    {
        add_filter('fchub_memberships/access_api_rate_limit', static fn(int $limit): int => 2);
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int =>
            str_contains($query, 'GET_LOCK(') ? 0 : 1;

        self::assertSame([
            'allowed' => false,
            'limit' => 2,
            'remaining' => 0,
            'retry_after' => 1,
        ], (new AccessApiRateLimiter())->consume('fchub_busy'));
        self::assertSame([], $GLOBALS['_fchub_test_transients']);
        self::assertCount(1, $GLOBALS['_fchub_test_queries']);
    }

    public function test_state_exception_releases_the_lock_and_fails_closed(): void
    {
        $GLOBALS['_fchub_test_transients'] = new class implements \ArrayAccess {
            public function offsetExists(mixed $offset): bool
            {
                return true;
            }

            public function offsetGet(mixed $offset): mixed
            {
                throw new \RuntimeException('Injected transient read failure.');
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }
        };

        $exception = null;
        $result = null;
        try {
            $result = (new AccessApiRateLimiter())->consume('fchub_exception');
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        self::assertNull($exception);
        self::assertSame([
            'allowed' => false,
            'limit' => 300,
            'remaining' => 0,
            'retry_after' => 1,
        ], $result);
        self::assertStringContainsString(
            'RELEASE_LOCK(',
            (string) ($GLOBALS['_fchub_test_queries'][1][1] ?? '')
        );
    }

    public function test_lock_release_failure_rejects_the_already_counted_request(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int =>
            str_contains($query, 'RELEASE_LOCK(') ? 0 : 1;

        self::assertSame([
            'allowed' => false,
            'limit' => 300,
            'remaining' => 0,
            'retry_after' => 1,
        ], (new AccessApiRateLimiter())->consume('fchub_release'));
        self::assertSame(1, current($GLOBALS['_fchub_test_transients'])['count']);
    }

    public function test_lock_identity_is_blog_scoped_bounded_and_contains_no_credential_prefix(): void
    {
        global $wpdb;

        $limiter = new AccessApiRateLimiter();
        $limiter->consume('fchub_visible-prefix');
        $first = (string) ($GLOBALS['_fchub_test_queries'][0][1] ?? '');

        $GLOBALS['_fchub_test_queries'] = [];
        $GLOBALS['_fchub_test_transients'] = [];
        $wpdb->prefix = 'wp_2_';
        $limiter->consume('fchub_visible-prefix');
        $second = (string) ($GLOBALS['_fchub_test_queries'][0][1] ?? '');

        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('fchub_visible-prefix', $first);
        self::assertStringNotContainsString('fchub_visible-prefix', $second);
        self::assertMatchesRegularExpression("/GET_LOCK\\('([^']{1,64})', 1\\)/", $first);
        self::assertMatchesRegularExpression("/GET_LOCK\\('([^']{1,64})', 1\\)/", $second);
    }
}
