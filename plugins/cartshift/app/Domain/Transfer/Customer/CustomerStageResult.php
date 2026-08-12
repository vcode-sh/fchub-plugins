<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

defined('ABSPATH') || exit;

final readonly class CustomerStageResult
{
    /** @param list<int> $addressIds */
    public function __construct(public int $targetId, public array $addressIds, public string $targetFingerprint, public bool $reused)
    {
        if ($targetId <= 0 || preg_match('/\A[a-f0-9]{64}\z/D', $targetFingerprint) !== 1 || !array_is_list($addressIds)) throw new \InvalidArgumentException('Customer stage result is invalid.');
    }
}
