<?php

namespace FChubMemberships\Modules\Infrastructure;

use FChubMemberships\Core\Container;
use FChubMemberships\Core\Contracts\ModuleInterface;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Domain\Lifecycle\MembershipLifecycleCoordinator;
use FChubMemberships\Http\Controllers\SettingsController;
use FChubMemberships\Integration\FluentCrmSync;
use FChubMemberships\Integration\WebhookDeliveryWorker;
use FChubMemberships\Integration\WebhookQueue;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\MutationRequestRepository;
use FChubMemberships\Storage\WebhookDeliveryRepository;
use FChubMemberships\Storage\WebhookEventRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Support\Logger;
use FChubMemberships\Support\Migrations;

defined('ABSPATH') || exit;

final class InfrastructureModule implements ModuleInterface
{
    private DripScheduleRepository $dripScheduleRepository;
    private ProviderOperationWorker $providerOperationWorker;
    private ?MembershipLifecycleCoordinator $membershipLifecycleCoordinator;
    private FluentCrmSync $fluentCrmSync;
    private MutationRequestRepository $mutationRequestRepository;
    private object $webhookDeliveryWorker;
    private object $webhookDeliveryRepository;
    private object $webhookEventRepository;
    private object $webhookQueue;
    private Clock $clock;
    private \Closure $webhookReadiness;

    public function __construct(
        ?DripScheduleRepository $dripScheduleRepository = null,
        ?ProviderOperationWorker $providerOperationWorker = null,
        ?MembershipLifecycleCoordinator $membershipLifecycleCoordinator = null,
        ?FluentCrmSync $fluentCrmSync = null,
        ?MutationRequestRepository $mutationRequestRepository = null,
        ?object $webhookDeliveryWorker = null,
        ?object $webhookDeliveryRepository = null,
        ?object $webhookEventRepository = null,
        ?object $webhookQueue = null,
        ?Clock $clock = null,
        ?callable $webhookReadiness = null
    ) {
        $this->dripScheduleRepository = $dripScheduleRepository ?? new DripScheduleRepository();
        $this->providerOperationWorker = $providerOperationWorker ?? new ProviderOperationWorker();
        $this->membershipLifecycleCoordinator = $membershipLifecycleCoordinator;
        $this->fluentCrmSync = $fluentCrmSync ?? new FluentCrmSync();
        $this->mutationRequestRepository = $mutationRequestRepository ?? new MutationRequestRepository();
        $this->clock = $clock ?? new Clock(null, new \DateTimeZone('UTC'));
        $this->webhookDeliveryRepository = $webhookDeliveryRepository ?? new WebhookDeliveryRepository($this->clock);
        $this->webhookEventRepository = $webhookEventRepository ?? new WebhookEventRepository();
        $this->webhookQueue = $webhookQueue ?? new WebhookQueue();
        $this->webhookDeliveryWorker = $webhookDeliveryWorker ?? new WebhookDeliveryWorker(
            $this->webhookDeliveryRepository,
            $this->webhookEventRepository,
            $this->webhookQueue,
            null,
            $this->clock
        );
        $this->webhookReadiness = \Closure::fromCallable(
            $webhookReadiness ?? [self::class, 'webhookPersistenceReady']
        );
    }

    public function key(): string
    {
        return 'infrastructure';
    }

    public function register(Container $container): void
    {
        add_filter('cron_schedules', [$this, 'registerCronSchedules']);
        add_action('init', [$this, 'repairWebhookSchedules'], 4);
        add_action('fchub_memberships_validity_check', [$this, 'recoverProviderOperations'], 5);
        add_action('fchub_memberships_validity_check', [$this, 'recoverCrmProjectionJobs'], 6);
        add_action('fchub_memberships_validity_check', [$this, 'purgeMutationRequests'], 7);
        add_action('fchub_memberships_validity_check', [$this, 'runValidityCheck']);
        add_action('fchub_memberships_drip_process', [$this, 'runDripProcess']);
        add_action('fchub_memberships_expiry_notify', [$this, 'runExpiryNotifications']);
        add_action('fchub_memberships_daily_stats', [$this, 'runDailyStats']);
        add_action('fchub_memberships_audit_cleanup', [$this, 'runAuditCleanup']);
        add_action('fchub_memberships_trial_check', [$this, 'runTrialCheck']);
        add_action('fchub_memberships_plan_schedule', [$this, 'runPlanSchedule']);
        add_action('fchub_memberships_send_email', [$this, 'sendEmail'], 10, 4);
        add_action(WebhookQueue::HOOK, [$this->webhookDeliveryWorker, 'handle'], 10, 1);
        add_action('fchub_memberships_webhook_reconcile', [$this, 'reconcileWebhookDeliveries']);
        add_action('fchub_memberships_webhook_cleanup', [$this, 'cleanupWebhookDeliveries']);
        add_action('fchub_memberships/grant_resumed', [$this, 'releaseDeferredDrips']);
        add_action(ProviderOperationWorker::HOOK, [$this, 'processProviderOperation'], 10, 1);
        add_action('admin_notices', [$this, 'renderFluentCartNotice']);
    }

