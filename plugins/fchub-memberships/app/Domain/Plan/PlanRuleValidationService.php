<?php

namespace FChubMemberships\Domain\Plan;

use FChubMemberships\Support\ResourceTypeRegistry;

defined('ABSPATH') || exit;

final class PlanRuleValidationService
{
    private ResourceTypeRegistry $registry;

    public function __construct(?ResourceTypeRegistry $registry = null)
    {
        $this->registry = $registry ?? ResourceTypeRegistry::getInstance();
    }

    public function validate(array $rules): ?string
    {
        foreach ($rules as $index => $rule) {
            $ruleNum = $index + 1;
            $resourceType = $rule['resource_type'] ?? '';

            if ($resourceType !== '' && !$this->registry->isValid($resourceType)) {
                return sprintf(
                    __('Rule #%d: invalid resource type "%s".', 'fchub-memberships'),
                    $ruleNum,
                    $resourceType
                );
            }

            $dripType = $rule['drip_type'] ?? 'immediate';

            if ($dripType === 'fixed_date' && empty($rule['drip_date'])) {
                return sprintf(
                    __('Rule #%d: drip_date is required when drip type is "Fixed Date".', 'fchub-memberships'),
                    $ruleNum
                );
            }

            if ($dripType === 'fixed_date' && !empty($rule['drip_date'])) {
                $dripDate = strtotime($rule['drip_date']);
                if ($dripDate && $dripDate < strtotime('today')) {
                    return sprintf(
                        __('Rule #%d: drip date cannot be in the past.', 'fchub-memberships'),
                        $ruleNum
                    );
                }
            }

            if ($dripType === 'delayed') {
                $delayDays = (int) ($rule['drip_delay_days'] ?? 0);
                if ($delayDays < 1 || $delayDays > 730) {
                    return sprintf(
                        __('Rule #%d: delay days must be between 1 and 730.', 'fchub-memberships'),
                        $ruleNum
                    );
                }
            }
        }

        return null;
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
