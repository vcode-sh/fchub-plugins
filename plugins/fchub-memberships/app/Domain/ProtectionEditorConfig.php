<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;

class ProtectionEditorConfig
{
    public function __construct(
        private ?ProtectionRuleRepository $protectionRepo = null,
        private ?PlanRuleResolver $ruleResolver = null,
        private ?PlanRepository $planRepo = null
    ) {
        $this->protectionRepo ??= new ProtectionRuleRepository();
        $this->ruleResolver ??= new PlanRuleResolver();
        $this->planRepo ??= new PlanRepository();
    }

    public function getForPost(int $postId, string $postType): array
    {
        $postIdString = (string) $postId;
        $rule = $this->protectionRepo->findByResource($postType, $postIdString);
        $meta = $rule['meta'] ?? [];
        $teaserMode = $meta['teaser_mode'] ?? null;
        if ($teaserMode === null && $rule) {
            $teaserMode = ($rule['show_teaser'] ?? 'no') === 'yes' ? 'excerpt' : 'none';
        }

        $plans = array_map(static fn(array $plan): array => [
            'id' => (int) $plan['id'],
            'label' => (string) $plan['title'],
        ], $this->planRepo->getActivePlans());
        $planNames = [];
        foreach ($plans as $plan) {
            $planNames[$plan['id']] = $plan['label'];
        }

        $sources = [];
        if ($rule) {
            $sources[] = [
                'type' => 'direct',
                'label' => __('Direct protection', 'fchub-memberships'),
                'detail' => $this->planSummary($rule['plan_ids'] ?? [], $planNames),
            ];
        }

        $implicitPlanIds = array_map('intval', $this->ruleResolver->findPlansWithResource(
            Constants::PROVIDER_WORDPRESS_CORE,
            $postType,
            $postIdString
        ));
        if ($implicitPlanIds !== []) {
            $sources[] = [
                'type' => 'plan_rule',
                'label' => __('Included by plan rules', 'fchub-memberships'),
                'detail' => $this->planSummary($implicitPlanIds, $planNames),
                'manage_url' => admin_url('admin.php?page=fchub-memberships#/plans'),
            ];
        }

        foreach (get_object_taxonomies($postType, 'names') as $taxonomy) {
            $terms = get_the_terms($postId, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $taxonomyRule = $this->protectionRepo->findByResource($taxonomy, (string) $term->term_id);
                if (!$taxonomyRule || ($taxonomyRule['meta']['inheritance_mode'] ?? 'none') !== 'all_posts') {
                    continue;
                }

                $sources[] = [
                    'type' => 'taxonomy',
                    'label' => sprintf(__('Inherited from %s', 'fchub-memberships'), (string) $term->name),
                    'detail' => $this->planSummary($taxonomyRule['plan_ids'] ?? [], $planNames),
                    'manage_url' => get_edit_term_link((int) $term->term_id, $taxonomy),
                ];
            }
        }

        $hasInherited = count($sources) > ($rule ? 1 : 0);
        $mode = $rule ? ($hasInherited ? 'mixed' : 'direct') : ($hasInherited ? 'inherited' : 'public');

        return [
            'enabled' => $rule !== null,
            'plan_ids' => array_values(array_map('intval', $rule['plan_ids'] ?? [])),
            'teaser_mode' => $teaserMode ?: 'none',
            'teaser_word_count' => (int) ($meta['teaser_word_count'] ?? 50),
            'custom_teaser' => (string) ($meta['custom_teaser'] ?? ''),
            'restriction_message' => (string) ($rule['restriction_message'] ?? ''),
            'fallback_message' => (new AccessEvaluator())->getRestrictionMessage($postType, $postIdString),
            'cta_text' => (string) ($meta['cta_text'] ?? ''),
            'cta_url' => (string) ($meta['cta_url'] ?? ''),
            'plans' => $plans,
            'effective' => [
                'protected' => $sources !== [],
                'mode' => $mode,
                'sources' => $sources,
            ],
        ];
    }

    public function saveForPost(int $postId, string $postType, array $config): bool|\WP_Error
    {
        $existing = $this->protectionRepo->findByResource($postType, (string) $postId);
        if (empty($config['enabled'])) {
            if ($existing) {
                $this->protectionRepo->delete((int) $existing['id']);
            }
            AccessEvaluator::clearCache();
            return true;
        }

        $ctaText = sanitize_text_field((string) ($config['cta_text'] ?? ''));
        $ctaUrl = esc_url_raw((string) ($config['cta_url'] ?? ''));
        if (($ctaText === '') !== ($ctaUrl === '')) {
            return new \WP_Error(
                'fchub_incomplete_cta',
                __('Add both the button label and destination, or leave both empty.', 'fchub-memberships')
            );
        }

        $allowedModes = ['none', 'excerpt', 'more_tag', 'words', 'custom'];
        $teaserMode = sanitize_text_field((string) ($config['teaser_mode'] ?? 'none'));
        if (!in_array($teaserMode, $allowedModes, true)) {
            $teaserMode = 'none';
        }

        $planIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($config['plan_ids'] ?? [])),
            static fn(int $planId): bool => $planId > 0
        )));
        $wordCount = max(1, min(500, absint($config['teaser_word_count'] ?? 50) ?: 50));

        $this->protectionRepo->createOrUpdate($postType, (string) $postId, [
            'plan_ids' => $planIds,
            'protection_mode' => Constants::PROTECTION_MODE_EXPLICIT,
            'restriction_message' => sanitize_textarea_field((string) ($config['restriction_message'] ?? '')) ?: null,
            'show_teaser' => $teaserMode === 'none' ? 'no' : 'yes',
            'meta' => [
                'teaser_mode' => $teaserMode,
                'teaser_word_count' => $wordCount,
                'custom_teaser' => sanitize_textarea_field((string) ($config['custom_teaser'] ?? '')),
                'cta_text' => $ctaText,
                'cta_url' => $ctaUrl,
            ],
        ]);

        AccessEvaluator::clearCache();
        return true;
    }

    private function planSummary(array $planIds, array $planNames): string
    {
        if ($planIds === []) {
            return __('Any active membership plan', 'fchub-memberships');
        }

        $labels = array_map(
            static fn(int $planId): string => $planNames[$planId] ?? sprintf(__('Plan #%d', 'fchub-memberships'), $planId),
            array_map('intval', $planIds)
        );
        return implode(', ', $labels);
    }
}
