<?php

namespace CartShift\Tests\Unit\Support;

use CartShift\Support\SqlIdentifier;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The three sanitisers this replaced were two strip-lists and an allow-list.
 * These tests pin the allow-list's actual contract, in both directions: what it
 * lets through unchanged, and what it refuses. A guard that only ever gets
 * tested with hostile input tends to quietly reject legitimate input too.
 */
final class SqlIdentifierTest extends PluginTestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function columnReferences(): array
    {
        return [
            ['status'],
            ['o.status'],
            ['p.ID'],
            ['customer_id'],
            ['billing_email'],
            ['_id'],
            ['t2.column_9'],
            ['CAST(im.meta_value AS UNSIGNED)'],
        ];
    }

    #[DataProvider('columnReferences')]
    public function testAColumnReferencePassesThroughUnchanged(string $column): void
    {
        $this->assertSame($column, SqlIdentifier::column($column, 'p.ID'));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'statement separator'    => ['o.status; DROP TABLE wp_posts', 'a second statement'],
            'comment'                => ['status -- x', 'a trailing comment'],
            'space'                  => ['o status', 'two tokens rather than one'],
            'leading digit'          => ['1status', 'not a legal identifier'],
            'empty'                  => ['', 'nothing at all'],
            'whitespace only'        => ['   ', 'nothing at all'],
            'bare dot'               => ['.', 'no name either side'],
            'trailing dot'           => ['o.', 'a qualifier with no column'],
            'three parts'            => ['a.b.c', 'deeper than table.column'],
            'quoted'                 => ['`status`', 'backticks are not part of the name'],
            'function call'          => ['COUNT(status)', 'only CAST is permitted'],
            'cast with a subquery'   => ['CAST((SELECT 1) AS UNSIGNED)', 'not an identifier inside'],
        ];
    }

    #[DataProvider('refusals')]
    public function testAnythingElseFallsBackToTheGivenColumn(string $column, string $why): void
    {
        $this->assertSame('p.ID', SqlIdentifier::column($column, 'p.ID'), $why);
    }

    public function testTheFallbackIsValidatedToo(): void
    {
        // A caller with a broken fallback has made the same mistake one level
        // up. Trusting it would reintroduce exactly the hole this closes.
        $this->assertSame(
            'p.ID',
            SqlIdentifier::column('nonsense; DROP TABLE wp_posts', 'also; nonsense'),
        );
    }

    public function testSurroundingWhitespaceIsNotAReasonToRefuse(): void
    {
        $this->assertSame('o.status', SqlIdentifier::column('  o.status  ', 'p.ID'));
    }
}
