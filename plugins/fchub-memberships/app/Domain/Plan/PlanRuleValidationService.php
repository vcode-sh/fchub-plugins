<?php

namespace FChubMemberships\Domain\Plan;

use FChubMemberships\Support\ResourceTypeRegistry;
use FluentCommunity\App\Functions\Utility;

defined('ABSPATH') || exit;

final class PlanRuleValidationService
{
    private ResourceTypeRegistry $registry;
    private \Closure $badgeCatalogueResolver;

    public function __construct(
        ?ResourceTypeRegistry $registry = null,
        ?callable $badgeCatalogueResolver = null
    ) {
        $this->registry = $registry ?? ResourceTypeRegistry::getInstance();
        $this->badgeCatalogueResolver = \Closure::fromCallable(
            $badgeCatalogueResolver ?? self::installedBadgeCatalogue(...)
        );
    }

    public function validate(array $rules): ?string
    {
        foreach ($rules as $index => $rule) {
            $ruleNum = $index + 1;
            $resourceType = $rule['resource_type'] ?? '';

            if ($resourceType !== '' && !$this->registry->isValid($resourceType)) {
                return sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('Rule #%1$d: invalid resource type "%2$s".', 'fchub-memberships'),
                    $ruleNum,
                    $resourceType
                );
            }

            $typeConfig = $this->registry->get($resourceType);
            $identifier = (string) ($typeConfig['identifier'] ?? 'positive_int');
            $resourceId = trim((string) ($rule['resource_id'] ?? ''));
            if (
                $typeConfig
                && ($typeConfig['allow_all'] ?? true) === false
                && $identifier === 'positive_int'
                && !preg_match('/^[1-9]\d*$/', $resourceId)
            ) {
                if (in_array($resourceId, ['0', '*'], true)) {
                    return sprintf(
                        /* translators: Placeholder values are runtime membership details included in this message. */
                        __('Rule #%1$d: resource type "%2$s" does not support all resources; choose a positive resource ID.', 'fchub-memberships'),
                        $ruleNum,
                        $resourceType
                    );
                }

                return sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('Rule #%1$d: resource type "%2$s" requires a positive resource ID.', 'fchub-memberships'),
                    $ruleNum,
                    $resourceType
                );
            }
            if ($typeConfig
                && ($typeConfig['allow_all'] ?? true) === false
                && $identifier === 'slug'
                && (
                    $resourceId === ''
                    || preg_match('/\D/', $resourceId) !== 1
                    || sanitize_title($resourceId) !== $resourceId
                )
            ) {
                return sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('Rule #%1$d: resource type "%2$s" requires a sanitised resource slug.', 'fchub-memberships'),
                    $ruleNum,
                    $resourceType
                );
            }
            if ($resourceType === 'fc_badge' && !$this->isInstalledBadgeSlug($resourceId)) {
                return sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('Rule #%1$d: FluentCommunity badge slug "%2$s" is not installed.', 'fchub-memberships'),
                    $ruleNum,
                    $resourceId
                );
            }

            $dripType = $rule['drip_type'] ?? 'immediate';

            if ($dripType === 'fixed_date' && empty($rule['drip_date'])) {
                return sprintf(
                    /* translators: Placeholder values are runtime membership details included in this message. */
                    __('Rule #%d: drip_date is required when drip type is "Fixed Date".', 'fchub-memberships'),
                    $ruleNum
                );
            }

            if ($dripType === 'fixed_date' && !empty($rule['drip_date'])) {
                $dripDate = strtotime($rule['drip_date']);
                if ($dripDate && $dripDate < strtotime('today')) {
                    return sprintf(
                        /* translators: Placeholder values are runtime membership details included in this message. */
                        __('Rule #%d: drip date cannot be in the past.', 'fchub-memberships'),
                        $ruleNum
                    );
                }
            }

            if ($dripType === 'delayed') {
                $delayDays = (int) ($rule['drip_delay_days'] ?? 0);
                if ($delayDays < 1 || $delayDays > 730) {
                    return sprintf(
                        /* translators: Placeholder values are runtime membership details included in this message. */
                        __('Rule #%d: delay days must be between 1 and 730.', 'fchub-memberships'),
                        $ruleNum
                    );
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function installedBadgeCatalogue(): array
    {
        if (!is_callable([Utility::class, 'getOption'])) {
            return [];
        }

        $catalogue = Utility::getOption('user_badges', []);

        return is_array($catalogue) ? $catalogue : [];
    }

    private function isInstalledBadgeSlug(string $resourceId): bool
    {
        try {
            $catalogue = ($this->badgeCatalogueResolver)();
        } catch (\Throwable) {
            return false;
        }

        return is_array($catalogue) && array_key_exists($resourceId, $catalogue);
    }

    public function prepareForStorage(array $rules): array
    {
        return array_map(function (array $rule): array {
            $resourceType = $rule['resource_type'] ?? '';
            $typeConfig = $this->registry->get($resourceType);
            if ($typeConfig) {
                $rule['provider'] = $typeConfig['provider'];
            }

            unset($rule['access_type'], $rule['resource_label'], $rule['resource_type_label']);

            return $rule;
        }, $rules);
    }
}
