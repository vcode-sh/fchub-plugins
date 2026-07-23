<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\WebhookRetryPolicy;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookRetryPolicyTest extends PluginTestCase
{
    public function test_accepts_every_two_xx_response_and_rejects_redirects(): void
    {
        $policy = new WebhookRetryPolicy();

        foreach ([200, 204, 299] as $code) {
            self::assertSame('succeeded', $policy->classify(1, $this->response($code), 1_000)['outcome']);
        }

        self::assertSame('retry', $policy->classify(1, $this->response(300), 1_000)['outcome']);
    }

    public function test_uses_the_complete_fixed_delay_sequence_for_non_two_xx_and_transport_errors(): void
    {
        $policy = new WebhookRetryPolicy();

        foreach (WebhookRetryPolicy::DELAYS as $offset => $delay) {
            $attempt = $offset + 1;
            foreach ([400, 429, 500, 503] as $code) {
                $result = $policy->classify($attempt, $this->response($code), 1_000);
                self::assertSame('retry', $result['outcome'], "HTTP {$code}, attempt {$attempt}");
                self::assertSame(1_000 + $delay, $result['next_timestamp']);
            }

            $transport = $policy->classify($attempt, new \WP_Error('http_request_failed', 'Network failed'), 1_000);
            self::assertSame('retry', $transport['outcome']);
            self::assertSame(1_000 + $delay, $transport['next_timestamp']);
        }
    }

    public function test_honours_only_strict_retry_after_values_for_429_and_503_and_caps_them(): void
    {
        $policy = new WebhookRetryPolicy();
        $now = 1_800_000_000;

        foreach ([429, 503] as $code) {
            self::assertSame(
                $now + 37,
                $policy->classify(1, $this->response($code, '', '37'), $now)['next_timestamp']
            );
            self::assertSame(
                $now + 86_400,
                $policy->classify(1, $this->response($code, '', '999999'), $now)['next_timestamp']
            );
            $date = gmdate('D, d M Y H:i:s \G\M\T', $now + 91);
            self::assertSame(
                $now + 91,
                $policy->classify(1, $this->response($code, '', $date), $now)['next_timestamp']
            );
        }

        foreach (['37 seconds', '+37', '3.7', 'Thursday-ish'] as $malformed) {
            self::assertSame(
                $now + 60,
                $policy->classify(1, $this->response(429, '', $malformed), $now)['next_timestamp']
            );
        }
        self::assertSame(
            $now + 60,
            $policy->classify(1, $this->response(429, '', ['37', '91']), $now)['next_timestamp']
        );
        self::assertSame(
            $now + 60,
            $policy->classify(1, $this->response(500, '', '37'), $now)['next_timestamp']
        );
    }

    public function test_attempt_seven_is_terminal_for_every_failure_class(): void
    {
        $policy = new WebhookRetryPolicy();

        foreach ([
            $this->response(400),
            $this->response(500),
            new \WP_Error('http_request_failed', 'Network failed'),
        ] as $failure) {
            $result = $policy->classify(7, $failure, 1_000);
            self::assertSame('failed', $result['outcome']);
            self::assertNull($result['next_timestamp']);
        }
    }

    public function test_bounds_response_and_transport_text_by_bytes_without_broken_utf8(): void
    {
        $policy = new WebhookRetryPolicy();
        $response = $policy->classify(1, $this->response(500, str_repeat('ż', 1_500)), 1_000);
        $transport = $policy->classify(1, new \WP_Error('failed', str_repeat('ż', 700)), 1_000);

        self::assertLessThanOrEqual(2_048, strlen($response['body']));
        self::assertMatchesRegularExpression('//u', $response['body']);
        self::assertLessThanOrEqual(1_024, strlen($transport['error']));
        self::assertMatchesRegularExpression('//u', $transport['error']);
        self::assertSame('webhook_transport_error', $transport['error']);
    }

    public function test_truncates_raw_bytes_before_utf8_repair_cannot_pull_later_data_into_storage(): void
    {
        $policy = new WebhookRetryPolicy();
        $response = $policy->classify(
            1,
            $this->response(500, str_repeat("\xFF", 100) . str_repeat('a', 1_948) . 'late-body-sentinel'),
            1_000
        );
        $transport = $policy->classify(
            1,
            new \WP_Error('failed', str_repeat("\xFF", 100) . str_repeat('b', 924) . 'late-error-sentinel'),
            1_000
        );

        self::assertStringNotContainsString('late-body-sentinel', $response['body']);
        self::assertStringNotContainsString('late-error-sentinel', $transport['error']);
        self::assertMatchesRegularExpression('//u', $response['body']);
        self::assertMatchesRegularExpression('//u', $transport['error']);
    }

    public function test_transport_failures_never_persist_the_raw_wordpress_error(): void
    {
        $result = (new WebhookRetryPolicy())->classify(
            1,
            new \WP_Error('http_request_failed', 'credential-sentinel https://private.example'),
            1_000
        );

        self::assertSame('webhook_transport_error', $result['error']);
        self::assertStringNotContainsString('credential-sentinel', serialize($result));
        self::assertStringNotContainsString('private.example', serialize($result));
    }

    /** @return array<string, mixed> */
    private function response(int $code, string $body = '', string|array $retryAfter = ''): array
    {
        return [
            'response' => ['code' => $code],
            'body' => $body,
            'headers' => $retryAfter === '' ? [] : ['Retry-After' => $retryAfter],
        ];
    }
}
