<?php

declare(strict_types=1);

namespace FChubMemberships\Http\Controllers;

use FChubMemberships\Http\WebhookRestArguments;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Integration\WebhookDeliveryWorker;
use FChubMemberships\Integration\WebhookEndpointConfig;
use FChubMemberships\Integration\WebhookEndpointPolicy;
use FChubMemberships\Integration\WebhookEnvelope;
use FChubMemberships\Integration\WebhookQueue;
use FChubMemberships\Storage\WebhookDeliveryRepository;
use FChubMemberships\Storage\WebhookEventRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Support\Migrations;

defined('ABSPATH') || exit;

final class WebhookController
{
    private object $deliveryRepository;
    private object $eventRepository;
    private object $queue;
    private object $worker;
    private object $endpointPolicy;
    private MembershipSettingsOptionCoordinator $settingsCoordinator;
    private Clock $clock;
    private \Closure $readiness;

    public function __construct(
        ?object $deliveryRepository = null,
        ?object $eventRepository = null,
        ?object $queue = null,
        ?object $worker = null,
        ?object $endpointPolicy = null,
        ?MembershipSettingsOptionCoordinator $settingsCoordinator = null,
        ?Clock $clock = null,
        ?callable $readiness = null
    ) {
        $this->clock = $clock ?? new Clock(null, new \DateTimeZone('UTC'));
        $this->deliveryRepository = $deliveryRepository ?? new WebhookDeliveryRepository($this->clock);
        $this->eventRepository = $eventRepository ?? new WebhookEventRepository();
        $this->queue = $queue ?? new WebhookQueue();
        $this->worker = $worker ?? new WebhookDeliveryWorker(
            $this->deliveryRepository,
            $this->eventRepository,
            $this->queue,
            null,
            $this->clock
        );
        $this->endpointPolicy = $endpointPolicy ?? new WebhookEndpointPolicy();
        $this->settingsCoordinator = $settingsCoordinator ?? new MembershipSettingsOptionCoordinator();
        $this->readiness = \Closure::fromCallable($readiness ?? [self::class, 'persistenceReady']);
    }

    public static function registerRoutes(): void
    {
        $namespace = 'fchub-memberships/v1';

        register_rest_route($namespace, '/admin/webhooks/health', [
            'methods' => 'GET',
            'callback' => [self::class, 'health'],
            'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route($namespace, '/admin/webhooks/deliveries', [
            'methods' => 'GET',
            'callback' => [self::class, 'deliveries'],
            'permission_callback' => [self::class, 'permission'],
            'args' => WebhookRestArguments::deliveries(),
        ]);
        register_rest_route($namespace, '/admin/webhooks/deliveries/(?P<id>\d+)/retry', [
            'methods' => 'POST',
            'callback' => [self::class, 'retry'],
            'permission_callback' => [self::class, 'permission'],
            'args' => WebhookRestArguments::retry(),
        ]);
        register_rest_route($namespace, '/admin/webhooks/deliveries/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => [self::class, 'cancel'],
            'permission_callback' => [self::class, 'permission'],
            'args' => WebhookRestArguments::retry(),
        ]);
        register_rest_route($namespace, '/admin/webhooks/test', [
            'methods' => 'POST',
            'callback' => [self::class, 'test'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function permission(\WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public static function health(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->healthResponse();
    }

    public static function deliveries(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->deliveriesResponse($request);
    }

    public static function retry(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->retryDelivery($request);
    }

    public static function test(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->testDelivery($request);
    }

    public static function cancel(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->cancelDelivery($request);
    }

    public function healthResponse(): \WP_REST_Response
    {
        if (!$this->isReady()) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        try {
            $settings = $this->readSettings();
            if ($settings === null) {
                return $this->error('fchub_webhook_storage_unavailable', 503);
            }
            $summary = $this->deliveryRepository->summary();
            $configured = $this->configured($settings);
        } catch (\Throwable) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        $pending = (int) ($summary['pending'] ?? 0);
        $processing = (int) ($summary['processing'] ?? 0);
        $retrying = (int) ($summary['retrying'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);
        $status = !$configured
            ? (($settings['webhook_enabled'] ?? 'no') === 'yes' ? 'needs_setup' : 'off')
            : (($retrying + $failed) > 0 ? 'degraded' : 'ready');

        return new \WP_REST_Response(['data' => [
            'status' => $status,
            'pending_count' => $pending,
            'processing_count' => $processing,
            'retrying_count' => $retrying,
            'succeeded_count' => (int) ($summary['succeeded'] ?? 0),
            'failed_count' => $failed,
            'last_success_at' => $summary['last_success_at'] ?? null,
        ]]);
    }

    public function deliveriesResponse(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!$this->isReady()) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        $page = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?? 20)));
        $status = (string) ($request->get_param('status') ?? '');
        try {
            $rows = $this->deliveryRepository->recent([
                'page' => $page,
                'per_page' => $perPage,
                'status' => $status,
            ]);
        } catch (\Throwable) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        return new \WP_REST_Response(['data' => [
            'deliveries' => array_map([$this, 'publicDelivery'], $rows),
            'page' => $page,
            'per_page' => $perPage,
        ]]);
    }

    public function retryDelivery(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!$this->isReady()) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        $deliveryId = (int) $request->get_param('id');
        try {
            $result = $this->settingsCoordinator->synchronized(function () use ($deliveryId): \WP_REST_Response {
                $delivery = $this->deliveryRepository->find($deliveryId);
                if (!is_array($delivery)) {
                    return $this->error('fchub_webhook_delivery_not_found', 404);
                }
                if (($delivery['status'] ?? '') !== 'failed') {
                    return $this->error(
                        'fchub_webhook_retry_not_allowed',
                        409,
                        ['status' => (string) ($delivery['status'] ?? '')]
                    );
                }

                if (!$this->deliveryRepository->resetForManualRetry($deliveryId)) {
                    $current = $this->deliveryRepository->find($deliveryId);
                    if (!is_array($current)) {
                        return $this->error('fchub_webhook_delivery_not_found', 404);
                    }
                    if (($current['status'] ?? '') !== 'failed') {
                        return $this->error(
                            'fchub_webhook_retry_not_allowed',
                            409,
                            ['status' => (string) ($current['status'] ?? '')]
                        );
                    }

                    return $this->error('fchub_webhook_storage_unavailable', 503);
                }

                try {
                    $scheduled = $this->queue->schedule($deliveryId, 1, $this->clock->now()->getTimestamp());
                } catch (\Throwable) {
                    return $this->error('fchub_webhook_retry_schedule_failed', 503);
                }
                if (!$scheduled) {
                    return $this->error('fchub_webhook_retry_schedule_failed', 503);
                }

                return new \WP_REST_Response(['data' => ['id' => $deliveryId, 'status' => 'pending']], 202);
            });
        } catch (\Throwable) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        if (!$result['success'] || !$result['value'] instanceof \WP_REST_Response) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        return $result['value'];
    }

