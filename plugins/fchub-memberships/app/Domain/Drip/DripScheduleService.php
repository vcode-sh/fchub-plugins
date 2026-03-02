<?php

namespace FChubMemberships\Domain\Drip;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Email\DripContentUnlockedEmail;
use FChubMemberships\Support\Logger;

class DripScheduleService
{
    private DripScheduleRepository $dripRepo;
    private GrantRepository $grantRepo;

    public function __construct()
    {
        $this->dripRepo = new DripScheduleRepository();
        $this->grantRepo = new GrantRepository();
    }

    /**
     * Process pending drip notifications. Called by hourly cron.
     */
    public function processNotifications(int $limit = 50): int
    {
        $pending = $this->dripRepo->getPendingNotifications($limit);
        $processed = 0;

        foreach ($pending as $notification) {
            $grant = $this->grantRepo->find($notification['grant_id']);
            if (!$grant || $grant['status'] !== 'active') {
                $this->dripRepo->markSent($notification['id']);
                continue;
            }

            try {
                $this->sendDripNotification($notification, $grant);
                $this->dripRepo->markSent($notification['id']);
                do_action('fchub_memberships/drip_unlocked', $notification, $grant, $notification['user_id']);
                $processed++;
            } catch (\Throwable $e) {
                $this->dripRepo->markFailed($notification['id']);

                $retryCount = ((int) ($notification['retry_count'] ?? 0)) + 1;
                if ($retryCount >= 3) {
                    Logger::error(
                        'Drip notification permanently failed',
                        sprintf('Notification #%d for user %d after %d retries: %s',
                            $notification['id'],
                            $notification['user_id'],
                            $retryCount,
                            $e->getMessage()
                        )
                    );
                } else {
                    Logger::log(
                        'Drip notification failed, will retry',
                        sprintf('Notification #%d retry %d/3: %s',
                            $notification['id'],
                            $retryCount,
                            $e->getMessage()
                        )
                    );
                }
            }
        }

        if ($processed > 0) {
            Logger::log('Drip notifications processed', sprintf('%d notifications sent', $processed));
        }

        // Check drip milestones for grants that had notifications processed
        $this->checkDripMilestones($pending);

        return $processed;
    }

    /**
     * Check if any grants have reached drip completion milestones and fire hooks.
     */
    private function checkDripMilestones(array $processedNotifications): void
    {
        $milestones = [25, 50, 75, 100];
        $checkedGrantIds = [];

        foreach ($processedNotifications as $notification) {
            $grantId = $notification['grant_id'];
            if (in_array($grantId, $checkedGrantIds, true)) {
                continue;
            }
            $checkedGrantIds[] = $grantId;

            $grant = $this->grantRepo->find($grantId);
            if (!$grant || $grant['status'] !== 'active') {
                continue;
            }

            $allNotifications = $this->dripRepo->getByGrantId($grantId);
            if (empty($allNotifications)) {
                continue;
            }

            $total = count($allNotifications);
            $sent = count(array_filter($allNotifications, fn($n) => $n['status'] === 'sent'));
            $percentage = (int) round(($sent / $total) * 100);

            $meta = $grant['meta'] ?? [];
            $firedMilestones = $meta['drip_milestones_fired'] ?? [];

            foreach ($milestones as $milestone) {
                if ($percentage >= $milestone && !in_array($milestone, $firedMilestones, true)) {
                    do_action('fchub_memberships/drip_milestone_reached', $grant, $milestone, $grant['user_id']);

                    $firedMilestones[] = $milestone;
                }
            }

            // Update meta if new milestones were fired
            if ($firedMilestones !== ($meta['drip_milestones_fired'] ?? [])) {
                $meta['drip_milestones_fired'] = $firedMilestones;
                $this->grantRepo->update($grantId, ['meta' => $meta]);
            }
        }
    }

    /**
     * Schedule drip notifications for a grant based on plan rules.
     */
    public function scheduleForGrant(int $grantId, int $planId, int $userId): void
    {
        $ruleRepo = new PlanRuleRepository();
        $rules = $ruleRepo->getByPlanId($planId);

        foreach ($rules as $rule) {
            if ($rule['drip_type'] === 'immediate') {
                continue;
            }

            $notifyAt = $this->calculateNotifyAt($rule);
            if (!$notifyAt) {
                continue;
            }

            $this->dripRepo->schedule([
                'grant_id'     => $grantId,
                'plan_rule_id' => $rule['id'],
                'user_id'      => $userId,
                'notify_at'    => $notifyAt,
            ]);
        }
    }

