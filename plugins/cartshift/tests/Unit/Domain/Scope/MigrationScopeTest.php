<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Scope;

use CartShift\Domain\Scope\MigrationScope;
use CartShift\Tests\Unit\PluginTestCase;

final class MigrationScopeTest extends PluginTestCase
{
    public function testAnAbsentScopeIsEverything(): void
    {
        $scope = MigrationScope::fromArray(null);

        $this->assertTrue($scope->isEverything());
        $this->assertSame(MigrationScope::MODE_EVERYTHING, $scope->mode());
        $this->assertNull($scope->since());
    }

    public function testADateBoundBecomesTheStartOfThatDayInGmt(): void
    {
        $scope = MigrationScope::fromArray(['mode' => 'since', 'since' => '2024-03-01']);

        $this->assertSame('2024-03-01 00:00:00', $scope->since());
        $this->assertSame('2024-03-01', $scope->toArray()['since']);
    }

    public function testASinceModeWithNoUsableDateFallsBackToEverything(): void
    {
        // Widening is the safe direction here and only here: there is no date to
        // honour, and migrating everything is never a data-loss outcome. The UI
        // cannot reach this; a hand-rolled REST call can.
        $scope = MigrationScope::fromArray(['mode' => 'since', 'since' => 'last Tuesday']);

        $this->assertTrue($scope->isEverything());
    }

    public function testPickedIdsAreCoercedDeduplicatedAndOrdered(): void
    {
        $scope = MigrationScope::fromArray([
            'mode'         => 'explicit',
            'product_ids'  => ['44', 12, 44, 0, -3, 'nonsense'],
            'customer_ids' => [7],
            'guest_emails' => ['  BOB@example.com ', 'bob@example.com', ''],
        ]);

        $this->assertSame([12, 44], $scope->productIds());
        $this->assertSame([7], $scope->customerIds());
        $this->assertSame(['bob@example.com'], $scope->guestEmails());
    }

    public function testAnExplicitScopeThatPicksNothingIsNotSilentlyEverything(): void
    {
        $scope = MigrationScope::fromArray(['mode' => 'explicit']);

        $this->assertFalse($scope->isEverything());
        $this->assertSame(MigrationScope::MODE_EXPLICIT, $scope->mode());
    }

    public function testItRoundTripsThroughToArray(): void
    {
        $raw = [
            'mode'                        => 'explicit',
            'since'                       => null,
            'product_ids'                 => [12],
            'customer_ids'                => [7],
            'guest_emails'                => ['bob@example.com'],
            'include_orders_for_products' => true,
        ];

        $this->assertSame($raw, MigrationScope::fromArray($raw)->toArray());
    }
}