    public function processProviderOperation(int $operationId): void
    {
        $this->providerOperationWorker->process($operationId);
    }

    public function recoverProviderOperations(): void
    {
        $this->providerOperationWorker->recoverDue(50);
    }

    public function recoverCrmProjectionJobs(): void
    {
        $settings = SettingsController::getSettings();
        if (($settings['fluentcrm_enabled'] ?? 'no') !== 'yes') {
            return;
        }

        $this->fluentCrmSync->recoverDue(50);
    }

    public function purgeMutationRequests(): void
    {
        try {
            $this->mutationRequestRepository->purgeTerminalOlderThan(30, 100);
        } catch (\Throwable) {
            Logger::error(
                'Mutation receipt retention failed',
                'Terminal receipt cleanup will retry during the next validity recovery pass.'
            );
        }
    }

    public function releaseDeferredDrips(array $grant): void
    {
        $this->dripScheduleRepository->releaseDeferredForGrant((int) $grant['id']);
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

    public static function scheduleRecurringEvents(?callable $webhookReadiness = null): void
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

        self::scheduleWebhookRecurringEvents($webhookReadiness);
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
            'fchub_memberships_webhook_reconcile',
            'fchub_memberships_webhook_cleanup',
        ];

        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    public function repairWebhookSchedules(): void
    {
        if (wp_next_scheduled('fchub_memberships_webhook_reconcile')
            && wp_next_scheduled('fchub_memberships_webhook_cleanup')
        ) {
            return;
        }

        try {
            self::scheduleWebhookRecurringEvents($this->webhookReadiness);
        } catch (\Throwable) {
            Logger::error(
                'Webhook schedule repair failed',
                'Durable webhook schedules will be checked again during the next request.'
            );
        }
    }

    public function reconcileWebhookDeliveries(): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        try {
            $deliveries = $this->webhookDeliveryRepository->retryableDue($this->clock->storage($now), 100);
        } catch (\Throwable) {
            Logger::error(
                'Webhook reconciliation failed',
                'Due durable webhook deliveries will be checked again during the next recovery run.'
            );
            return;
        }

        foreach ($deliveries as $delivery) {
            $attempt = (int) ($delivery['attempt_count'] ?? 0);
            if (($delivery['status'] ?? '') !== 'processing') {
                $attempt++;
            }
            try {
                $this->webhookQueue->schedule(
                    (int) ($delivery['id'] ?? 0),
                    max(1, $attempt),
                    $now->getTimestamp()
                );
            } catch (\Throwable) {
                Logger::error(
                    'Webhook reconciliation scheduling failed',
                    'The durable webhook delivery remains eligible for a later recovery run.'
                );
            }
        }
    }

    public function cleanupWebhookDeliveries(): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $successCutoff = $this->clock->storage($now->modify('-30 days'));
        $failureCutoff = $this->clock->storage($now->modify('-90 days'));

        try {
            $this->webhookDeliveryRepository->purge($successCutoff, $failureCutoff);
        } catch (\Throwable) {
            Logger::error(
                'Webhook delivery cleanup failed',
                'Terminal delivery retention will be retried during the next cleanup run.'
            );
        }

        try {
            $this->webhookEventRepository->deleteOrphansBefore($failureCutoff);
        } catch (\Throwable) {
            Logger::error(
                'Webhook event cleanup failed',
                'Orphan event retention will be retried during the next cleanup run.'
            );
        }
    }

    private static function scheduleWebhookRecurringEvents(?callable $readiness = null): void
    {
        $readiness ??= [self::class, 'webhookPersistenceReady'];
        if (!$readiness()) {
            return;
        }

        foreach ([
            'fchub_memberships_webhook_reconcile' => 'five_minutes',
            'fchub_memberships_webhook_cleanup' => 'daily',
        ] as $hook => $recurrence) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $recurrence, $hook);
            }
        }
    }

    private static function webhookPersistenceReady(): bool
    {
        if (version_compare((string) get_option('fchub_memberships_db_version', '0'), '1.8.0', '<')) {
            return false;
        }

        global $wpdb;
        foreach (['webhook_events', 'webhook_deliveries'] as $suffix) {
            $table = $wpdb->prefix . 'fchub_membership_' . $suffix;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                return false;
            }
        }

        return Migrations::verifySchema() === [];
    }

    public function runValidityCheck(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        ($this->membershipLifecycleCoordinator ??= new MembershipLifecycleCoordinator())->checkValidity();
    }

    public function runDripProcess(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        (new \FChubMemberships\Domain\Drip\DripScheduleService(
            $this->dripScheduleRepository,
            null,
            null,
            $this->providerOperationWorker
        ))->processNotifications();
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