    public function cancelDelivery(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!$this->isReady()) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        $deliveryId = (int) $request->get_param('id');
        try {
            $result = $this->settingsCoordinator->synchronized(function () use ($deliveryId): \WP_REST_Response {
                $delivery = $this->deliveryRepository->find($deliveryId);
                if (!is_array($delivery)) {
                    return $this->error('fchub_webhook_delivery_not_found', 404);
                }
                if (!in_array((string) ($delivery['status'] ?? ''), ['pending', 'retrying'], true)) {
                    return $this->error(
                        'fchub_webhook_cancel_not_allowed',
                        409,
                        ['status' => (string) ($delivery['status'] ?? '')]
                    );
                }
                if (!$this->deliveryRepository->cancel($deliveryId)) {
                    return $this->error('fchub_webhook_cancel_not_allowed', 409);
                }
                $this->queue->cancel($deliveryId);

                return new \WP_REST_Response(['data' => [
                    'id' => $deliveryId,
                    'status' => 'cancelled',
                ]]);
            });
        } catch (\Throwable) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        if (!$result['success'] || !$result['value'] instanceof \WP_REST_Response) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        return $result['value'];
    }

    public function testDelivery(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!$this->isReady()) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        try {
            $persisted = $this->settingsCoordinator->synchronized(function (
                MembershipSettingsOptionCoordinator $coordinator
            ): array|\WP_REST_Response {
                $settings = $coordinator->read();
                $destinations = $this->safeDestinations($settings);
                if ($destinations === []) {
                    return $this->error('fchub_webhook_not_ready', 422);
                }

                $envelope = WebhookEnvelope::create('test', [
                    'message' => 'This is a test webhook from FCHub Memberships',
                ]);
                $body = WebhookEnvelope::encode($envelope);
                $occurredAt = (new \DateTimeImmutable((string) $envelope['occurred_at']))
                    ->setTimezone(new \DateTimeZone('UTC'));
                $storedAt = $this->clock->storage($occurredAt);
                $this->eventRepository->create([
                    'event_id' => (string) $envelope['id'],
                    'event_type' => 'test',
                    'schema_version' => (string) $envelope['schema_version'],
                    'body' => $body,
                    'occurred_at' => $storedAt,
                    'created_at' => $storedAt,
                ]);

                return [
                    'event_id' => (string) $envelope['id'],
                    'deliveries' => $this->deliveryRepository->createMany(
                        (string) $envelope['id'],
                        $destinations
                    ),
                ];
            });
        } catch (\Throwable) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }
        if (!$persisted['success']) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }
        if ($persisted['value'] instanceof \WP_REST_Response) {
            return $persisted['value'];
        }
        if (!is_array($persisted['value'] ?? null)) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        $projections = [];
        foreach ($persisted['value']['deliveries'] as $delivery) {
            $deliveryId = (int) ($delivery['id'] ?? 0);
            try {
                $this->worker->deliverNow($deliveryId);
            } catch (\Throwable) {
                // The durable row remains the authority even when synchronous delivery throws.
            }
            try {
                $current = $this->deliveryRepository->find($deliveryId);
            } catch (\Throwable) {
                return $this->error('fchub_webhook_storage_unavailable', 503);
            }
            if (!is_array($current)) {
                return $this->error('fchub_webhook_storage_unavailable', 503);
            }
            $projections[] = [
                'id' => $deliveryId,
                'destination_url' => esc_url((string) ($current['destination_url'] ?? '')),
                'status' => (string) ($current['status'] ?? ''),
            ];
        }

        return new \WP_REST_Response(['data' => [
            'success' => $projections !== []
                && count(array_filter($projections, static fn(array $row): bool => $row['status'] === 'succeeded')) === count($projections),
            'event_id' => (string) $persisted['value']['event_id'],
            'results' => $projections,
        ]]);
    }

    private function isReady(): bool
    {
        try {
            return (bool) ($this->readiness)();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    private function readSettings(): ?array
    {
        $result = $this->settingsCoordinator->synchronized(
            static fn(MembershipSettingsOptionCoordinator $coordinator): array => $coordinator->read()
        );

        return $result['success'] && is_array($result['value'] ?? null) ? $result['value'] : null;
    }

    /** @param array<string, mixed> $settings */
    private function configured(array $settings): bool
    {
        return WebhookEndpointConfig::active($settings) !== [];
    }

    /** @param array<string, mixed> $settings @return list<string> */
    private function safeDestinations(array $settings): array
    {
        $urls = [];
        foreach (WebhookEndpointConfig::all($settings) as $endpoint) {
            $url = (string) ($endpoint['url'] ?? '');
            if ((string) ($endpoint['secret'] ?? '') === ''
                || $this->endpointPolicy->validate($url) !== true
            ) {
                continue;
            }
            $normalised = $this->endpointPolicy->normalise($url);
            if (count($normalised) === 1) {
                $urls[] = $normalised[0];
            }
        }

        return array_values(array_unique($urls));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicDelivery(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'event_id' => (string) ($row['event_id'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'destination_url' => esc_url((string) ($row['destination_url'] ?? '')),
            'status' => (string) ($row['status'] ?? ''),
            'attempt_count' => (int) ($row['attempt_count'] ?? 0),
            'response_code' => isset($row['response_code']) ? (int) $row['response_code'] : null,
            'error_message' => $this->publicError((string) ($row['error_message'] ?? '')),
            'next_attempt_at' => $row['next_attempt_at'] ?? null,
            'last_attempt_at' => $row['last_attempt_at'] ?? null,
            'delivered_at' => $row['delivered_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function publicError(string $error): string
    {
        $error = substr($error, 0, 500);
        if (preg_match('//u', $error) !== 1) {
            $converted = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $error) : false;
            $error = is_string($converted) ? $converted : '';
        }

        return wp_strip_all_tags($error);
    }

    /** @param array<string, mixed>|null $data */
    private function error(string $code, int $status, ?array $data = null): \WP_REST_Response
    {
        $messages = [
            'fchub_webhook_delivery_not_found' => 'Webhook delivery was not found.',
            'fchub_webhook_retry_not_allowed' => 'Webhook delivery cannot be retried in its current state.',
            'fchub_webhook_cancel_not_allowed' => 'Webhook delivery cannot be stopped in its current state.',
            'fchub_webhook_retry_schedule_failed' => 'Webhook retry could not be scheduled.',
            'fchub_webhook_not_ready' => 'Configure safe webhook destinations and a signing secret first.',
            'fchub_webhook_storage_unavailable' => 'Webhook storage is temporarily unavailable.',
        ];

        $response = [
            'code' => $code,
            'message' => __($messages[$code] ?? 'Webhook operation failed.', 'fchub-memberships'),
        ];
        if ($data !== null) {
            $response['data'] = $data;
        }

        return new \WP_REST_Response($response, $status);
    }

    private static function persistenceReady(): bool
    {
        if (version_compare((string) get_option('fchub_memberships_db_version', '0'), '1.8.0', '<')) {
            return false;
        }

        return Migrations::verifyWebhookSchema() === [];
    }
}
