<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Scope;

use CartShift\Domain\Scope\MigrationScope;
use CartShift\State\MigrationState;
use CartShift\Tests\Unit\PluginTestCase;

final class ScopePersistenceTest extends PluginTestCase
{
    public function testAStateWithNoScopeReadsAsEverything(): void
    {
        $state = new MigrationState();
        $state->start(['product']);

        $this->assertTrue($state->getScope()->isEverything());
    }

    public function testTheScopeSurvivesIntoTheStoredState(): void
    {
        $state = new MigrationState();
        $scope = MigrationScope::fromArray(['mode' => 'since', 'since' => '2024-03-01']);

        $state->start(['order'], false, $scope);

        $this->assertSame('2024-03-01 00:00:00', $state->getScope()->since());
        $this->assertSame('since', $state->getProgress()['scope']['mode']);
    }

    public function testAResumedRunReadsTheSameScopeAFreshInstanceSees(): void
    {
        // The batch after the first runs in a fresh PHP request, so the only
        // thing carrying the scope across is the stored option. A resumed run
        // that widened here would migrate records the owner never confirmed.
        $state = new MigrationState();
        $state->start(['order'], false, MigrationScope::fromArray([
            'mode'         => 'explicit',
            'customer_ids' => [7, 19],
        ]));

        $resumed = new MigrationState();

        $this->assertSame([7, 19], $resumed->getScope()->customerIds());
        $this->assertSame('explicit', $resumed->getScope()->mode());
    }
}
