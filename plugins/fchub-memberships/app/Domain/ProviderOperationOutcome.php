<?php

declare(strict_types=1);

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

final readonly class ProviderOperationOutcome
{
    public const STATUSES = [
        'applied',
        'already-applied',
        'deferred',
        'retryable-failure',
        'terminal-failure',
    ];

    private function __construct(
        public string $status,
        string $code,
        string $message
    ) {
        $this->code = self::sanitiseCode($code);
        $this->message = self::sanitiseMessage($message);
    }

    public string $code;

    public string $message;

    public static function applied(string $code = 'provider_operation_applied', string $message = ''): self
    {
        return new self('applied', $code, $message);
    }

    public static function alreadyApplied(string $code = 'provider_state_already_applied', string $message = ''): self
    {
        return new self('already-applied', $code, $message);
    }

    public static function deferred(string $code = 'provider_operation_deferred', string $message = ''): self
    {
        return new self('deferred', $code, $message);
    }

    public static function retryableFailure(string $code = 'provider_operation_failed', string $message = ''): self
    {
        return new self('retryable-failure', $code, $message);
    }

    public static function terminalFailure(string $code = 'provider_operation_terminal', string $message = ''): self
    {
        return new self('terminal-failure', $code, $message);
    }

    private static function sanitiseCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9]+/', '_', $code) ?? '';
        $code = trim($code, '_');

        return substr($code !== '' ? $code : 'provider_operation_failed', 0, 100);
    }

    private static function sanitiseMessage(string $message): string
    {
        $message = sanitize_text_field($message);
        $message = preg_replace('/\s+/u', ' ', $message) ?? '';

        return mb_substr(trim($message), 0, 500);
    }
}
