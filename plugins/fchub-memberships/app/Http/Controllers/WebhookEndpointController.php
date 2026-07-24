<?php

declare(strict_types=1);

namespace FChubMemberships\Http\Controllers;

use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Integration\WebhookEndpointConfig;
use FChubMemberships\Integration\WebhookEndpointPolicy;
use FChubMemberships\Integration\WebhookEnvelope;
use FChubMemberships\Integration\WebhookSecret;
use FChubMemberships\Storage\WebhookDeliveryRepository;
use FChubMemberships\Storage\WebhookEventRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Support\Migrations;

defined('ABSPATH') || exit;

final class WebhookEndpointController
{
    private object $deliveryRepository;
    private object $eventRepository;
    private object $worker;
    private object $endpointPolicy;
    private MembershipSettingsOptionCoordinator $settingsCoordinator;
    private Clock $clock;
    private \Closure $idFactory;
    private \Closure $secretFactory;
    private \Closure $readiness;

    public function __construct(
        ?object $deliveryRepository = null,
        ?object $eventRepository = null,
        ?object $worker = null,
        ?object $endpointPolicy = null,
        ?MembershipSettingsOptionCoordinator $settingsCoordinator = null,
        ?Clock $clock = null,
        ?callable $idFactory = null,
        ?callable $secretFactory = null,
        ?callable $readiness = null
    ) {
        $this->deliveryRepository = $deliveryRepository ?? new WebhookDeliveryRepository();
        $this->eventRepository = $eventRepository ?? new WebhookEventRepository();
        $this->worker = $worker ?? new \FChubMemberships\Integration\WebhookDeliveryWorker();
        $this->endpointPolicy = $endpointPolicy ?? new WebhookEndpointPolicy();
        $this->settingsCoordinator = $settingsCoordinator ?? new MembershipSettingsOptionCoordinator();
        $this->clock = $clock ?? new Clock(null, new \DateTimeZone('UTC'));
        $this->idFactory = \Closure::fromCallable(
            $idFactory ?? static fn(): string => 'we_' . bin2hex(random_bytes(12))
        );
        $this->secretFactory = \Closure::fromCallable($secretFactory ?? [WebhookSecret::class, 'generate']);
        $this->readiness = \Closure::fromCallable($readiness ?? [self::class, 'persistenceReady']);
    }