    /**
     * Get drip overview data for all plans.
     */
    public function getOverview(): array
    {
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $ruleRepo = new PlanRuleRepository();
        $plans = $planRepo->getActivePlans();
        $overview = [];

        foreach ($plans as $plan) {
            $dripRules = $ruleRepo->getDripRules($plan['id']);
            if (empty($dripRules)) {
                continue;
            }

            $totalItems = count($ruleRepo->getByPlanId($plan['id']));
            $dripItems = count($dripRules);

            $overview[] = [
                'plan_id'         => $plan['id'],
                'plan_title'      => $plan['title'],
                'total_items'     => $totalItems,
                'drip_items'      => $dripItems,
                'immediate_items' => $totalItems - $dripItems,
            ];
        }

        return $overview;
    }

    /**
     * Get calendar data for drip unlocks within a date range.
     */
    public function getCalendar(string $from, string $to): array
    {
        return $this->dripRepo->getUpcomingUnlocks($from, $to);
    }

    /**
     * Get notification queue data.
     */
    public function getNotificationQueue(array $filters = []): array
    {
        return $this->dripRepo->all($filters);
    }

    /**
     * Get queue stats.
     */
    public function getQueueStats(): array
    {
        return [
            'pending' => $this->dripRepo->countPending(),
            'sent'    => $this->dripRepo->countSent(),
        ];
    }

    /**
     * Retry a failed notification.
     */
    public function retry(int $notificationId): bool
    {
        $notification = $this->dripRepo->find($notificationId);
        if (!$notification || $notification['status'] !== 'failed') {
            return false;
        }

        $grant = $this->grantRepo->find($notification['grant_id']);
        if (!$grant || $grant['status'] !== 'active') {
            return false;
        }

        $this->sendDripNotification($notification, $grant);
        $this->dripRepo->markSent($notificationId);

        return true;
    }

    private function sendDripNotification(array $notification, array $grant): void
    {
        $settings = get_option('fchub_memberships_settings', []);
        if (($settings['email_drip_unlocked'] ?? 'yes') !== 'yes') {
            return;
        }

        $ruleRepo = new PlanRuleRepository();
        $rule = $ruleRepo->find($notification['plan_rule_id']);
        if (!$rule) {
            return;
        }

        // Get resource label
        $adapter = $this->getAdapter($grant['provider']);
        $resourceTitle = $adapter ? $adapter->getResourceLabel($grant['resource_type'], $grant['resource_id']) : $grant['resource_id'];
        $resourceUrl = $this->getResourceUrl($grant['resource_type'], $grant['resource_id']);

        // Get plan info
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plan = $grant['plan_id'] ? $planRepo->find($grant['plan_id']) : null;

        // Get drip progress
        $evaluator = new \FChubMemberships\Domain\AccessEvaluator();
        $progress = $grant['plan_id'] ? $evaluator->getDripProgress($notification['user_id'], $grant['plan_id']) : null;

        // Get next drip item
        $nextItem = null;
        if ($grant['plan_id']) {
            $allRules = $ruleRepo->getByPlanId($grant['plan_id']);
            foreach ($allRules as $r) {
                if ($r['sort_order'] > $rule['sort_order'] && $r['drip_type'] !== 'immediate') {
                    $nextAdapter = $this->getAdapter($r['provider'] ?? 'wordpress_core');
                    $nextItem = $nextAdapter ? $nextAdapter->getResourceLabel($r['resource_type'], $r['resource_id']) : $r['resource_id'];
                    break;
                }
            }
        }

        (new DripContentUnlockedEmail())->send($notification['user_id'], [
            'resource_title' => $resourceTitle,
            'resource_url'   => $resourceUrl,
            'plan_title'     => $plan ? $plan['title'] : '',
            'next_drip_item' => $nextItem,
            'progress'       => $progress,
        ]);
    }

    private function calculateNotifyAt(array $rule): ?string
    {
        if ($rule['drip_type'] === 'delayed' && $rule['drip_delay_days'] > 0) {
            return gmdate('Y-m-d H:i:s', strtotime('+' . $rule['drip_delay_days'] . ' days'));
        }

        if ($rule['drip_type'] === 'fixed_date' && !empty($rule['drip_date'])) {
            return $rule['drip_date'];
        }

        return null;
    }

    private function getAdapter(string $provider): ?object
    {
        $adapters = [
            'wordpress_core'   => \FChubMemberships\Adapters\WordPressContentAdapter::class,
            'learndash'        => \FChubMemberships\Adapters\LearnDashAdapter::class,
            'fluentcrm'        => \FChubMemberships\Adapters\FluentCrmAdapter::class,
            'fluent_community' => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
        ];

        $class = $adapters[$provider] ?? null;
        if ($class && class_exists($class)) {
            return new $class();
        }

        return null;
    }

    private function getResourceUrl(string $resourceType, string $resourceId): string
    {
        if (in_array($resourceType, ['post', 'page'], true) || post_type_exists($resourceType)) {
            return get_permalink((int) $resourceId) ?: '';
        }

        if (taxonomy_exists($resourceType)) {
            $link = get_term_link((int) $resourceId, $resourceType);
            return is_wp_error($link) ? '' : $link;
        }

        return '';
    }
}
