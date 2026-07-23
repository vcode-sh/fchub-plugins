<?php

declare(strict_types=1);

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\WebhookDeliveryRepository;
use FChubMemberships\Storage\WebhookEventRepository;

final class WebhookDispatcher
{
    private WebhookEventRepository $eventRepository;
    private WebhookDeliveryRepository $deliveryRepository;
    private WebhookQueue $queue;
    private WebhookEndpointPolicy $endpointPolicy;
    private MembershipSettingsOptionCoordinator $settingsCoordinator;

    public function __construct(
        ?WebhookEventRepository $eventRepository = null,
        ?WebhookDeliveryRepository $deliveryRepository = null,
        ?WebhookQueue $queue = null,
        ?WebhookEndpointPolicy $endpointPolicy = null,
        ?MembershipSettingsOptionCoordinator $settingsCoordinator = null
    ) {
        $this->eventRepository = $eventRepository ?? new WebhookEventRepository();
        $this->deliveryRepository = $deliveryRepository ?? new WebhookDeliveryRepository();
        $this->queue = $queue ?? new WebhookQueue();
        $this->endpointPolicy = $endpointPolicy ?? new WebhookEndpointPolicy();
        $this->settingsCoordinator = $settingsCoordinator ?? new MembershipSettingsOptionCoordinator();
    }

    public function register(): void
    {
        add_action('fchub_memberships/grant_created', [$this, 'onGrantCreated'], 20, 3);
        add_action('fchub_memberships/grant_revoked', [$this, 'onGrantRevoked'], 20, 4);
        add_action('fchub_memberships/grant_expired', [$this, 'onGrantExpired'], 20, 1);
        add_action('fchub_memberships/grant_paused', [$this, 'onGrantPaused'], 20, 2);
        add_action('fchub_memberships/grant_resumed', [$this, 'onGrantResumed'], 20, 1);
    }

    /** @param array<string, mixed> $context */
    public function onGrantCreated(int $userId, int $planId, array $context): void
    {
        $this->dispatch('grant_created', [
            'user' => $this->formatUser(get_userdata($userId)),
            'plan' => $this->formatPlan((new PlanRepository())->find($planId)),
            'context' => [
                'source_type' => $context['source_type'] ?? 'manual',
                'source_id' => $context['source_id'] ?? 0,
            ],
        ]);
    }

    /** @param list<array<string, mixed>> $grants */
    public function onGrantRevoked(array $grants, int $planId, int $userId, string $reason): void
    {
        $this->dispatch('grant_revoked', [
            'user' => $this->formatUser(get_userdata($userId)),
            'plan' => $this->formatPlan((new PlanRepository())->find($planId)),
            'reason' => $reason,
            'grants_affected' => count($grants),
        ]);
    }

    /** @param array<string, mixed> $grant */
    public function onGrantExpired(array $grant): void
    {
        $this->dispatch('grant_expired', [
            'user' => $this->formatUser(get_userdata((int) ($grant['user_id'] ?? 0))),
            'plan' => $this->findGrantPlan($grant),
            'grant' => $this->formatGrant($grant),
        ]);
    }

