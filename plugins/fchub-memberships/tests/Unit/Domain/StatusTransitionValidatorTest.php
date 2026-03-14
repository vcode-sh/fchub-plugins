<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\StatusTransitionValidator;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class StatusTransitionValidatorTest extends PluginTestCase
{
    public function test_valid_transitions_are_allowed(): void
    {
        self::assertTrue(StatusTransitionValidator::isValid('active', 'paused'));
        self::assertTrue(StatusTransitionValidator::isValid('paused', 'active'));
        self::assertTrue(StatusTransitionValidator::isValid('expired', 'active'));
        self::assertTrue(StatusTransitionValidator::isValid('revoked', 'active'));
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        self::assertFalse(StatusTransitionValidator::isValid('expired', 'paused'));
        self::assertFalse(StatusTransitionValidator::isValid('revoked', 'paused'));

        $this->expectException(\InvalidArgumentException::class);
        StatusTransitionValidator::assertTransition('revoked', 'paused');
    }
}
