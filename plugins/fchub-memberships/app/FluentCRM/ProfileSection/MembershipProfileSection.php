<?php

namespace FChubMemberships\FluentCRM\ProfileSection;

defined('ABSPATH') || exit;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Html\TableBuilder;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\PlanRuleRepository;

class MembershipProfileSection
{
    public function register(): void
    {
        add_filter('fluentcrm_profile_sections', [$this, 'addSection']);
        // Note: FluentCRM has a typo in this hook name - "fluencrm" not "fluentcrm"
        add_filter('fluencrm_profile_section_fchub_memberships', [$this, 'getSection'], 10, 2);
    }

    public function addSection(array $sections): array
    {
        $sections['fchub_memberships'] = [
            'name'    => 'fluentcrm_profile_extended',
            'title'   => __('Memberships', 'fchub-memberships'),
            'handler' => 'route',
            'query'   => ['handler' => 'fchub_memberships'],
        ];

        return $sections;
    }

    public function getSection($section, Subscriber $subscriber): array
    {
        $section['heading'] = __('Membership Access', 'fchub-memberships');
        $userId = $subscriber->getWpUserId();

        if (!$userId) {
            $section['content_html'] = '<p>' . __('This contact is not linked to a WordPress user.', 'fchub-memberships') . '</p>';
            return $section;
        }

        $grants = (new GrantRepository())->getByUserId($userId);
        if (empty($grants)) {
            $section['content_html'] = '<p>' . __('No membership grants found.', 'fchub-memberships') . '</p>';
            return $section;
        }

        $section['content_html'] = $this->renderHtml($grants);
        return $section;
    }

    private function renderHtml(array $grants): string
    {
        $planRepo = new PlanRepository();
        $planRuleRepo = new PlanRuleRepository();
        $dripRepo = new DripScheduleRepository();
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');
        $planCache = [];
        $activeGrants = [];
        $otherGrants = [];

        foreach ($grants as $grant) {
            if ($grant['plan_id'] && !isset($planCache[$grant['plan_id']])) {
                $planCache[$grant['plan_id']] = $planRepo->find($grant['plan_id']);
            }
            if ($grant['status'] === 'active') {
                $activeGrants[] = $grant;
            } else {
                $otherGrants[] = $grant;
            }
        }

        $html = '<h3>' . __('Active Memberships', 'fchub-memberships') . '</h3>';

        if (!empty($activeGrants)) {
            $table = new TableBuilder();
            $table->setHeader([
                'plan'      => __('Plan', 'fchub-memberships'),
                'status'    => __('Status', 'fchub-memberships'),
                'expires'   => __('Expires', 'fchub-memberships'),
                'renewals'  => __('Renewals', 'fchub-memberships'),
                'granted'   => __('Granted', 'fchub-memberships'),
            ]);

            foreach ($activeGrants as $grant) {
                $planName = isset($planCache[$grant['plan_id']]) ? esc_html($planCache[$grant['plan_id']]['title']) : __('(No Plan)', 'fchub-memberships');
                $table->addRow([
                    'plan'     => $planName,
                    'status'   => $this->statusBadge($grant),
                    'expires'  => $grant['expires_at'] ? gmdate($dateFormat, strtotime($grant['expires_at'])) : __('Never', 'fchub-memberships'),
                    'renewals' => (int) $grant['renewal_count'],
                    'granted'  => gmdate($dateFormat, strtotime($grant['created_at'])),
                ]);
            }

            $html .= $table->getHtml();

            $html .= $this->renderDripProgress($activeGrants, $planCache, $planRuleRepo, $dripRepo);
        } else {
            $html .= '<p>' . __('No active memberships.', 'fchub-memberships') . '</p>';
        }

        // Grant history
        if (!empty($otherGrants)) {
            $html .= '<h3>' . __('Grant History', 'fchub-memberships') . '</h3>';
            $historyTable = new TableBuilder();
            $historyTable->setHeader([
                'plan'    => __('Plan', 'fchub-memberships'),
                'status'  => __('Status', 'fchub-memberships'),
                'granted' => __('Granted', 'fchub-memberships'),
                'updated' => __('Updated', 'fchub-memberships'),
            ]);

            foreach ($otherGrants as $grant) {
                $planName = isset($planCache[$grant['plan_id']]) ? esc_html($planCache[$grant['plan_id']]['title']) : __('(No Plan)', 'fchub-memberships');
                $historyTable->addRow([
                    'plan'    => $planName,
                    'status'  => $this->statusBadge($grant),
                    'granted' => gmdate($dateFormat, strtotime($grant['created_at'])),
                    'updated' => gmdate($dateFormat, strtotime($grant['updated_at'])),
                ]);
            }

            $html .= $historyTable->getHtml();
        }

        return $html;
    }

    private function renderDripProgress(array $grants, array $planCache, PlanRuleRepository $ruleRepo, DripScheduleRepository $dripRepo): string
    {
        $html = '';
        $hasProgress = false;

        foreach ($grants as $grant) {
            $plan = $planCache[$grant['plan_id'] ?? 0] ?? null;
            if (!$plan) continue;
            $totalItems = count($ruleRepo->getByPlanId($grant['plan_id']));
            if ($totalItems === 0) continue;

            if (!$hasProgress) {
                $html .= '<h3>' . __('Drip Progress', 'fchub-memberships') . '</h3>';
                $hasProgress = true;
            }

            $sentCount = count(array_filter($dripRepo->getByGrantId($grant['id']), fn($n) => $n['status'] === 'sent'));
            $unlocked = min($sentCount + 1, $totalItems);
            $pct = round(($unlocked / $totalItems) * 100);

            $html .= '<p style="margin-bottom:4px;"><strong>' . esc_html($plan['title']) . '</strong>: '
                . sprintf(__('%d of %d items unlocked', 'fchub-memberships'), $unlocked, $totalItems) . '</p>';
            $html .= '<div style="background:#e0e0e0;border-radius:4px;height:12px;max-width:300px;margin-bottom:12px;">'
                . '<div style="background:#409EFF;border-radius:4px;height:12px;width:' . $pct . '%;"></div></div>';
        }

        return $html;
    }

    private function statusBadge(array $grant): string
    {
        $colors = [
            'active'  => '#67C23A',
            'paused'  => '#E6A23C',
            'expired' => '#909399',
            'revoked' => '#F56C6C',
            'trial'   => '#409EFF',
        ];

        $status = $grant['status'];

        // Check for trial
        if ($status === 'active' && !empty($grant['trial_ends_at']) && strtotime($grant['trial_ends_at']) > time()) {
            $status = 'trial';
        }

        $color = $colors[$status] ?? '#909399';
        $label = ucfirst($status);

        return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:12px;background:' . $color . ';">' . esc_html($label) . '</span>';
    }
}
