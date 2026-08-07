<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\OrderMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

/**
 * The order-note query used to match comment_type IN ('order_note', ''), which
 * under HPOS drags in ordinary blog comments — order IDs and post IDs come from
 * different sequences and can collide. It also invented the customer-visible
 * flag from a heuristic instead of reading it from commentmeta.
 */
final class OrderNoteQueryTest extends PluginTestCase
{
    private object $originalWpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new \CartShiftTestWpdb();

        // No rows: migrateOrderNotes() returns before touching any FC model,
        // which is all we need in order to inspect the SQL it built.
        $GLOBALS['_cartshift_test_get_results_return'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->originalWpdb;

        parent::tearDown();
    }

    public function testOnlyRealOrderNotesAreSelected(): void
    {
        $sql = $this->noteQuery();

        $this->assertStringContainsString("c.comment_type = 'order_note'", $sql);
        $this->assertStringNotContainsString("IN ('order_note', '')", $sql);
        $this->assertStringNotContainsString('comment_type IN', $sql);
    }

    public function testTheQueryIsScopedToTheOrderAndApprovedComments(): void
    {
        $sql = $this->noteQuery(4242);

        $this->assertStringContainsString('c.comment_post_ID = 4242', $sql);
        $this->assertStringContainsString("c.comment_approved = '1'", $sql);
    }

    public function testTheCustomerFlagIsReadFromCommentMeta(): void
    {
        $sql = $this->noteQuery();

        $this->assertStringContainsString('wp_commentmeta', $sql);
        $this->assertStringContainsString("cm.meta_key = 'is_customer_note'", $sql);
        $this->assertStringContainsString('AS is_customer_note', $sql);
    }

    public function testTheCustomerFlagIsNotGuessedFromTheAuthor(): void
    {
        $sql = $this->noteQuery();

        $this->assertStringNotContainsString(
            'comment_author_email',
            $sql,
            'The customer-visible flag is real data, not a heuristic over the note author',
        );
    }

    public function testAllNotesAreReadInOneQuery(): void
    {
        $this->noteQuery();

        $reads = array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $entry): bool => $entry[0] === 'get_results',
        );

        $this->assertCount(1, $reads, 'Reading the flag must not introduce an N+1');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('customerNoteValues')]
    public function testCustomerNoteFlagInterpretation(mixed $raw, bool $expected): void
    {
        $method = new \ReflectionMethod(OrderMigrator::class, 'isCustomerNote');

        $this->assertSame($expected, $method->invoke(null, $raw));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function customerNoteValues(): array
    {
        return [
            'missing meta row means private note' => [null, false],
            'woocommerce writes the integer 1'    => [1, true],
            'and it comes back as a string'       => ['1', true],
            'explicit zero'                       => ['0', false],
            'empty string'                        => ['', false],
            'hand-edited yes'                     => ['yes', true],
            'hand-edited true'                    => ['TRUE', true],
            'hand-edited no'                      => ['no', false],
            'real boolean'                        => [true, true],
        ];
    }

    private function noteQuery(int $wcOrderId = 77): string
    {
        $migrator = new OrderMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );

        $method = new \ReflectionMethod($migrator, 'migrateOrderNotes');
        $method->invoke($migrator, $wcOrderId, 5);

        foreach (array_reverse($GLOBALS['_cartshift_test_queries'] ?? []) as $entry) {
            if ($entry[0] === 'get_results') {
                return (string) $entry[1];
            }
        }

        $this->fail('migrateOrderNotes() issued no query');
    }
}
