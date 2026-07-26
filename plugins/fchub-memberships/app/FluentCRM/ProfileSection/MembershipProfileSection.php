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
    private const STATUS_PRECEDENCE = [
        'revoked' => 1,
        'expired' => 2,
        'paused' => 3,
        'active' => 4,
        'trial' => 5,
    ];

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
        $activeGroups = [];
        $otherGroups = [];

        foreach ($this->groupGrantsByPlan($grants) as $group) {
            $summary = $this->summariseGrantGroup($group);
            $planId = $summary['plan_id'];

            if ($planId !== null && !array_key_exists($planId, $planCache)) {
                $planCache[$planId] = $planRepo->find($planId);
            }

            if (in_array($summary['status'], ['active', 'trial'], true)) {
                $activeGroups[] = $summary;
            } else {
                $otherGroups[] = $summary;
            }
        }

        $html = '<h3>' . __('Active Memberships', 'fchub-memberships') . '</h3>';

        if (!empty($activeGroups)) {
            $table = new TableBuilder();
            $table->setHeader([
                'plan'      => __('Plan', 'fchub-memberships'),
                'status'    => __('Status', 'fchub-memberships'),
                'expires'   => __('Expires', 'fchub-memberships'),
                'renewals'  => __('Renewals', 'fchub-memberships'),
                'granted'   => __('Granted', 'fchub-memberships'),
            ]);

            foreach ($activeGroups as $group) {
                $table->addRow([
                    'plan'     => $this->planName($group['plan_id'], $planCache),
                    'status'   => $this->statusBadge($group['status'], $group['status_mixed']),
                    'expires'  => $this->expiryLabel($group['grants'], $dateFormat),
                    'renewals' => $this->renewalLabel($group['grants']),
                    'granted'  => $this->formatDate($group['created_at'], $dateFormat),
                ]);
            }

            $html .= $table->getHtml();

            $html .= $this->renderDripProgress($activeGroups, $planCache, $planRuleRepo, $dripRepo);
        } else {
            $html .= '<p>' . __('No active memberships.', 'fchub-memberships') . '</p>';
        }

        // Grant history
        if (!empty($otherGroups)) {
            $html .= '<h3>' . __('Grant History', 'fchub-memberships') . '</h3>';
            $historyTable = new TableBuilder();
            $historyTable->setHeader([
                'plan'     => __('Plan', 'fchub-memberships'),
                'status'   => __('Status', 'fchub-memberships'),
                'expires'  => __('Expires', 'fchub-memberships'),
                'renewals' => __('Renewals', 'fchub-memberships'),
                'granted'  => __('Granted', 'fchub-memberships'),
                'updated'  => __('Updated', 'fchub-memberships'),
            ]);

            foreach ($otherGroups as $group) {
                $historyTable->addRow([
                    'plan'     => $this->planName($group['plan_id'], $planCache),
                    'status'   => $this->statusBadge($group['status'], $group['status_mixed']),
                    'expires'  => $this->expiryLabel($group['grants'], $dateFormat),
                    'renewals' => $this->renewalLabel($group['grants']),
                    'granted'  => $this->formatDate($group['created_at'], $dateFormat),
                    'updated'  => $this->formatDate($group['updated_at'], $dateFormat),
                ]);
            }

            $html .= $historyTable->getHtml();
        }

        return $html;
    }

    private function groupGrantsByPlan(array $grants): array
    {
        $groups = [];

        foreach ($grants as $grant) {
            $planId = isset($grant['plan_id']) && (int) $grant['plan_id'] > 0
                ? (int) $grant['plan_id']
                : null;
            $key = $planId ?? sprintf(
                'direct:%s:%s:%s',
                (string) ($grant['provider'] ?? ''),
                (string) ($grant['resource_type'] ?? ''),
                (string) ($grant['resource_id'] ?? '')
            );

            $groups[$key][] = $grant;
        }

        uksort($groups, static function (int|string $left, int|string $right): int {
            if (is_int($left) && is_int($right)) {
                return $left <=> $right;
            }
            if (is_int($left)) {
                return -1;
            }
            if (is_int($right)) {
                return 1;
            }

            return strcmp($left, $right);
        });

        return $groups;
    }

    private function summariseGrantGroup(array $grants): array
    {
        $first = reset($grants);
        $statuses = array_map(fn(array $grant): string => $this->grantStatus($grant), $grants);
        $uniqueStatuses = array_values(array_unique($statuses));
        $status = 'revoked';

        foreach ($uniqueStatuses as $candidate) {
            if ((self::STATUS_PRECEDENCE[$candidate] ?? 0) > (self::STATUS_PRECEDENCE[$status] ?? 0)) {
                $status = $candidate;
            }
        }

        return [
            'plan_id' => isset($first['plan_id']) && (int) $first['plan_id'] > 0
                ? (int) $first['plan_id']
                : null,
            'status' => $status,
            'status_mixed' => count($uniqueStatuses) > 1,
            'created_at' => $this->dateBoundary($grants, 'created_at', true),
            'updated_at' => $this->dateBoundary($grants, 'updated_at', false),
            'grants' => array_values($grants),
        ];
    }

    private function renderDripProgress(array $groups, array $planCache, PlanRuleRepository $ruleRepo, DripScheduleRepository $dripRepo): string
    {
        $html = '';
        $hasProgress = false;

        foreach ($groups as $group) {
            $planId = $group['plan_id'];
            $plan = $planId !== null ? ($planCache[$planId] ?? null) : null;
            if (!$plan) continue;
            $rules = $ruleRepo->getByPlanId($planId);
            $totalItems = count($rules);
            if ($totalItems === 0) continue;

            if (!$hasProgress) {
                $html .= '<h3>' . __('Drip Progress', 'fchub-memberships') . '</h3>';
                $hasProgress = true;
            }

            $currentRuleIds = [];
            $unlockedRuleIds = [];
            foreach ($rules as $rule) {
                $ruleId = (int) $rule['id'];
                $currentRuleIds[$ruleId] = true;

                if (($rule['drip_type'] ?? 'immediate') === 'immediate') {
                    $unlockedRuleIds[$ruleId] = true;
                }
            }

            foreach ($group['grants'] as $grant) {
                foreach ($dripRepo->getByGrantId((int) $grant['id']) as $notification) {
                    $ruleId = (int) $notification['plan_rule_id'];
                    if (($notification['status'] ?? '') === 'sent' && isset($currentRuleIds[$ruleId])) {
                        $unlockedRuleIds[$ruleId] = true;
                    }
                }
            }

            $unlocked = min(count($unlockedRuleIds), $totalItems);
            $pct = round(($unlocked / $totalItems) * 100);

            $html .= '<p style="margin-bottom:4px;"><strong>' . esc_html($plan['title']) . '</strong>: '
                /* translators: Placeholder values are runtime membership details included in this message. */
                . sprintf(__('%1$d of %2$d items unlocked', 'fchub-memberships'), $unlocked, $totalItems) . '</p>';
            $html .= '<div style="background:#e0e0e0;border-radius:4px;height:12px;max-width:300px;margin-bottom:12px;">'
                . '<div style="background:#409EFF;border-radius:4px;height:12px;width:' . $pct . '%;"></div></div>';
        }

        return $html;
    }

    private function planName(?int $planId, array $planCache): string
    {
        if ($planId === null || empty($planCache[$planId])) {
            return __('(No Plan)', 'fchub-memberships');
        }

        return esc_html((string) $planCache[$planId]['title']);
    }

    private function statusBadge(string $status, bool $mixed): string
    {
        $colors = [
            'active'  => '#67C23A',
            'paused'  => '#E6A23C',
            'expired' => '#909399',
            'revoked' => '#F56C6C',
            'trial'   => '#409EFF',
        ];

        $color = $colors[$status] ?? '#909399';
        $label = ucfirst($status);
        if ($mixed) {
            /* translators: Placeholder values are runtime membership details included in this message. */
            $label = sprintf(__('%s (mixed)', 'fchub-memberships'), $label);
        }

        return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:12px;background:' . $color . ';">' . esc_html($label) . '</span>';
    }

    private function grantStatus(array $grant): string
    {
        $status = (string) ($grant['status'] ?? 'revoked');

        if ($status === 'active' && !empty($grant['trial_ends_at'])) {
            return 'trial';
        }

        return array_key_exists($status, self::STATUS_PRECEDENCE) ? $status : 'revoked';
    }

    private function expiryLabel(array $grants, string $dateFormat): string
    {
        $activeGrants = array_values(array_filter(
            $grants,
            static fn(array $grant): bool => ($grant['status'] ?? '') === 'active'
        ));

        if ($activeGrants !== []) {
            foreach ($activeGrants as $grant) {
                if (empty($grant['expires_at'])) {
                    return __('Never', 'fchub-memberships');
                }
            }

            return $this->formatDate(
                $this->dateBoundary($activeGrants, 'expires_at', false),
                $dateFormat
            );
        }

        // Paused and terminal rows keep their exact/uniform-or-varies history display.
        $expiries = array_map(
            static fn(array $grant): ?string => !empty($grant['expires_at'])
                ? (string) $grant['expires_at']
                : null,
            $grants
        );
        $uniqueExpiries = array_values(array_unique($expiries, SORT_REGULAR));

        if (count($uniqueExpiries) !== 1) {
            return __('Varies', 'fchub-memberships');
        }

        return $uniqueExpiries[0] === null
            ? __('Never', 'fchub-memberships')
            : $this->formatDate($uniqueExpiries[0], $dateFormat);
    }

    private function renewalLabel(array $grants): int|string
    {
        $counts = array_map(static fn(array $grant): int => (int) ($grant['renewal_count'] ?? 0), $grants);
        $uniqueCounts = array_values(array_unique($counts));
        $maximum = $counts ? max($counts) : 0;

        return count($uniqueCounts) > 1
            /* translators: Placeholder values are runtime membership details included in this message. */
            ? sprintf(__('%d (varies)', 'fchub-memberships'), $maximum)
            : $maximum;
    }

    private function dateBoundary(array $grants, string $field, bool $earliest): ?string
    {
        $dates = array_values(array_filter(array_column($grants, $field)));

        if ($dates === []) {
            return null;
        }

        usort($dates, static fn(string $left, string $right): int => strtotime($left) <=> strtotime($right));

        return $earliest ? reset($dates) : end($dates);
    }

    private function formatDate(?string $date, string $dateFormat): string
    {
        if ($date === null || strtotime($date) === false) {
            return '';
        }

        return gmdate($dateFormat, strtotime($date));
    }
}
