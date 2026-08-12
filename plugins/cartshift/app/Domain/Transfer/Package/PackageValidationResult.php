<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

defined('ABSPATH') || exit;

final readonly class PackageValidationResult
{
    /** @param list<string> $errors */
    public function __construct(public bool $valid, public array $errors = [])
    {
        if ($valid && $errors !== []) {
            throw new \InvalidArgumentException('A valid package cannot carry validation errors.');
        }
    }
}
