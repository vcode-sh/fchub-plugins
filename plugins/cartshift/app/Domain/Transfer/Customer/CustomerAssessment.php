<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

defined('ABSPATH') || exit;

final readonly class CustomerAssessment
{
    /** @param array<string, scalar|null> $evidence */
    public function __construct(public string $action, public array $evidence = [])
    {
        if (!in_array($action, ['create_target_customer_unlinked', 'reuse_exact_customer_map', 'reuse_explicit_target_customer', 'attach_exact_same_site_user', 'requires_mapping_decision', 'blocked_ambiguous_identity', 'blocked_invalid_customer'], true)) {
            throw new \InvalidArgumentException('Customer assessment action is invalid.');
        }
    }
}
