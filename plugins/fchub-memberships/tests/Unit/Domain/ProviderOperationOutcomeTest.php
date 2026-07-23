<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProviderOperationOutcomeTest extends PluginTestCase
{
    public function test_outcomes_are_immutable_and_expose_exactly_five_results(): void
    {
        self::assertTrue(class_exists(ProviderOperationOutcome::class));
        self::assertTrue((new \ReflectionClass(ProviderOperationOutcome::class))->isReadOnly());

        self::assertSame('applied', ProviderOperationOutcome::applied()->status);
        self::assertSame('already-applied', ProviderOperationOutcome::alreadyApplied()->status);
        self::assertSame('deferred', ProviderOperationOutcome::deferred()->status);
        self::assertSame('retryable-failure', ProviderOperationOutcome::retryableFailure()->status);
        self::assertSame('terminal-failure', ProviderOperationOutcome::terminalFailure()->status);
        self::assertSame(
            ['applied', 'already-applied', 'deferred', 'retryable-failure', 'terminal-failure'],
            ProviderOperationOutcome::STATUSES
        );
    }

    public function test_outcome_carries_only_stable_code_and_sanitised_message(): void
    {
        $outcome = ProviderOperationOutcome::retryableFailure(
            'Provider Error! 500',
            "  Provider <b>failed</b>\nwith token secret-token  "
        );

        self::assertSame('provider_error_500', $outcome->code);
        self::assertSame('Provider failed with token secret-token', $outcome->message);
    }
}
