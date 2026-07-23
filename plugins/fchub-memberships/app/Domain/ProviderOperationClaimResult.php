<?php

declare(strict_types=1);

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

final readonly class ProviderOperationClaimResult
{
    private function __construct(
        public string $outcome,
        public ?array $operation = null
    ) {
    }

    public static function acquired(array $operation): self
    {
        return new self('acquired', $operation);
    }

    public static function inProgress(array $operation): self
    {
        return new self('in-progress', $operation);
    }

    public static function applied(array $operation): self
    {
        return new self('applied', $operation);
    }

    public static function notDue(array $operation): self
    {
        return new self('not-due', $operation);
    }

    public static function deferred(array $operation): self
    {
        return new self('deferred', $operation);
    }

    public static function terminal(array $operation): self
    {
        return new self('terminal', $operation);
    }

    public static function missing(): self
    {
        return new self('missing');
    }
}
