<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FChubMemberships\Http\Controllers\SettingsController;

class FluentCommunitySync
{
    public function register(): void
    {
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            return;
        }

        $settings = SettingsController::getSettings();
        if (($settings['fc_enabled'] ?? 'no') !== 'yes') {
            return;
        }

        add_action('fchub_memberships/grant_created', [$this, 'onGrantCreated'], 10, 3);
        add_action('fchub_memberships/grant_revoked', [$this, 'onGrantRevoked'], 10, 4);
        add_action('fchub_memberships/grant_paused', [$this, 'onGrantPaused'], 10, 2);
        add_action('fchub_memberships/grant_resumed', [$this, 'onGrantResumed'], 10, 1);
        add_action('fchub_memberships/grant_expired', [$this, 'onGrantExpired'], 10, 1);
    }

    public function onGrantCreated(int $userId, int $planId, array $context = []): void
    {
        $this->updateUserMeta($userId, $planId, 'active');

        $settings = SettingsController::getSettings();
        $spaceMappings = $settings['fc_space_mappings'] ?? [];
        $spaceId = $spaceMappings[$planId] ?? null;

        if ($spaceId && class_exists('FluentCommunity\App\Models\Space')) {
            $space = \FluentCommunity\App\Models\Space::find((int) $spaceId);
            if ($space) {
                $space->addMember($userId, 'member');
            }
        }

        $badgeMappings = $settings['fc_badge_mappings'] ?? [];
        $badgeId = $badgeMappings[$planId] ?? null;

        if ($badgeId && class_exists('FluentCommunity\App\Models\Badge')) {
            $badge = \FluentCommunity\App\Models\Badge::find((int) $badgeId);
            if ($badge) {
                $badge->assignToUser($userId);
            }
        }
    }

    public function onGrantRevoked(array $grants, int $planId, int $userId, string $reason = ''): void
    {
        $this->updateUserMeta($userId, $planId, 'revoked');

        $settings = SettingsController::getSettings();
        $spaceMappings = $settings['fc_space_mappings'] ?? [];
        $spaceId = $spaceMappings[$planId] ?? null;

        if ($spaceId && class_exists('FluentCommunity\App\Models\Space')) {
            $space = \FluentCommunity\App\Models\Space::find((int) $spaceId);
            if ($space) {
                $space->removeMember($userId);
            }
        }

        if (($settings['fc_remove_badge_on_revoke'] ?? 'no') === 'yes') {
            $badgeMappings = $settings['fc_badge_mappings'] ?? [];
            $badgeId = $badgeMappings[$planId] ?? null;

            if ($badgeId && class_exists('FluentCommunity\App\Models\Badge')) {
                $badge = \FluentCommunity\App\Models\Badge::find((int) $badgeId);
                if ($badge) {
                    $badge->removeFromUser($userId);
                }
            }
        }
    }

    public function onGrantPaused(array $grant, string $reason = ''): void
    {
        $this->updateUserMeta($grant['user_id'], $grant['plan_id'] ?? 0, 'paused');
    }

    public function onGrantResumed(array $grant): void
    {
        $this->updateUserMeta($grant['user_id'], $grant['plan_id'] ?? 0, 'active');
    }

    public function onGrantExpired(array $grant): void
    {
        $this->updateUserMeta($grant['user_id'], $grant['plan_id'] ?? 0, 'expired');

        $settings = SettingsController::getSettings();
        $spaceMappings = $settings['fc_space_mappings'] ?? [];
        $planId = $grant['plan_id'] ?? 0;
        $spaceId = $spaceMappings[$planId] ?? null;

        if ($spaceId && class_exists('FluentCommunity\App\Models\Space')) {
            $space = \FluentCommunity\App\Models\Space::find((int) $spaceId);
            if ($space) {
                $space->removeMember($grant['user_id']);
            }
        }

        if (($settings['fc_remove_badge_on_revoke'] ?? 'no') === 'yes') {
            $badgeMappings = $settings['fc_badge_mappings'] ?? [];
            $badgeId = $badgeMappings[$planId] ?? null;

            if ($badgeId && class_exists('FluentCommunity\App\Models\Badge')) {
                $badge = \FluentCommunity\App\Models\Badge::find((int) $badgeId);
                if ($badge) {
                    $badge->removeFromUser($grant['user_id']);
                }
            }
        }
    }

    private function updateUserMeta(int $userId, int $planId, string $status): void
    {
        update_user_meta($userId, '_fchub_membership_status', $status);
        update_user_meta($userId, '_fchub_membership_plan_id', $planId);
        update_user_meta($userId, '_fchub_membership_updated', current_time('mysql'));
    }
}
