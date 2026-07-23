<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\Reconciliation;

defined('ABSPATH') || exit;

final readonly class ProviderHealthObservation
{
    public function __construct(public string $state, public string $code)
    {
        if (!in_array($state, ['present', 'absent', 'unknown'], true) || $code === '') {
            throw new \InvalidArgumentException('Provider health observation is invalid.');
        }
    }
}