    /** @param array<string, mixed> $grant */
    public function onGrantPaused(array $grant, string $reason): void
    {
        $this->dispatch('grant_paused', [
            'user' => $this->formatUser(get_userdata((int) ($grant['user_id'] ?? 0))),
            'plan' => $this->findGrantPlan($grant),
            'grant' => $this->formatGrant($grant),
            'reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $grant */
    public function onGrantResumed(array $grant): void
    {
        $this->dispatch('grant_resumed', [
            'user' => $this->formatUser(get_userdata((int) ($grant['user_id'] ?? 0))),
            'plan' => $this->findGrantPlan($grant),
            'grant' => $this->formatGrant($grant),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(string $eventType, array $payload): void
    {
        $persisted = $this->settingsCoordinator->synchronized(function (
            MembershipSettingsOptionCoordinator $coordinator
        ) use ($eventType, $payload): ?array {
            $urls = $this->readyDestinations($coordinator->read());
            if ($urls === []) {
                return null;
            }

            $envelope = WebhookEnvelope::create($eventType, $payload);
            $body = WebhookEnvelope::encode($envelope);
            $occurredAt = new \DateTimeImmutable((string) $envelope['occurred_at']);
            $occurredAt = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
            $storedAt = $occurredAt->format('Y-m-d H:i:s');

            $this->eventRepository->create([
                'event_id' => $envelope['id'],
                'event_type' => $envelope['event_type'],
                'schema_version' => $envelope['schema_version'],
                'body' => $body,
                'occurred_at' => $storedAt,
                'created_at' => $storedAt,
            ]);

            return [
                'deliveries' => $this->deliveryRepository->createMany((string) $envelope['id'], $urls),
                'timestamp' => $occurredAt->getTimestamp(),
            ];
        });

        if (!$persisted['success'] || !is_array($persisted['value'] ?? null)) {
            return;
        }

        foreach ($persisted['value']['deliveries'] as $delivery) {
            try {
                $this->queue->schedule(
                    (int) ($delivery['id'] ?? 0),
                    1,
                    (int) $persisted['value']['timestamp']
                );
            } catch (\Throwable) {
                // The pending row remains recoverable by reconciliation.
            }
        }
    }

    /** @return array<string, mixed> */
    public function sendTest(): array
    {
        $loaded = $this->settingsCoordinator->synchronized(
            static fn(MembershipSettingsOptionCoordinator $coordinator): array => $coordinator->read()
        );
        $settings = $loaded['success'] && is_array($loaded['value'] ?? null)
            ? $loaded['value']
            : [];
        $urls = $this->readyDestinations($settings, false);
        if ($urls === []) {
            return ['success' => false, 'message' => 'No webhook URLs configured'];
        }

        $body = wp_json_encode([
            'event_type' => 'test',
            'timestamp' => current_time('c'),
            'site_url' => get_site_url(),
            'data' => ['message' => 'This is a test webhook from FCHub Memberships'],
        ]);
        if (!is_string($body)) {
            return ['success' => false, 'message' => 'Unable to encode test webhook'];
        }

        $signature = hash_hmac('sha256', $body, (string) $settings['webhook_secret']);
        $results = [];
        foreach ($urls as $url) {
            $response = wp_remote_post($url, [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-FCHub-Signature' => $signature,
                    'X-FCHub-Event' => 'test',
                ],
                'body' => $body,
            ]);
            if (is_wp_error($response)) {
                $results[] = [
                    'url' => $url,
                    'success' => false,
                    'error' => $response->get_error_message(),
                ];
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $results[] = [
                'url' => $url,
                'success' => $code >= 200 && $code < 300,
                'status_code' => $code,
            ];
        }

        return ['success' => true, 'results' => $results];
    }

    /** @param array<string, mixed> $settings @return list<string> */
    private function readyDestinations(array $settings, bool $requireEnabled = true): array
    {
        if (($requireEnabled && ($settings['webhook_enabled'] ?? 'no') !== 'yes')
            || empty($settings['webhook_secret'])
        ) {
            return [];
        }

        $raw = (string) ($settings['webhook_urls'] ?? '');
        if ($raw === '' || $this->endpointPolicy->validate($raw) !== true) {
            return [];
        }

        return $this->endpointPolicy->normalise($raw);
    }

    /** @param array<string, mixed> $grant */
    private function findGrantPlan(array $grant): ?array
    {
        $planId = (int) ($grant['plan_id'] ?? 0);
        return $planId > 0
            ? $this->formatPlan((new PlanRepository())->find($planId))
            : null;
    }

    private function formatUser(mixed $user): array
    {
        if (!$user) {
            return ['id' => 0, 'email' => '', 'display_name' => ''];
        }

        return [
            'id' => (int) $user->ID,
            'email' => (string) $user->user_email,
            'display_name' => (string) $user->display_name,
        ];
    }

    /** @param array<string, mixed>|null $plan */
    private function formatPlan(?array $plan): ?array
    {
        if ($plan === null) {
            return null;
        }

        return [
            'id' => (int) $plan['id'],
            'title' => (string) $plan['title'],
            'slug' => (string) ($plan['slug'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $grant */
    private function formatGrant(array $grant): array
    {
        $status = (string) ($grant['status'] ?? '');
        if ($status === '' && ($grant['lifecycle'] ?? '') === 'ended') {
            $status = 'expired';
        }

        return [
            'id' => (int) ($grant['id'] ?? 0),
            'status' => $status,
            'source_type' => (string) ($grant['source_type'] ?? ''),
            'created_at' => $grant['created_at'] ?? null,
            'expires_at' => $grant['expires_at'] ?? null,
        ];
    }
}
