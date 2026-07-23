<?php

namespace FChubMemberships\Domain\Event;

defined('ABSPATH') || exit;

final readonly class EventClaimResult
{
    public const ACQUIRED = 'acquired';
    public const DUPLICATE_SUCCEEDED = 'duplicate_succeeded';
    public const IN_PROGRESS = 'in_progress';
    public const RETRYABLE_FAILED = 'retryable_failed';
    public const TERMINAL_FAILED = 'terminal_failed';

    private function __construct(public string $outcome)
    {
    }

    public static function acquired(): self
    {
        return new self(self::ACQUIRED);
    }

    public static function duplicateSucceeded(): self
    {
        return new self(self::DUPLICATE_SUCCEEDED);
    }

    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS);
    }

    public static function retryableFailed(): self
    {
        return new self(self::RETRYABLE_FAILED);
    }

    public static function terminalFailed(): self
    {
        return new self(self::TERMINAL_FAILED);
    }
}
