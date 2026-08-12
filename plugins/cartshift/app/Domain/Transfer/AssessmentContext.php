<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

class AssessmentContext
{
    /** @param array<string, mixed> $values */
    public function __construct(public readonly array $values = [])
    {
    }
}
