<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

final class AccessApiRateLimiter
{
    private const DEFAULT_LIMIT = 300;
    private const WINDOW_SECONDS = 60;
    private const LOCK_TIMEOUT_SECONDS = 1;

    /** @return array{allowed:bool, limit:int, remaining:int, retry_after:int} */
    public function consume(string $credentialPrefix): array
    {
        $limit = max(1, (int) apply_filters(
            'fchub_memberships/access_api_rate_limit',
            self::DEFAULT_LIMIT,
            $credentialPrefix
        ));
        $key = 'fchub_memberships_access_api_' . hash('sha256', $credentialPrefix);
        $lockName = $this->lockName($credentialPrefix);

        try {
            if (!$this->acquireLock($lockName)) {
                return $this->failClosed($limit);
            }
        } catch (\Throwable) {
            return $this->failClosed($limit);
        }

        $result = $this->failClosed($limit);
        $released = false;
        try {
            $result = $this->consumeLocked($key, $limit);
        } catch (\Throwable) {
            $result = $this->failClosed($limit);
        } finally {
            try {
                $released = $this->releaseLock($lockName);
            } catch (\Throwable) {
                $released = false;
            }
        }

        return $released ? $result : $this->failClosed($limit);
    }

    private function acquireLock(string $lockName): bool
    {
        global $wpdb;

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lockName,
            self::LOCK_TIMEOUT_SECONDS
        )) === 1;
    }

    private function releaseLock(string $lockName): bool
    {
        global $wpdb;

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            'SELECT RELEASE_LOCK(%s)',
            $lockName
        )) === 1;
    }

    private function lockName(string $credentialPrefix): string
    {
        global $wpdb;

        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $scope = (string) ($wpdb->dbname ?? '')
            . "\0"
            . (string) ($wpdb->prefix ?? '')
            . "\0"
            . $blogId
            . "\0"
            . $credentialPrefix;

        return 'fchub_access_' . substr(hash('sha256', $scope), 0, 51);
    }

    /** @return array{allowed:bool, limit:int, remaining:int, retry_after:int} */
    private function consumeLocked(string $key, int $limit): array
    {
        $now = (int) current_time('timestamp', true);
        $state = get_transient($key);

        if (!is_array($state)
            || !isset($state['window_started_at'], $state['count'])
            || $now - (int) $state['window_started_at'] >= self::WINDOW_SECONDS
        ) {
            $state = ['window_started_at' => $now, 'count' => 0];
        }

        $elapsed = max(0, $now - (int) $state['window_started_at']);
        if ((int) $state['count'] >= $limit) {
            return [
                'allowed' => false,
                'limit' => $limit,
                'remaining' => 0,
                'retry_after' => max(1, self::WINDOW_SECONDS - $elapsed),
            ];
        }

        $state['count'] = (int) $state['count'] + 1;
        if (!set_transient($key, $state, self::WINDOW_SECONDS)) {
            return $this->failClosed($limit);
        }

        return [
            'allowed' => true,
            'limit' => $limit,
            'remaining' => max(0, $limit - (int) $state['count']),
            'retry_after' => 0,
        ];
    }

    /** @return array{allowed:bool, limit:int, remaining:int, retry_after:int} */
    private function failClosed(int $limit): array
    {
        return [
            'allowed' => false,
            'limit' => $limit,
            'remaining' => 0,
            'retry_after' => 1,
        ];
    }
}
