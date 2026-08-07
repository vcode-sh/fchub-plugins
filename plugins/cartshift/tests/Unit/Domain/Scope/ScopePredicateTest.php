<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Scope;

use CartShift\Domain\Scope\ScopePredicate;
use CartShift\Tests\Unit\PluginTestCase;

final class ScopePredicateTest extends PluginTestCase
{
    public function testAnEmptyPredicateContributesNothingToAQuery(): void
    {
        $predicate = ScopePredicate::none();

        $this->assertTrue($predicate->isEmpty());
        $this->assertSame('', $predicate->sql());
        $this->assertSame('', $predicate->andSql());
        $this->assertSame([], $predicate->values());
    }

    public function testAnIdSetBecomesPlaceholdersInOrder(): void
    {
        $predicate = ScopePredicate::intIn('customer_id', [7, 19]);

        $this->assertSame('customer_id IN (%d, %d)', $predicate->sql());
        $this->assertSame([7, 19], $predicate->values());
        $this->assertSame(' AND (customer_id IN (%d, %d))', $predicate->andSql());
    }

    public function testAnEmptyIdSetMatchesNothingRatherThanEverything(): void
    {
        // The whole release turns on this. An empty IN list rendered as no
        // clause at all is how "migrate these three customers" becomes
        // "migrate all of them".
        $predicate = ScopePredicate::intIn('customer_id', []);

        $this->assertFalse($predicate->isEmpty());
        $this->assertTrue($predicate->matchesNoRows());
        $this->assertSame('1 = 0', $predicate->sql());
    }

    public function testARawFragmentIsNeverMistakenForTheEmptySet(): void
    {
        // "Matches nothing" is a flag on the object, not a string comparison
        // against the rendered SQL. Detecting it by comparing sql() to '1 = 0'
        // would drop this caller's deliberate clause out of any() and widen the
        // scope for no reason anybody could see.
        $raw = ScopePredicate::raw('1 = 0', []);

        $this->assertFalse($raw->matchesNoRows());
        $this->assertSame(
            '(1 = 0) OR (customer_id IN (%d))',
            ScopePredicate::any($raw, ScopePredicate::intIn('customer_id', [7]))->sql(),
        );
    }

    public function testAndSkipsEmptyPartsAndJoinsTheRest(): void
    {
        $predicate = ScopePredicate::all(
            ScopePredicate::none(),
            ScopePredicate::raw('date_created_gmt >= %s', ['2024-03-01 00:00:00']),
            ScopePredicate::intIn('customer_id', [7]),
        );

        $this->assertSame(
            '(date_created_gmt >= %s) AND (customer_id IN (%d))',
            $predicate->sql(),
        );
        $this->assertSame(['2024-03-01 00:00:00', 7], $predicate->values());
    }

    public function testOrOfNothingButEmptyPartsIsEmpty(): void
    {
        $this->assertTrue(ScopePredicate::any(ScopePredicate::none())->isEmpty());
    }

    public function testOrSkipsMatchesNothingPartsSoOneEmptySetDoesNotKillTheRest(): void
    {
        // A scope with picked customers but no picked guest emails must still
        // match the customers.
        $predicate = ScopePredicate::any(
            ScopePredicate::intIn('customer_id', [7]),
            ScopePredicate::stringIn('billing_email', []),
        );

        $this->assertSame('(customer_id IN (%d))', $predicate->sql());
        $this->assertSame([7], $predicate->values());
    }

    public function testAStringSetUsesStringPlaceholders(): void
    {
        $predicate = ScopePredicate::stringIn('billing_email', ['bob@example.com']);

        $this->assertSame('billing_email IN (%s)', $predicate->sql());
        $this->assertSame(['bob@example.com'], $predicate->values());
    }
}