    public static function registerRoutes(): void
    {
        $namespace = 'fchub-memberships/v1';
        $idPattern = '(?P<id>[A-Za-z0-9_-]+)';
        register_rest_route($namespace, '/admin/webhooks/endpoints', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'indexRoute'],
                'permission_callback' => [self::class, 'permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'createRoute'],
                'permission_callback' => [self::class, 'permission'],
            ],
        ]);
        foreach (['secret', 'test', 'activate', 'pause'] as $action) {
            register_rest_route($namespace, "/admin/webhooks/endpoints/{$idPattern}/{$action}", [
                'methods' => 'POST',
                'callback' => [self::class, $action . 'Route'],
                'permission_callback' => [self::class, 'permission'],
            ]);
        }
        register_rest_route($namespace, "/admin/webhooks/endpoints/{$idPattern}", [
            'methods' => 'DELETE',
            'callback' => [self::class, 'deleteRoute'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function permission(\WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public static function indexRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->index($request);
    }

    public static function createRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->create($request);
    }

    public static function secretRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->rotateSecret($request);
    }

    public static function testRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->test($request);
    }

    public static function activateRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->activate($request);
    }

    public static function pauseRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->pause($request);
    }

    public static function deleteRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->delete($request);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $settings = $this->readSettings();
        if ($settings === null) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        return new \WP_REST_Response(['data' => [
            'endpoints' => array_map(
                [WebhookEndpointConfig::class, 'public'],
                WebhookEndpointConfig::all($settings)
            ),
        ]]);
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response
    {
        $name = trim(sanitize_text_field((string) $request->get_param('name')));
        $rawUrl = trim((string) $request->get_param('url'));
        $validation = $this->endpointPolicy->validate($rawUrl);
        $urls = $validation === true ? $this->endpointPolicy->normalise($rawUrl) : [];
        if ($name === '' || count($urls) !== 1) {
            return $this->error('fchub_webhook_endpoint_invalid', 422);
        }
        $url = $urls[0];
        $id = (string) ($this->idFactory)();
        $error = '';
        $result = $this->settingsCoordinator->mutate(function (array $settings) use (
            $id,
            $name,
            $url,
            &$error
        ): array {
            $settings = WebhookEndpointConfig::migrateLegacy($settings);
            if (count(WebhookEndpointConfig::all($settings)) >= WebhookEndpointPolicy::MAX_ENDPOINTS) {
                $error = 'fchub_webhook_endpoint_limit';
                return $settings;
            }
            if (WebhookEndpointConfig::findByUrl($settings, $url) !== null) {
                $error = 'fchub_webhook_endpoint_duplicate';
                return $settings;
            }
            $settings['webhook_endpoints'][] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'secret' => '',
                'status' => 'draft',
                'requires_rotation' => false,
                'last_test_status' => '',
                'last_tested_at' => null,
            ];
            return $settings;
        });

        if ($error !== '') {
            return $this->error($error, $error === 'fchub_webhook_endpoint_limit' ? 422 : 409);
        }
        if (!$result['success']) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        return new \WP_REST_Response(['data' => [
            'endpoint' => WebhookEndpointConfig::public(
                WebhookEndpointConfig::find($result['settings'], $id) ?? []
            ),
        ]], 201);
    }

    public function rotateSecret(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (string) $request->get_param('id');
        $secret = (string) ($this->secretFactory)();
        $error = '';
        $result = $this->mutateEndpoint($id, function (array $endpoint) use ($secret): array {
            $endpoint['secret'] = $secret;
            $endpoint['status'] = 'paused';
            $endpoint['requires_rotation'] = false;
            $endpoint['last_test_status'] = '';
            $endpoint['last_tested_at'] = null;
            return $endpoint;
        }, $error);

        if ($error !== '') {
            return $this->error($error, 404);
        }
        if (!$result['success']) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        return new \WP_REST_Response(['data' => [
            'secret' => $secret,
            'endpoint' => WebhookEndpointConfig::public(
                WebhookEndpointConfig::find($result['settings'], $id) ?? []
            ),
        ]]);
    }

    public function test(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!(bool) ($this->readiness)()) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }
        $id = (string) $request->get_param('id');
        $settings = $this->readSettings();
        $endpoint = $settings === null ? null : WebhookEndpointConfig::find($settings, $id);
        if ($endpoint === null) {
            return $this->error('fchub_webhook_endpoint_not_found', 404);
        }
        if ((string) ($endpoint['secret'] ?? '') === '') {
            return $this->error('fchub_webhook_endpoint_secret_required', 409);
        }

        $envelope = WebhookEnvelope::create('test', [
            'message' => 'This is a one-shot test webhook from FCHub Memberships',
        ]);
        $occurredAt = (new \DateTimeImmutable((string) $envelope['occurred_at']))
            ->setTimezone(new \DateTimeZone('UTC'));
        $storedAt = $this->clock->storage($occurredAt);
        try {
            $this->eventRepository->create([
                'event_id' => (string) $envelope['id'],
                'event_type' => 'test',
                'schema_version' => (string) $envelope['schema_version'],
                'body' => WebhookEnvelope::encode($envelope),
                'occurred_at' => $storedAt,
                'created_at' => $storedAt,
            ]);
            $deliveries = $this->deliveryRepository->createMany(
                (string) $envelope['id'],
                [(string) $endpoint['url']]
            );
            $deliveryId = (int) ($deliveries[0]['id'] ?? 0);
            $outcome = $this->worker->deliverNow($deliveryId, false);
            $delivery = $this->deliveryRepository->find($deliveryId);
        } catch (\Throwable) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }
        $status = (string) ($delivery['status'] ?? $outcome['status'] ?? 'failed');
        $error = '';
        $updated = $this->mutateEndpoint($id, function (array $current) use ($status): array {
            $current['last_test_status'] = $status;
            $current['last_tested_at'] = $this->clock->storage($this->clock->now());
            return $current;
        }, $error);
        if (!$updated['success']) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        return new \WP_REST_Response(['data' => [
            'status' => $status,
            'delivery' => [
                'id' => $deliveryId,
                'response_code' => isset($delivery['response_code']) ? (int) $delivery['response_code'] : null,
            ],
            'endpoint' => WebhookEndpointConfig::public(
                WebhookEndpointConfig::find($updated['settings'], $id) ?? []
            ),
        ]]);
    }

    public function activate(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->changeStatus((string) $request->get_param('id'), 'active');
    }

    public function pause(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->changeStatus((string) $request->get_param('id'), 'paused');
    }

    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (string) $request->get_param('id');
        $error = '';
        $result = $this->mutateEndpoint($id, static function (array $endpoint): array {
            $endpoint['status'] = 'deleted';
            $endpoint['secret'] = '';
            return $endpoint;
        }, $error);
        if ($error !== '') {
            return $this->error($error, 404);
        }
        if (!$result['success']) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        return new \WP_REST_Response(null, 204);
    }

    private function changeStatus(string $id, string $status): \WP_REST_Response
    {
        $error = '';
        $result = $this->mutateEndpoint($id, static function (array $endpoint) use ($status, &$error): array {
            if ($status === 'active'
                && ((string) ($endpoint['secret'] ?? '') === ''
                    || ($endpoint['last_test_status'] ?? '') !== 'succeeded')
            ) {
                $error = 'fchub_webhook_endpoint_test_required';
                return $endpoint;
            }
            $endpoint['status'] = $status;
            return $endpoint;
        }, $error);
        if ($error === 'fchub_webhook_endpoint_not_found') {
            return $this->error($error, 404);
        }
        if ($error !== '') {
            return $this->error($error, 409);
        }
        if (!$result['success']) {
            return $this->error('fchub_webhook_storage_unavailable', 503);
        }

        return new \WP_REST_Response(['data' => [
            'endpoint' => WebhookEndpointConfig::public(
                WebhookEndpointConfig::find($result['settings'], $id) ?? []
            ),
        ]]);
    }

    /** @return array{success:bool, changed:bool, settings:array, reason?:string} */
    private function mutateEndpoint(string $id, callable $mutation, string &$error): array
    {
        return $this->settingsCoordinator->mutate(function (array $settings) use (
            $id,
            $mutation,
            &$error
        ): array {
            $settings = WebhookEndpointConfig::migrateLegacy($settings);
            $found = false;
            foreach ($settings['webhook_endpoints'] as $index => $endpoint) {
                if (!is_array($endpoint) || (string) ($endpoint['id'] ?? '') !== $id) {
                    continue;
                }
                $found = true;
                $settings['webhook_endpoints'][$index] = $mutation($endpoint);
                break;
            }
            if (!$found) {
                $error = 'fchub_webhook_endpoint_not_found';
            }
            return $settings;
        });
    }

    /** @return array<string, mixed>|null */
    private function readSettings(): ?array
    {
        $result = $this->settingsCoordinator->synchronized(
            static fn(MembershipSettingsOptionCoordinator $coordinator): array =>
                WebhookEndpointConfig::migrateLegacy($coordinator->read())
        );

        return $result['success'] && is_array($result['value'] ?? null)
            ? $result['value']
            : null;
    }

    private function error(string $code, int $status): \WP_REST_Response
    {
        $messages = [
            'fchub_webhook_endpoint_invalid' => 'Enter a name and one safe HTTPS endpoint URL.',
            'fchub_webhook_endpoint_duplicate' => 'This webhook endpoint already exists.',
            'fchub_webhook_endpoint_limit' => sprintf(
                'Configure no more than %d webhook endpoints.',
                WebhookEndpointPolicy::MAX_ENDPOINTS
            ),
            'fchub_webhook_endpoint_not_found' => 'Webhook endpoint was not found.',
            'fchub_webhook_endpoint_secret_required' => 'Generate and save the endpoint secret first.',
            'fchub_webhook_endpoint_test_required' => 'Pass a one-shot endpoint test before activation.',
            'fchub_webhook_storage_unavailable' => 'Webhook storage is temporarily unavailable.',
        ];

        return new \WP_REST_Response([
            'code' => $code,
            'message' => __($messages[$code] ?? 'Webhook endpoint operation failed.', 'fchub-memberships'),
        ], $status);
    }

    private static function persistenceReady(): bool
    {
        if (version_compare((string) get_option('fchub_memberships_db_version', '0'), '1.8.0', '<')) {
            return false;
        }

        return Migrations::verifyWebhookSchema() === [];
    }
}
