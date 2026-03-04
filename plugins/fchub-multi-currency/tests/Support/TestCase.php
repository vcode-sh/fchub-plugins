<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset global mock state
        $GLOBALS['wp_options'] = [];
        $GLOBALS['wp_actions_fired'] = [];
        $GLOBALS['wp_actions_registered'] = [];
        $GLOBALS['wp_filters_registered'] = [];
        $GLOBALS['wp_mock_posts'] = [];
        $GLOBALS['wp_mock_current_user_id'] = 0;
        $GLOBALS['wp_mock_user_caps'] = [];
        $GLOBALS['wp_mock_users'] = [];
        $GLOBALS['wp_transients'] = [];
        $GLOBALS['wp_mock_is_admin'] = false;
        $GLOBALS['wp_mock_cookies'] = [];
        $GLOBALS['wp_mock_user_meta'] = [];
        $GLOBALS['wp_mock_post_meta'] = [];
        $GLOBALS['wp_cache_store'] = [];

        // Reset wpdb mock state
        $GLOBALS['wpdb_mock_results'] = [];
        $GLOBALS['wpdb_mock_row'] = null;
        $GLOBALS['wpdb_mock_var'] = null;
        $GLOBALS['wpdb_mock_col'] = [];
        $GLOBALS['wpdb_mock_query_result'] = true;
        $GLOBALS['wpdb']->resetQueries();

        // Reset FluentCRM mock state
        $GLOBALS['fluentcrm_mock_contact'] = null;
        $GLOBALS['fluentcrm_mock_tag_id'] = 1;
        $GLOBALS['fluentcrm_custom_field_updates'] = [];
        $GLOBALS['fluentcrm_attached_tags'] = [];

        // Reset wp_send_json state
        $GLOBALS['wp_send_json_data'] = null;
        $GLOBALS['wp_send_json_status'] = null;

        // Reset CurrencyContextService singleton
        \FChubMultiCurrency\Domain\Services\CurrencyContextService::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function setCurrentUserId(int $userId): void
    {
        $GLOBALS['wp_mock_current_user_id'] = $userId;
    }

    protected function setOption(string $key, $value): void
    {
        $GLOBALS['wp_options'][$key] = $value;
    }

    protected function setUserCapability(int $userId, string $capability, bool $value = true): void
    {
        $GLOBALS['wp_mock_user_caps'][$userId][$capability] = $value;
    }

    protected function setUserMeta(int $userId, string $key, $value): void
    {
        $GLOBALS['wp_mock_user_meta'][$userId][$key] = $value;
    }

    protected function setPostMeta(int $postId, string $key, $value): void
    {
        $GLOBALS['wp_mock_post_meta'][$postId][$key] = $value;
    }

    protected function setWpdbMockRow(?array $row): void
    {
        $GLOBALS['wpdb_mock_row'] = $row;
    }

    protected function setWpdbMockVar($value): void
    {
        $GLOBALS['wpdb_mock_var'] = $value;
    }

    protected function setWpdbMockResults(array $results): void
    {
        $GLOBALS['wpdb_mock_results'] = $results;
    }

    protected function getActionsFired(string $tag): array
    {
        return array_values(array_filter(
            $GLOBALS['wp_actions_fired'],
            fn($a) => $a['tag'] === $tag
        ));
    }

    protected function assertHookFired(string $tag, string $message = ''): void
    {
        $found = $this->getActionsFired($tag);
        $this->assertNotEmpty($found, $message ?: "Expected hook '{$tag}' to have been fired.");
    }

    protected function assertHookNotFired(string $tag, string $message = ''): void
    {
        $found = $this->getActionsFired($tag);
        $this->assertEmpty($found, $message ?: "Expected hook '{$tag}' to NOT have been fired.");
    }

    protected function getLastQuery(): string
    {
        $queries = $GLOBALS['wpdb']->queries;
        return end($queries) ?: '';
    }
}
