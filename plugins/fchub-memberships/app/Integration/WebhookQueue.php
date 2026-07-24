<?php

declare(strict_types=1);

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

final class WebhookQueue
{
    public const HOOK = 'fchub_memberships_deliver_webhook';
    public const GROUP = 'fchub-memberships-webhooks';

    public function __construct(private ?bool $actionSchedulerAvailable = null)
    {
    }

    public function schedule(int $deliveryId, int $attempt, int $timestamp): bool
    {
        if ($deliveryId <= 0 || $attempt <= 0 || $timestamp <= 0) {
            throw new \InvalidArgumentException('Invalid webhook schedule identity.');
        }

        $args = [$deliveryId];
        if ($this->usesActionScheduler()) {
            $group = sprintf('%s-%d-a%d', self::GROUP, $deliveryId, $attempt);
            if (as_has_scheduled_action(self::HOOK, $args, $group)) {
                return true;
            }

            $actionId = as_schedule_single_action(
                $timestamp,
                self::HOOK,
                $args,
                $group,
                true
            );

            return $actionId > 0 || as_has_scheduled_action(self::HOOK, $args, $group);
        }

        if (wp_next_scheduled(self::HOOK, $args) !== false) {
            return true;
        }

        $scheduled = wp_schedule_single_event($timestamp, self::HOOK, $args, true);
        if ($scheduled === true) {
            return true;
        }

        return wp_next_scheduled(self::HOOK, $args) !== false;
    }

    public function cancel(int $deliveryId): bool
    {
        if ($deliveryId <= 0) {
            throw new \InvalidArgumentException('Invalid webhook delivery identity.');
        }

        $args = [$deliveryId];
        if ($this->usesActionScheduler()) {
            as_unschedule_all_actions(self::HOOK, $args);
            return true;
        }

        wp_clear_scheduled_hook(self::HOOK, $args);
        return true;
    }

    private function usesActionScheduler(): bool
    {
        return $this->actionSchedulerAvailable
            ?? (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action'));
    }
}
