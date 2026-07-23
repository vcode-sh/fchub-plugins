<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\WebhookDeliveryWorker;
use FChubMemberships\Integration\WebhookRetryPolicy;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookDeliveryWorkerTest extends PluginTestCase
{
    private const EVENT_ID = '8f14b86a-b3ec-4fe5-a8f7-8a01b694bf15';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_fchub_test_safe_remote_posts'] = [];
        unset($GLOBALS['_fchub_test_safe_remote_post_result']);
    }

    public function test_missing_delivery_does_not_load_event_or_secret(): void
    {
        $fixture = $this->fixture(null, null);

        self::assertSame(['status' => 'missing'], $fixture['worker']->deliverNow(99));
        self::assertSame(0, $fixture['events']->findCalls);
        self::assertSame(0, $fixture['settingsCalls']());
    }

    public function test_concurrent_acquisition_does_not_load_event_or_secret(): void
    {
        $fixture = $this->fixture($this->delivery(), $this->event());
        $fixture['deliveries']->claim = null;

        self::assertSame(['status' => 'unavailable'], $fixture['worker']->deliverNow(7));
        self::assertSame(0, $fixture['events']->findCalls);
        self::assertSame(0, $fixture['settingsCalls']());
    }

    public function test_sends_exact_stored_body_with_required_headers_and_marks_success(): void
    {
        $body = "{\n  \"raw\": \"body-sentinel\"\n}";
        $fixture = $this->fixture($this->delivery(), $this->event($body));
        $GLOBALS['_fchub_test_safe_remote_post_result'] = [
            'response' => ['code' => 204],
            'body' => 'accepted',
            'headers' => [],
        ];

        $result = $fixture['worker']->deliverNow(7);

        self::assertSame('succeeded', $result['status']);
        self::assertCount(1, $GLOBALS['_fchub_test_safe_remote_posts']);
        [$url, $arguments] = $GLOBALS['_fchub_test_safe_remote_posts'][0];
        self::assertSame('https://hooks.example.com/memberships', $url);
        self::assertSame($body, $arguments['body']);
        self::assertSame(15, $arguments['timeout']);
        self::assertSame(3, $arguments['redirection']);
        self::assertSame('body', $arguments['data_format']);
        self::assertSame('application/json', $arguments['headers']['Content-Type']);
        self::assertSame('grant_created', $arguments['headers']['X-FCHub-Event']);
        self::assertSame(self::EVENT_ID, $arguments['headers']['X-FCHub-Delivery']);
        self::assertSame('2026-07-22T08:00:00+00:00', $arguments['headers']['X-FCHub-Timestamp']);
        self::assertSame(hash_hmac('sha256', $body, 'current-secret'), $arguments['headers']['X-FCHub-Signature']);
        self::assertSame(204, $fixture['deliveries']->succeeded[0][3]);
        self::assertSame('accepted', $fixture['deliveries']->succeeded[0][4]);
    }

    public function test_http_500_is_persisted_as_retrying_before_a_unique_future_action_is_scheduled(): void
    {
        $fixture = $this->fixture($this->delivery(), $this->event());
        $GLOBALS['_fchub_test_safe_remote_post_result'] = [
            'response' => ['code' => 500],
            'body' => 'try later',
            'headers' => [],
        ];

        $result = $fixture['worker']->deliverNow(7);

        self::assertSame('retrying', $result['status']);
        self::assertTrue($result['scheduled']);
        self::assertCount(1, $fixture['deliveries']->retrying);
        self::assertSame(500, $fixture['deliveries']->retrying[0][3]);
        self::assertSame([[7, 2, 1_800_000_060]], $fixture['queue']->scheduled);
        self::assertSame(['retry-cas', 'schedule'], $fixture['order']);
    }

    public function test_scheduler_failure_leaves_retry_state_durable_and_is_logged_without_sensitive_values(): void
    {
        $fixture = $this->fixture($this->delivery(), $this->event());
        $fixture['queue']->throw = true;
        $GLOBALS['_fchub_test_safe_remote_post_result'] = new \WP_Error(
            'http_request_failed',
            'transport-secret body-sentinel https://private.example'
        );

        $result = $fixture['worker']->deliverNow(7);

        self::assertSame('retrying', $result['status']);
        self::assertFalse($result['scheduled']);
        self::assertCount(1, $fixture['deliveries']->retrying);
        $logs = serialize($fixture['logs']);
        self::assertStringNotContainsString('transport-secret', $logs);
        self::assertStringNotContainsString('body-sentinel', $logs);
        self::assertStringNotContainsString('private.example', $logs);
        self::assertStringNotContainsString('current-secret', $logs);
        self::assertCount(1, $fixture['logs']);
    }

    public function test_missing_event_and_secret_never_send_unsigned_requests(): void
    {
        $missingEvent = $this->fixture($this->delivery(), null);
        self::assertSame('failed', $missingEvent['worker']->deliverNow(7)['status']);
        self::assertSame('webhook_event_missing', $missingEvent['deliveries']->failed[0][5]);

        $missingSecret = $this->fixture($this->delivery(), $this->event(), '');
        self::assertSame('retrying', $missingSecret['worker']->deliverNow(7)['status']);
        self::assertSame('webhook_secret_missing', $missingSecret['deliveries']->retrying[0][5]);
        self::assertSame([], $GLOBALS['_fchub_test_safe_remote_posts']);
    }

    public function test_attempt_seven_is_terminal_and_response_text_is_bounded(): void
    {
        $fixture = $this->fixture($this->delivery(7), $this->event());
        $GLOBALS['_fchub_test_safe_remote_post_result'] = [
            'response' => ['code' => 500],
            'body' => str_repeat('ż', 1_500),
            'headers' => [],
        ];

        $result = $fixture['worker']->deliverNow(7);

        self::assertSame('failed', $result['status']);
        self::assertSame([], $fixture['queue']->scheduled);
        self::assertLessThanOrEqual(2_048, strlen($fixture['deliveries']->failed[0][4]));
        self::assertMatchesRegularExpression('//u', $fixture['deliveries']->failed[0][4]);
    }

    public function test_stale_completion_cas_never_schedules_or_reports_owned_state(): void
    {
        $retry = $this->fixture($this->delivery(), $this->event());
        $retry['deliveries']->allowRetry = false;
        $GLOBALS['_fchub_test_safe_remote_post_result'] = ['response' => ['code' => 500], 'body' => '', 'headers' => []];
        self::assertSame(['status' => 'lost'], $retry['worker']->deliverNow(7));
        self::assertSame([], $retry['queue']->scheduled);

        $success = $this->fixture($this->delivery(), $this->event());
        $success['deliveries']->allowSuccess = false;
        $GLOBALS['_fchub_test_safe_remote_post_result'] = ['response' => ['code' => 204], 'body' => '', 'headers' => []];
        self::assertSame(['status' => 'lost'], $success['worker']->deliverNow(7));

        $failure = $this->fixture($this->delivery(7), $this->event());
        $failure['deliveries']->allowFailure = false;
        $GLOBALS['_fchub_test_safe_remote_post_result'] = ['response' => ['code' => 500], 'body' => '', 'headers' => []];
        self::assertSame(['status' => 'lost'], $failure['worker']->deliverNow(7));
        self::assertSame([], $failure['queue']->scheduled);
    }

    public function test_retry_and_terminal_rows_redact_echoed_payload_credentials_signature_and_destination(): void
    {
        foreach ([1, 7] as $attempt) {
            $body = '{"private":"event-body-sentinel"}';
            $fixture = $this->fixture($this->delivery($attempt), $this->event($body));
            $signature = hash_hmac('sha256', $body, 'current-secret');
            $GLOBALS['_fchub_test_safe_remote_post_result'] = [
                'response' => ['code' => 500],
                'body' => implode(' ', [
                    'current-secret',
                    $signature,
                    'https://hooks.example.com/memberships',
                    $body,
                ]),
                'headers' => [],
            ];

            $fixture['worker']->deliverNow(7);
            $stored = $attempt === 7
                ? serialize($fixture['deliveries']->failed)
                : serialize($fixture['deliveries']->retrying);
            self::assertStringNotContainsString('current-secret', $stored);
            self::assertStringNotContainsString($signature, $stored);
            self::assertStringNotContainsString('hooks.example.com', $stored);
            self::assertStringNotContainsString('event-body-sentinel', $stored);
            self::assertStringContainsString('[redacted]', $stored);
        }
    }

    public function test_default_settings_reader_uses_fresh_internal_secret_while_admin_response_remains_redacted(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_enabled' => 'yes',
            'webhook_secret' => 'stored-secret-sentinel',
        ];
        $fixture = $this->fixture($this->delivery(), $this->event(), '', true);
        $GLOBALS['_fchub_test_safe_remote_post_result'] = ['response' => ['code' => 204], 'body' => '', 'headers' => []];

        self::assertSame('succeeded', $fixture['worker']->deliverNow(7)['status']);
        $headers = $GLOBALS['_fchub_test_safe_remote_posts'][0][1]['headers'];
        self::assertSame(
            hash_hmac('sha256', '{"raw":"body-sentinel"}', 'stored-secret-sentinel'),
            $headers['X-FCHub-Signature']
        );
        $public = \FChubMemberships\Http\Controllers\SettingsController::get(new \WP_REST_Request())->get_data();
        self::assertArrayNotHasKey('webhook_secret', $public['data']);
        self::assertStringNotContainsString('stored-secret-sentinel', serialize($public));
    }

    public function test_completion_uses_fresh_time_and_cannot_overwrite_after_lease_expiry(): void
    {
        $times = [
            new \DateTimeImmutable('@1800000000'),
            new \DateTimeImmutable('@1800000301'),
        ];
        $fixture = $this->fixture(
            $this->delivery(),
            $this->event(),
            'current-secret',
            false,
            static function () use (&$times): \DateTimeImmutable { return array_shift($times); }
        );
        $GLOBALS['_fchub_test_safe_remote_post_result'] = ['response' => ['code' => 204], 'body' => '', 'headers' => []];

        self::assertSame(['status' => 'lost'], $fixture['worker']->deliverNow(7));
        self::assertSame('2027-01-15 08:05:01', $fixture['deliveries']->succeeded[0][5]);
    }

    public function test_redaction_rebounds_expanded_response_text(): void
    {
        $body = '{}';
        $fixture = $this->fixture($this->delivery(), $this->event($body));
        $GLOBALS['_fchub_test_safe_remote_post_result'] = [
            'response' => ['code' => 500],
            'body' => str_repeat($body, 1_024),
            'headers' => [],
        ];

        $fixture['worker']->deliverNow(7);
        $stored = $fixture['deliveries']->retrying[0][4];
        self::assertLessThanOrEqual(2_048, strlen($stored));
        self::assertMatchesRegularExpression('//u', $stored);
    }

    /** @return array<string, mixed> */
    private function fixture(
        ?array $delivery,
        ?array $event,
        string $secret = 'current-secret',
        bool $useDefaultSettings = false,
        ?callable $nowProvider = null
    ): array
    {
        $order = [];
        $deliveries = new class($delivery, $order) {
            public ?array $claim;
            public array $succeeded = [];
            public array $retrying = [];
            public array $failed = [];
            public bool $allowSuccess = true;
            public bool $allowRetry = true;
            public bool $allowFailure = true;
            public string $leaseExpiresAt = '';

            public function __construct(private ?array $delivery, private array &$order)
            {
                $this->claim = $delivery;
            }

            public function find(int $id): ?array { return $this->delivery; }
            public function acquire(int $id, string $owner, string $attemptedAt, string $leaseExpiresAt): ?array
            {
                $this->leaseExpiresAt = $leaseExpiresAt;
                return $this->claim;
            }
            public function markSucceeded(int $id, string $owner, int $attempt, int $code, string $body, string $at): bool
            {
                $this->succeeded[] = func_get_args(); return $this->allowSuccess && $at < $this->leaseExpiresAt;
            }
            public function markRetrying(int $id, string $owner, int $attempt, ?int $code, string $body, string $error, string $nextAt): bool
            {
                $this->retrying[] = func_get_args(); $this->order[] = 'retry-cas'; return $this->allowRetry;
            }
            public function markFailed(int $id, string $owner, int $attempt, ?int $code, string $body, string $error, string $at): bool
            {
                $this->failed[] = func_get_args(); return $this->allowFailure && $at < $this->leaseExpiresAt;
            }
        };
        $events = new class($event) {
            public int $findCalls = 0;
            public function __construct(private ?array $event) {}
            public function findByEventId(string $eventId): ?array { $this->findCalls++; return $this->event; }
        };
        $queue = new class($order) {
            public array $scheduled = [];
            public bool $throw = false;
            public function __construct(private array &$order) {}
            public function schedule(int $id, int $attempt, int $timestamp): bool
            {
                $this->order[] = 'schedule';
                if ($this->throw) { throw new \RuntimeException('scheduler internals'); }
                $this->scheduled[] = func_get_args(); return true;
            }
        };
        $settingsCalls = 0;
        $logs = [];
        $clock = new Clock(new \DateTimeImmutable('@1800000000'), new \DateTimeZone('UTC'));
        $worker = new WebhookDeliveryWorker(
            $deliveries,
            $events,
            $queue,
            new WebhookRetryPolicy(),
            $clock,
            $useDefaultSettings ? null : static function () use (&$settingsCalls, $secret): array { $settingsCalls++; return ['webhook_secret' => $secret]; },
            null,
            static fn(): string => str_repeat('a', 32),
            static function (string $title, string $description, array $context = []) use (&$logs): void {
                $logs[] = [$title, $description, $context];
            },
            $nowProvider
        );

        return [
            'worker' => $worker,
            'deliveries' => $deliveries,
            'events' => $events,
            'queue' => $queue,
            'settingsCalls' => static fn(): int => $settingsCalls,
            'logs' => &$logs,
            'order' => &$order,
        ];
    }

    /** @return array<string, mixed> */
    private function delivery(int $attempt = 1): array
    {
        return [
            'id' => 7,
            'event_id' => self::EVENT_ID,
            'destination_url' => 'https://hooks.example.com/memberships',
            'status' => 'processing',
            'attempt_count' => $attempt,
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $body = '{"raw":"body-sentinel"}'): array
    {
        return [
            'event_id' => self::EVENT_ID,
            'event_type' => 'grant_created',
            'occurred_at' => '2026-07-22 08:00:00',
            'body' => $body,
        ];
    }
}
