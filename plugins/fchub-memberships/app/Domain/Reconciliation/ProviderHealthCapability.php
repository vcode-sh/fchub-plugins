<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\Reconciliation;

defined('ABSPATH') || exit;

final readonly class ProviderHealthCapability
{
    /** @param list<string> $resourceTypes */
    public function __construct(
        public string $provider,
        public bool $certified,
        public bool $available,
        public array $resourceTypes,
        public string $status = 'healthy',
        public ?string $version = null,
        public string $reason = 'provider_available',
        public array $capabilities = []
    ) {
    }

    public function supports(ProviderResource $resource): bool
    {
        return $resource->provider === $this->provider
            && in_array($resource->resourceType, $this->resourceTypes, true);
    }
}
