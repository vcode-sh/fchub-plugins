<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\Reconciliation;

defined('ABSPATH') || exit;

final readonly class ProviderResource
{
    public function __construct(
        public int $userId,
        public string $provider,
        public string $resourceType,
        public string $resourceId
    ) {
        if ($userId <= 0 || $provider === '' || $resourceType === '' || $resourceId === '') {
            throw new \InvalidArgumentException('Provider resource identity is invalid.');
        }
    }

    /** @return array{user_id: int, provider: string, resource_type: string, resource_id: string} */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'provider' => $this->provider,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }

    public static function fromArray(array $resource): self
    {
        return new self(
            (int) ($resource['user_id'] ?? 0),
            trim((string) ($resource['provider'] ?? '')),
            trim((string) ($resource['resource_type'] ?? '')),
            trim((string) ($resource['resource_id'] ?? ''))
        );
    }
}
