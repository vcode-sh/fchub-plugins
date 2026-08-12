<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

defined('ABSPATH') || exit;

/** A stopped guided step whose plain presentation facts must survive with run state. */
final class GuidedRunFailure extends \RuntimeException
{
    /** @param array<string,mixed> $context */
    public function __construct(string $reason, public readonly array $context = [])
    {
        parent::__construct($reason);
    }
}
