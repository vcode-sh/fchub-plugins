<?php

namespace FChubMemberships\Adapters\Contracts;

defined('ABSPATH') || exit;

interface BatchResourceLabelAdapterInterface
{
    /**
     * Resolve labels for a bounded set of persisted resource IDs.
     *
     * @param string[] $resourceIds
     * @return array<string, string> Labels keyed by resource ID.
     */
    public function getResourceLabels(string $resourceType, array $resourceIds): array;
}
