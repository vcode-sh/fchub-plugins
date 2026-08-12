<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Runtime;

use CartShift\Domain\Transfer\Runtime\WpdbTransferSchemaInspector;
use CartShift\Tests\Unit\PluginTestCase;

final class WpdbTransferSchemaInspectorTest extends PluginTestCase
{
    private object $previousDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDatabase = $GLOBALS['wpdb'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousDatabase;
        parent::tearDown();
    }

    public function testAdjacentTableIndexRowsCannotLeakThroughTheSortingReference(): void
    {
        $database = new SchemaInspectorDatabaseDouble();
        $GLOBALS['wpdb'] = $database;
        $inspector = new WpdbTransferSchemaInspector();

        $before = $inspector->inspect(['first', 'second']);
        $database->secondCardinality = 37;
        $after = $inspector->inspect(['first', 'second']);

        self::assertSame($before, $after, 'Changing ignored index statistics changed the schema projection.');
        self::assertSame([
            'PRIMARY' => ['unique' => true, 'columns' => ['id']],
            'stable_lookup' => ['unique' => false, 'columns' => ['value']],
        ], $after['first']['indexes']);
        self::assertSame([
            'PRIMARY' => ['unique' => true, 'columns' => ['id']],
        ], $after['second']['indexes']);
    }
}

final class SchemaInspectorDatabaseDouble
{
    public string $prefix = 'wp_';
    public int $secondCardinality = 0;

    public function prepare(string $query, string $table): string
    {
        return str_replace('%s', "'" . $table . "'", $query);
    }

    /** @return array{Engine:string} */
    public function get_row(string $query, string $output): array
    {
        return ['Engine' => 'InnoDB'];
    }

    /** @return list<array<string,mixed>> */
    public function get_results(string $query, string $output): array
    {
        if (str_starts_with($query, 'SHOW FULL COLUMNS')) {
            return [
                ['Field' => 'id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
                ['Field' => 'value', 'Type' => 'varchar(64)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
            ];
        }

        if (str_contains($query, '`wp_first`')) {
            return [
                $this->index('wp_first', 'PRIMARY', 0, 1, 'id', 1),
                $this->index('wp_first', 'stable_lookup', 1, 1, 'value', 9),
            ];
        }

        return [
            $this->index('wp_second', 'PRIMARY', 0, 1, 'id', $this->secondCardinality),
        ];
    }

    /** @return array<string,mixed> */
    private function index(
        string $table,
        string $name,
        int $nonUnique,
        int $position,
        string $column,
        int $cardinality,
    ): array {
        return [
            'Table' => $table,
            'Non_unique' => (string) $nonUnique,
            'Key_name' => $name,
            'Seq_in_index' => (string) $position,
            'Column_name' => $column,
            'Collation' => 'A',
            'Cardinality' => (string) $cardinality,
        ];
    }
}
