<?php

namespace FChubMemberships\Domain\Event;

defined('ABSPATH') || exit;

final readonly class EventProcessingOutcome
{
    private function __construct(
        public bool $success,
        public bool $retryable,
        public bool $skipped,
        public ?string $error
    ) {
    }

    public static function succeeded(): self
    {
        return new self(true, false, false, null);
    }

    public static function skipped(): self
    {
        return new self(true, false, true, null);
    }

    public static function retryableFailure(string $error, bool $skipped = false): self
    {
        return new self(false, true, $skipped, $error);
    }

    public static function terminalFailure(string $error, bool $skipped = false): self
    {
        return new self(false, false, $skipped, $error);
    }
}
