<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\TransferRunState;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferRunStateTest extends PluginTestCase
{
    public function testOnlyTheDeclaredStateMachineEdgesAreLegal(): void
    {
        $legal = [
            'exported' => ['validated'],
            'validated' => ['prepared'],
            'prepared' => ['staging'],
            'staging' => ['staged', 'interrupted', 'failed'],
            'staged' => ['reconciling'],
            'reconciling' => ['reconciled', 'interrupted', 'failed'],
            'reconciled' => ['promoted'],
            'promoted' => ['catalogue_activating', 'completed', 'failed'],
            'catalogue_activating' => ['completed', 'interrupted', 'failed'],
            'interrupted' => ['staging', 'reconciling', 'catalogue_activating', 'failed'],
            'failed' => ['rolling_back'],
            'rolling_back' => ['rolled_back', 'failed'],
            'completed' => [],
            'rolled_back' => [],
        ];

        foreach (TransferRunState::cases() as $from) {
            foreach (TransferRunState::cases() as $to) {
                self::assertSame(
                    in_array($to->value, $legal[$from->value], true),
                    $from->canTransitionTo($to),
                    sprintf('Unexpected state-machine answer for %s -> %s.', $from->value, $to->value),
                );
            }
        }
    }
}
