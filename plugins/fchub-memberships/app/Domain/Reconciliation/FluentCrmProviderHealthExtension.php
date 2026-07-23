<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\Reconciliation;

use FChubMemberships\Domain\Reconciliation\Contracts\ProviderHealthExtensionInterface;

defined('ABSPATH') || exit;

final class FluentCrmProviderHealthExtension implements ProviderHealthExtensionInterface
{
    private \Closure $contactResolver;
    private \Closure $availability;
    private bool $capabilityResolved = false;
    private bool $capabilityFailed = false;
    private ?ProviderHealthCapability $resolvedCapability = null;

    public function __construct(?callable $contactResolver = null, ?callable $availability = null)
    {
        $this->contactResolver = \Closure::fromCallable($contactResolver ?? self::resolveContact(...));
        $this->availability = \Closure::fromCallable($availability ?? self::isAvailable(...));
    }

    public function capability(): ProviderHealthCapability
    {
        if (!$this->capabilityResolved) {
            $this->capabilityResolved = true;
            try {
                $this->resolvedCapability = new ProviderHealthCapability(
                    'fluentcrm',
                    true,
                    (bool) ($this->availability)(),
                    ['fluentcrm_tag', 'fluentcrm_list']
                );
            } catch (\Throwable) {
                $this->capabilityFailed = true;
            }
        }
        if ($this->capabilityFailed || !$this->resolvedCapability instanceof ProviderHealthCapability) {
            throw new \RuntimeException('Provider capability could not be resolved.');
        }

        return $this->resolvedCapability;
    }

    public function observe(ProviderResource $resource): ProviderHealthObservation
    {
        try {
            $capability = $this->capability();
        } catch (\Throwable) {
            return new ProviderHealthObservation('unknown', 'provider_observation_failed');
        }
        if (!$capability->supports($resource) || !$capability->available) {
            return new ProviderHealthObservation('unknown', 'provider_unavailable');
        }

        try {
            $contact = ($this->contactResolver)($resource->userId);
            if (!$contact) {
                return new ProviderHealthObservation('absent', 'contact_absent');
            }

            $relation = $resource->resourceType === 'fluentcrm_tag' ? 'tags' : 'lists';
            $ids = $contact->{$relation}?->pluck('id')->toArray() ?? [];
            $present = in_array((int) $resource->resourceId, array_map('intval', $ids), true);

            return new ProviderHealthObservation(
                $present ? 'present' : 'absent',
                $present ? 'relation_present' : 'relation_absent'
            );
        } catch (\Throwable) {
            return new ProviderHealthObservation('unknown', 'provider_observation_failed');
        }
    }

    private static function resolveContact(int $userId): mixed
    {
        return FluentCrmApi('contacts')->getContactByUserId($userId);
    }

    private static function isAvailable(): bool
    {
        return function_exists('FluentCrmApi');
    }
}
