<?php

namespace FChubMemberships\Modules\Infrastructure;

use FChubMemberships\Core\Container;
use FChubMemberships\Core\Contracts\ModuleInterface;
use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

final class InfrastructureModule implements ModuleInterface
{
    public function key(): string
    {
        return 'infrastructure';
    }

    public function register(Container $container): void
    {
        add_filter('cron_schedules', [$this, 'registerCronSchedules']);
        add_action('fchub_memberships_validity_check', [$this, 'runValidityCheck']);
        add_action('fchub_memberships_drip_process', [$this, 'runDripProcess']);
        add_action('fchub_memberships_expiry_notify', [$this, 'runExpiryNotifications']);
        add_action('fchub_memberships_daily_stats', [$this, 'runDailyStats']);
        add_action('fchub_memberships_audit_cleanup', [$this, 'runAuditCleanup']);
        add_action('fchub_memberships_trial_check', [$this, 'runTrialCheck']);
        add_action('fchub_memberships_plan_schedule', [$this, 'runPlanSchedule']);
        add_action('fchub_memberships_send_email', [$this, 'sendEmail'], 10, 4);
        add_action('fchub_memberships_dispatch_webhook', [$this, 'dispatchWebhook'], 10, 3);
        add_action('admin_notices', [$this, 'renderFluentCartNotice']);
    }

    /**
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    public function registerCronSchedules(array $schedules): array
    {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 300,
                'display'  => __('Every 5 Minutes', 'fchub-memberships'),
            ];
        }

        return $schedules;
    }

    public static function scheduleRecurringEvents(): void
    {
        $events = [
            'fchub_memberships_validity_check' => 'five_minutes',
            'fchub_memberships_drip_process' => 'hourly',
            'fchub_memberships_expiry_notify' => 'daily',
            'fchub_memberships_daily_stats' => 'daily',
            'fchub_memberships_audit_cleanup' => 'weekly',
            'fchub_memberships_trial_check' => 'daily',
            'fchub_memberships_plan_schedule' => 'hourly',
        ];

        foreach ($events as $hook => $recurrence) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $recurrence, $hook);
            }
        }
    }

    public static function clearRecurringEvents(): void
    {
        $hooks = [
            'fchub_memberships_validity_check',
            'fchub_memberships_drip_process',
            'fchub_memberships_expiry_notify',
            'fchub_memberships_daily_stats',
            'fchub_memberships_audit_cleanup',
            'fchub_memberships_trial_check',
            'fchub_memberships_plan_schedule',
        ];

        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    public function runValidityCheck(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        (new \FChubMemberships\Domain\SubscriptionValidityWatcher())->check();
    }

    public function runDripProcess(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        (new \FChubMemberships\Domain\Drip\DripScheduleService())->processNotifications();
    }

    public function runExpiryNotifications(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        (new \FChubMemberships\Email\AccessExpiringEmail())->sendPendingNotifications();
    }

    public function runDailyStats(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        (new \FChubMemberships\Reports\MemberStatsReport())->aggregateDaily();
        \FChubMemberships\FluentCRM\Triggers\MembershipAnniversaryTrigger::checkAnniversaries();
    }

    public function runAuditCleanup(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        (new \FChubMemberships\Storage\AuditLogRepository())->cleanup(90);
    }

    public function runTrialCheck(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        $service = new \FChubMemberships\Domain\TrialLifecycleService();
        $service->sendTrialExpiringNotifications();
        $service->checkTrialExpirations();
    }

    public function runPlanSchedule(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        (new \FChubMemberships\Domain\Plan\PlanService())->processScheduledStatuses();
    }

    /**
     * @param array<string, string> $headers
     */
    public function sendEmail(string $to, string $subject, string $body, array $headers): void
    {
        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function dispatchWebhook(string $url, string $body, array $headers): void
    {
        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => $headers,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            Logger::error(
                'Webhook dispatch failed',
                sprintf('%s: %s', $url, $response->get_error_message())
            );
        }
    }

    public function renderFluentCartNotice(): void
    {
        if (defined('FLUENTCART_VERSION')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('FCHub - Memberships requires FluentCart to be installed and activated.', 'fchub-memberships');
        echo '</p></div>';
    }
}
