<?php

namespace FChubMemberships\Adapters\Contracts;

defined('ABSPATH') || exit;

interface AccessAdapterInterface
{
    /**
     * Whether this adapter handles the given resource type.
     */
    public function supports(string $resourceType): bool;

    /**
     * Grant access to a resource.
     *
     * @return array{success: bool, message: string}
     */
    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array;

    /**
     * Revoke access to a resource.
     *
     * @return array{success: bool, message: string}
     */
    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array;

    /**
     * Check if user has native platform access to a resource.
     */
    public function check(int $userId, string $resourceType, string $resourceId): bool;

    /**
     * Get a human-readable label for a resource.
     */
    public function getResourceLabel(string $resourceType, string $resourceId): string;

    /**
     * Search for resources of a given type.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function searchResources(string $query, string $resourceType, int $limit = 20): array;

    /**
     * Get available resource types handled by this adapter.
     *
     * @return array<string, string> ['type_key' => 'Label']
     */
    public function getResourceTypes(): array;
}
