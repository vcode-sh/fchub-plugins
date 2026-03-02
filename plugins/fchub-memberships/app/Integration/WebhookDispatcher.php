<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Logger;

class WebhookDispatcher
{
    private array $settings;

    public function __construct()
    {
        $this->settings = get_option('fchub_memberships_settings', []);
    }

    /**
     * Register hooks for all membership events.
     */
    public function register(): void
    {
        if (($this->settings['webhook_enabled'] ?? 'no') !== 'yes') {
            return;
        }

        $urls = $this->getWebhookUrls();
        if (empty($urls)) {
            return;
        }

        add_action('fchub_memberships/grant_created', [$this, 'onGrantCreated'], 20, 3);
        add_action('fchub_memberships/grant_revoked', [$this, 'onGrantRevoked'], 20, 4);
        add_action('fchub_memberships/grant_expired', [$this, 'onGrantExpired'], 20, 1);
        add_action('fchub_memberships/grant_paused', [$this, 'onGrantPaused'], 20, 2);
        add_action('fchub_memberships/grant_resumed', [$this, 'onGrantResumed'], 20, 1);
    }

    public function onGrantCreated(int $userId, int $planId, array $context): void
    {
        $user = get_userdata($userId);
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plan = $planRepo->find($planId);

        $this->dispatch('grant_created', [
            'user'    => $this->formatUser($user),
            'plan'    => $this->formatPlan($plan),
            'context' => [
                'source_type' => $context['source_type'] ?? 'manual',
                'source_id'   => $context['source_id'] ?? 0,
            ],
        ]);
    }

    public function onGrantRevoked(array $grants, int $planId, int $userId, string $reason): void
    {
        $user = get_userdata($userId);
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plan = $planRepo->find($planId);

        $this->dispatch('grant_revoked', [
            'user'   => $this->formatUser($user),
            'plan'   => $this->formatPlan($plan),
            'reason' => $reason,
            'grants_affected' => count($grants),
        ]);
    }

    public function onGrantExpired(array $grant): void
    {
        $user = get_userdata($grant['user_id']);
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plan = $grant['plan_id'] ? $planRepo->find($grant['plan_id']) : null;

        $this->dispatch('grant_expired', [
            'user'  => $this->formatUser($user),
            'plan'  => $this->formatPlan($plan),
            'grant' => $this->formatGrant($grant),
        ]);
    }

    public function onGrantPaused(array $grant, string $reason): void
    {
        $user = get_userdata($grant['user_id']);
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plan = $grant['plan_id'] ? $planRepo->find($grant['plan_id']) : null;

        $this->dispatch('grant_paused', [
            'user'   => $this->formatUser($user),
            'plan'   => $this->formatPlan($plan),
            'grant'  => $this->formatGrant($grant),
            'reason' => $reason,
        ]);
    }

    public function onGrantResumed(array $grant): void
    {
        $user = get_userdata($grant['user_id']);
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plan = $grant['plan_id'] ? $planRepo->find($grant['plan_id']) : null;

        $this->dispatch('grant_resumed', [
            'user'  => $this->formatUser($user),
            'plan'  => $this->formatPlan($plan),
            'grant' => $this->formatGrant($grant),
        ]);
    }

    /**
     * Dispatch a webhook event to all configured URLs.
     */
    public function dispatch(string $eventType, array $payload): void
    {
        $urls = $this->getWebhookUrls();
        if (empty($urls)) {
            return;
        }

        $secret = $this->settings['webhook_secret'] ?? '';

        $body = wp_json_encode([
            'event_type' => $eventType,
            'timestamp'  => current_time('c'),
            'site_url'   => get_site_url(),
            'data'       => $payload,
        ]);

        $signature = $secret ? hash_hmac('sha256', $body, $secret) : '';

        foreach ($urls as $url) {
            $this->sendToUrl($url, $body, $signature, $eventType);
        }
    }

    /**
     * Send a test webhook to configured URLs.
     */
    public function sendTest(): array
    {
        $urls = $this->getWebhookUrls();
        if (empty($urls)) {
            return ['success' => false, 'message' => 'No webhook URLs configured'];
        }

        $secret = $this->settings['webhook_secret'] ?? '';

        $body = wp_json_encode([
            'event_type' => 'test',
            'timestamp'  => current_time('c'),
            'site_url'   => get_site_url(),
            'data'       => [
                'message' => 'This is a test webhook from FCHub Memberships',
            ],
        ]);

        $signature = $secret ? hash_hmac('sha256', $body, $secret) : '';
        $results = [];

        foreach ($urls as $url) {
            $response = wp_remote_post($url, [
                'timeout' => 15,
                'headers' => [
                    'Content-Type'     => 'application/json',
                    'X-FCHub-Signature' => $signature,
                    'X-FCHub-Event'    => 'test',
                ],
                'body' => $body,
            ]);

            if (is_wp_error($response)) {
                $results[] = ['url' => $url, 'success' => false, 'error' => $response->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $results[] = ['url' => $url, 'success' => $code >= 200 && $code < 300, 'status_code' => $code];
            }
        }

        return ['success' => true, 'results' => $results];
    }

    private function sendToUrl(string $url, string $body, string $signature, string $eventType): void
    {
        $headers = [
            'Content-Type'     => 'application/json',
            'X-FCHub-Signature' => $signature,
            'X-FCHub-Event'    => $eventType,
        ];

        // Use Action Scheduler if available for async dispatch
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'fchub_memberships_dispatch_webhook', [
                'url'     => $url,
                'body'    => $body,
                'headers' => $headers,
            ]);
            return;
        }

        // Fallback to synchronous dispatch
        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => $headers,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            Logger::error('Webhook dispatch failed', sprintf('%s: %s', $url, $response->get_error_message()));
        }
    }

    private function getWebhookUrls(): array
    {
        $raw = $this->settings['webhook_urls'] ?? '';
        if (empty($raw)) {
            return [];
        }

        $urls = array_filter(array_map('trim', explode("\n", $raw)));
        return array_filter($urls, function ($url) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        });
    }

    private function formatUser($user): array
    {
        if (!$user) {
            return ['id' => 0, 'email' => '', 'display_name' => ''];
        }

        return [
            'id'           => $user->ID,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
        ];
    }

    private function formatPlan(?array $plan): ?array
    {
        if (!$plan) {
            return null;
        }

        return [
            'id'    => $plan['id'],
            'title' => $plan['title'],
            'slug'  => $plan['slug'] ?? '',
        ];
    }

    private function formatGrant(array $grant): array
    {
        return [
            'id'          => $grant['id'],
            'status'      => $grant['status'],
            'source_type' => $grant['source_type'],
            'created_at'  => $grant['created_at'],
            'expires_at'  => $grant['expires_at'],
        ];
    }
}
