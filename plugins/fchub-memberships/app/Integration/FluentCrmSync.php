<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FChubMemberships\Http\Controllers\SettingsController;

class FluentCrmSync
{
    public function register(): void
    {
        if (!defined('FLUENTCRM') || !function_exists('FluentCrmApi')) {
            return;
        }

        $settings = SettingsController::getSettings();
        if (($settings['fluentcrm_enabled'] ?? 'no') !== 'yes') {
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
        $contact = $this->getContact($userId);
        if (!$contact) {
            return;
        }

        $settings = SettingsController::getSettings();
        $plan = $this->getPlan($planId);
        $planSlug = $plan ? sanitize_title($plan['title']) : $planId;
        $tagPrefix = $settings['fluentcrm_tag_prefix'] ?? 'member:';

        // Auto-create and apply tag
        $tagName = $tagPrefix . $planSlug;
        $tagId = $this->ensureTag($tagName, $settings);
        if ($tagId) {
            $contact->attachTags([$tagId]);
        }

        // Remove expired tag if present
        $expiredTagId = $this->findTagByTitle($tagPrefix . 'expired');
        if ($expiredTagId) {
            $contact->detachTags([$expiredTagId]);
        }

        // Add to default list
        $defaultListId = $settings['fluentcrm_default_list'] ?? '';
        if ($defaultListId) {
            $contact->attachLists([(int) $defaultListId]);
        }

        // Update custom fields
        $this->updateCustomFields($contact, $plan, 'active', $context);
    }

    public function onGrantRevoked(array $grants, int $planId, int $userId, string $reason = ''): void
    {
        $contact = $this->getContact($userId);
        if (!$contact) {
            return;
        }

        $settings = SettingsController::getSettings();
        $plan = $this->getPlan($planId);
        $planSlug = $plan ? sanitize_title($plan['title']) : $planId;
        $tagPrefix = $settings['fluentcrm_tag_prefix'] ?? 'member:';

        // Remove plan tag
        $tagId = $this->findTagByTitle($tagPrefix . $planSlug);
        if ($tagId) {
            $contact->detachTags([$tagId]);
        }

        // Remove from default list
        $defaultListId = $settings['fluentcrm_default_list'] ?? '';
        if ($defaultListId) {
            $contact->detachLists([(int) $defaultListId]);
        }

        // Update custom fields
        $this->updateCustomFields($contact, $plan, 'revoked');
    }

    public function onGrantPaused(array $grant, string $reason = ''): void
    {
        $contact = $this->getContact($grant['user_id']);
        if (!$contact) {
            return;
        }

        $settings = SettingsController::getSettings();
        $tagPrefix = $settings['fluentcrm_tag_prefix'] ?? 'member:';

        // Add paused tag
        $pausedTagId = $this->ensureTag($tagPrefix . 'paused', $settings);
        if ($pausedTagId) {
            $contact->attachTags([$pausedTagId]);
        }

        // Update custom field
        $contact->updateCustomFieldBySlug('membership_status', 'paused');
    }

    public function onGrantResumed(array $grant): void
    {
        $contact = $this->getContact($grant['user_id']);
        if (!$contact) {
            return;
        }

        $settings = SettingsController::getSettings();
        $tagPrefix = $settings['fluentcrm_tag_prefix'] ?? 'member:';

        // Remove paused tag
        $pausedTagId = $this->findTagByTitle($tagPrefix . 'paused');
        if ($pausedTagId) {
            $contact->detachTags([$pausedTagId]);
        }

        // Re-add active plan tag
        if (!empty($grant['plan_id'])) {
            $plan = $this->getPlan($grant['plan_id']);
            if ($plan) {
                $tagId = $this->ensureTag($tagPrefix . sanitize_title($plan['title']), $settings);
                if ($tagId) {
                    $contact->attachTags([$tagId]);
                }
            }
        }

        // Update custom field
        $contact->updateCustomFieldBySlug('membership_status', 'active');
    }

    public function onGrantExpired(array $grant): void
    {
        $contact = $this->getContact($grant['user_id']);
        if (!$contact) {
            return;
        }

        $settings = SettingsController::getSettings();
        $tagPrefix = $settings['fluentcrm_tag_prefix'] ?? 'member:';

        // Remove plan tag
        if (!empty($grant['plan_id'])) {
            $plan = $this->getPlan($grant['plan_id']);
            if ($plan) {
                $tagId = $this->findTagByTitle($tagPrefix . sanitize_title($plan['title']));
                if ($tagId) {
                    $contact->detachTags([$tagId]);
                }
            }
        }

        // Add expired tag
        $expiredTagId = $this->ensureTag($tagPrefix . 'expired', $settings);
        if ($expiredTagId) {
            $contact->attachTags([$expiredTagId]);
        }

        // Update custom fields
        $this->updateCustomFields($contact, null, 'expired');
    }

    private function getContact(int $userId): ?object
    {
        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);
        if ($contact) {
            return $contact;
        }

        $user = get_userdata($userId);
        if (!$user) {
            return null;
        }

        return FluentCrmApi('contacts')->createOrUpdate([
            'email'      => $user->user_email,
            'user_id'    => $userId,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
        ]);
    }

    private function ensureTag(string $tagName, array $settings): ?int
    {
        $existing = $this->findTagByTitle($tagName);
        if ($existing) {
            return $existing;
        }

        if (($settings['fluentcrm_auto_create_tags'] ?? 'yes') !== 'yes') {
            return null;
        }

        $result = FluentCrmApi('tags')->importBulk([
            ['title' => $tagName, 'slug' => sanitize_title($tagName)],
        ]);

        if (!empty($result) && isset($result[0])) {
            return $result[0]->id ?? null;
        }

        // Retry finding after import
        return $this->findTagByTitle($tagName);
    }

    private function findTagByTitle(string $title): ?int
    {
        $tag = FluentCrmApi('tags')->getInstance()->newQuery()
            ->where('title', $title)
            ->first();

        return $tag ? (int) $tag->id : null;
    }

    private function updateCustomFields(object $contact, ?array $plan, string $status, array $context = []): void
    {
        if ($plan) {
            $contact->updateCustomFieldBySlug('membership_plan', $plan['title']);
        }

        $contact->updateCustomFieldBySlug('membership_status', $status);

        $expiresAt = $context['expires_at'] ?? '';
        if ($expiresAt) {
            $contact->updateCustomFieldBySlug('membership_expires', $expiresAt);
        }
    }

    private function getPlan(int $planId): ?array
    {
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        return $planRepo->find($planId);
    }
}
