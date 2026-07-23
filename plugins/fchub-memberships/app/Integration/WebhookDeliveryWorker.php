<?php

declare(strict_types=1);

namespace FChubMemberships\Integration;

use FChubMemberships\Storage\WebhookDeliveryRepository;
use FChubMemberships\Storage\WebhookEventRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

final class WebhookDeliveryWorker
{
    private object $deliveryRepository;
    private object $eventRepository;
    private object $queue;
    private WebhookRetryPolicy $retryPolicy;
    private Clock $clock;
    private \Closure $settingsResolver;
    private \Closure $httpClient;
    private \Closure $ownerFactory;
    private \Closure $logger;
    private \Closure $nowProvider;

    public function __construct(
        ?object $deliveryRepository = null,
        ?object $eventRepository = null,
        ?object $queue = null,
        ?WebhookRetryPolicy $retryPolicy = null,
        ?Clock $clock = null,
        ?callable $settingsResolver = null,
        ?callable $httpClient = null,
        ?callable $ownerFactory = null,
        ?callable $logger = null,
        ?callable $nowProvider = null
    ) {
        $this->deliveryRepository = $deliveryRepository ?? new WebhookDeliveryRepository();
        $this->eventRepository = $eventRepository ?? new WebhookEventRepository();
        $this->queue = $queue ?? new WebhookQueue();
        $this->retryPolicy = $retryPolicy ?? new WebhookRetryPolicy();
        $this->clock = $clock ?? new Clock(null, new \DateTimeZone('UTC'));
        $this->settingsResolver = \Closure::fromCallable(
            $settingsResolver ?? static function (): array {
                $result = (new MembershipSettingsOptionCoordinator())->synchronized(
                    static fn(MembershipSettingsOptionCoordinator $coordinator): array => $coordinator->read()
                );

                return $result['success'] && is_array($result['value'] ?? null)
                    ? $result['value']
                    : [];
            }
        );
        $this->httpClient = \Closure::fromCallable(
            $httpClient ?? static fn(string $url, array $arguments): array|\WP_Error => wp_safe_remote_post($url, $arguments)
        );
        $this->ownerFactory = \Closure::fromCallable(
            $ownerFactory ?? static fn(): string => bin2hex(random_bytes(16))
        );
        $this->logger = \Closure::fromCallable(
            $logger ?? static fn(string $title, string $description, array $context = []): mixed => Logger::error(
                $title,
                $description,
                $context
            )
        );
        $this->nowProvider = \Closure::fromCallable(
            $nowProvider ?? fn(): \DateTimeImmutable => $this->clock->now()
        );
    }

    public function handle(int $deliveryId): void
    {
        try {
            $this->deliverNow($deliveryId);
        } catch (\Throwable) {
            $this->log('Webhook delivery failed', 'The durable delivery will be recovered by reconciliation.');
        }
    }

    /** @return array<string, mixed> */
    public function deliverNow(int $deliveryId): array
    {
        $delivery = $this->deliveryRepository->find($deliveryId);
        if (!is_array($delivery)) {
            return ['status' => 'missing'];
        }

        $now = $this->now();
        $attemptedAt = $this->clock->storage($now);
        $owner = (string) ($this->ownerFactory)();
        $claimed = $this->deliveryRepository->acquire(
            $deliveryId,
            $owner,
            $attemptedAt,
            $this->clock->storage($now->modify('+5 minutes'))
        );
        if (!is_array($claimed)) {
            return ['status' => 'unavailable'];
        }

        $attempt = (int) ($claimed['attempt_count'] ?? 0);
        $event = $this->eventRepository->findByEventId((string) ($claimed['event_id'] ?? ''));
        if (!is_array($event)) {
            $completedAt = $this->now();
            return $this->complete(
                $claimed,
                $owner,
                $attempt,
                [
                    'outcome' => 'failed',
                    'code' => null,
                    'body' => '',
                    'error' => 'webhook_event_missing',
                    'next_timestamp' => null,
                ],
                $this->clock->storage($completedAt)
            );
        }

        $settings = ($this->settingsResolver)();
        $settings = is_array($settings) ? $settings : [];
        $signature = WebhookSecret::sign((string) ($event['body'] ?? ''), $settings);
        if ($signature === '') {
            $completedAt = $this->now();
            return $this->complete(
                $claimed,
                $owner,
                $attempt,
                $this->retryPolicy->classify(
                    $attempt,
                    new \WP_Error('webhook_secret_missing', 'webhook_secret_missing'),
                    $completedAt->getTimestamp()
                ),
                $this->clock->storage($completedAt)
            );
        }

        $body = (string) ($event['body'] ?? '');
        $timestamp = $this->eventTimestamp((string) ($event['occurred_at'] ?? ''));
        $headers = [
            'Content-Type' => 'application/json',
            'X-FCHub-Event' => (string) ($event['event_type'] ?? ''),
            'X-FCHub-Delivery' => (string) ($event['event_id'] ?? ''),
            'X-FCHub-Timestamp' => $timestamp,
            'X-FCHub-Signature' => $signature,
        ];
        $response = ($this->httpClient)((string) ($claimed['destination_url'] ?? ''), [
            'timeout' => 15,
            'redirection' => 3,
            'headers' => $headers,
            'body' => $body,
            'data_format' => 'body',
        ]);

        $completedAt = $this->now();
        $classification = $this->retryPolicy->classify($attempt, $response, $completedAt->getTimestamp());
        $classification = $this->redactClassification($classification, [
            (string) ($settings['webhook_secret'] ?? ''),
            $signature,
            (string) ($claimed['destination_url'] ?? ''),
            $body,
        ]);

        return $this->complete(
            $claimed,
            $owner,
            $attempt,
            $classification,
            $this->clock->storage($completedAt)
        );
    }

    /**
     * @param array<string, mixed> $delivery
     * @param array{outcome:string, code:?int, body:string, error:string, next_timestamp:?int} $classification
     * @return array<string, mixed>
     */
    private function complete(
        array $delivery,
        string $owner,
        int $attempt,
        array $classification,
        string $attemptedAt
    ): array {
        $id = (int) ($delivery['id'] ?? 0);
        if ($classification['outcome'] === 'succeeded') {
            $updated = $this->deliveryRepository->markSucceeded(
                $id,
                $owner,
                $attempt,
                (int) $classification['code'],
                $classification['body'],
                $attemptedAt
            );

            return $updated ? ['status' => 'succeeded'] : ['status' => 'lost'];
        }

        if ($classification['outcome'] === 'failed') {
            $updated = $this->deliveryRepository->markFailed(
                $id,
                $owner,
                $attempt,
                $classification['code'],
                $classification['body'],
                $classification['error'],
                $attemptedAt
            );

            return $updated ? ['status' => 'failed'] : ['status' => 'lost'];
        }

        $nextTimestamp = (int) $classification['next_timestamp'];
        $updated = $this->deliveryRepository->markRetrying(
            $id,
            $owner,
            $attempt,
            $classification['code'],
            $classification['body'],
            $classification['error'],
            $this->clock->storage((new \DateTimeImmutable('@' . $nextTimestamp))->setTimezone(new \DateTimeZone('UTC')))
        );
        if (!$updated) {
            return ['status' => 'lost'];
        }

        $scheduled = false;
        $scheduleFailureLogged = false;
        try {
            $scheduled = $this->queue->schedule($id, $attempt + 1, $nextTimestamp);
        } catch (\Throwable) {
            $scheduleFailureLogged = true;
            $this->log(
                'Webhook retry scheduling failed',
                'The retry state remains durable and reconciliation will repair the schedule.'
            );
        }
        if (!$scheduled && !$scheduleFailureLogged) {
            $this->log(
                'Webhook retry scheduling failed',
                'The retry state remains durable and reconciliation will repair the schedule.'
            );
        }

        return ['status' => 'retrying', 'scheduled' => $scheduled];
    }

    private function eventTimestamp(string $stored): string
    {
        try {
            return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        } catch (\Throwable) {
            return $stored;
        }
    }

    /**
     * @param array{outcome:string, code:?int, body:string, error:string, next_timestamp:?int} $classification
     * @param list<string> $sensitiveValues
     * @return array{outcome:string, code:?int, body:string, error:string, next_timestamp:?int}
     */
    private function redactClassification(array $classification, array $sensitiveValues): array
    {
        $sensitiveValues = array_values(array_unique(array_filter(
            $sensitiveValues,
            static fn(string $value): bool => $value !== ''
        )));
        foreach (['body', 'error'] as $field) {
            $classification[$field] = str_replace($sensitiveValues, '[redacted]', $classification[$field]);
        }
        $classification['body'] = $this->boundedUtf8($classification['body'], 2048);
        $classification['error'] = $this->boundedUtf8($classification['error'], 1024);

        return $classification;
    }

    private function boundedUtf8(string $value, int $bytes): string
    {
        $value = substr($value, 0, $bytes);
        if (preg_match('//u', $value) !== 1) {
            $converted = function_exists('iconv') ? iconv('UTF-8', 'UTF-8//IGNORE', $value) : false;
            $value = is_string($converted) ? $converted : preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value);
            $value = is_string($value) ? $value : '';
        }
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }

    private function now(): \DateTimeImmutable
    {
        $now = ($this->nowProvider)();
        if (!$now instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException('Webhook clock must return an immutable date.');
        }

        return $now->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $context */
    private function log(string $title, string $description, array $context = []): void
    {
        ($this->logger)($title, $description, $context);
    }
}
