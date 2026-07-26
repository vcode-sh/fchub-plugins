<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\CustomTableDatabase;
use FChubMemberships\Support\PreparedCustomTableQuery;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class CustomTableDatabaseTest extends PluginTestCase
{
    public function test_identifier_uses_wordpress_identifier_preparation(): void
    {
        self::assertTrue(class_exists(CustomTableDatabase::class));

        self::assertSame('wp_fchub_membership_grants', CustomTableDatabase::identifier(
            'wp_fchub_membership_grants',
        ));
    }

    public function test_prepare_delegates_value_placeholders_to_wordpress(): void
    {
        self::assertTrue(method_exists(CustomTableDatabase::class, 'prepare'));

        $query = CustomTableDatabase::prepare(
            'SELECT * FROM example WHERE id = %d AND status = %s',
            42,
            'active',
        );

        self::assertInstanceOf(PreparedCustomTableQuery::class, $query);
        self::assertSame(
            "SELECT * FROM example WHERE id = 42 AND status = 'active'",
            $query->sql(),
        );
    }

    public function test_read_and_write_methods_delegate_to_wpdb(): void
    {
        self::assertTrue(class_exists(CustomTableDatabase::class));

        CustomTableDatabase::getResults(CustomTableDatabase::prepare('SELECT %d', 1), ARRAY_A);
        CustomTableDatabase::getRow(CustomTableDatabase::prepare('SELECT %d', 2), ARRAY_A);
        CustomTableDatabase::getVar(CustomTableDatabase::prepare('SELECT %d', 3));
        CustomTableDatabase::getCol(CustomTableDatabase::prepare('SELECT %d', 4));
        CustomTableDatabase::query(CustomTableDatabase::prepare('DELETE FROM %i', 'example'));
        CustomTableDatabase::insert('example', ['value' => 1]);
        CustomTableDatabase::update('example', ['value' => 2], ['id' => 1]);
        CustomTableDatabase::delete('example', ['id' => 1]);

        self::assertSame(
            ['get_results', 'get_row', 'get_var', 'get_col', 'query', 'insert', 'update', 'delete'],
            array_column($GLOBALS['_fchub_test_queries'], 0),
        );
    }

    public function test_injected_database_methods_preserve_repository_testability(): void
    {
        self::assertTrue(method_exists(CustomTableDatabase::class, 'prepareOn'));
        self::assertTrue(method_exists(CustomTableDatabase::class, 'getResultsFrom'));
        self::assertTrue(method_exists(CustomTableDatabase::class, 'getRowFrom'));
        self::assertTrue(method_exists(CustomTableDatabase::class, 'getVarFrom'));
        self::assertTrue(method_exists(CustomTableDatabase::class, 'getColFrom'));
        self::assertTrue(method_exists(CustomTableDatabase::class, 'queryOn'));
        self::assertTrue(method_exists(CustomTableDatabase::class, 'insertInto'));
        self::assertTrue(method_exists(CustomTableDatabase::class, 'updateIn'));
        self::assertTrue(method_exists(CustomTableDatabase::class, 'deleteFrom'));

        $database = $GLOBALS['wpdb'];
        self::assertSame('wp_fchub_membership_grants', CustomTableDatabase::identifierOn(
            $database,
            'wp_fchub_membership_grants',
        ));
        self::assertSame(
            'SELECT 7',
            CustomTableDatabase::prepareOn($database, 'SELECT %d', 7)->sql(),
        );
        self::assertSame([], CustomTableDatabase::getResultsFrom($database, CustomTableDatabase::prepareOn($database, 'SELECT %d', 1), ARRAY_A));
        self::assertNull(CustomTableDatabase::getRowFrom($database, CustomTableDatabase::prepareOn($database, 'SELECT %d', 2), ARRAY_A));
        self::assertSame(0, CustomTableDatabase::getVarFrom($database, CustomTableDatabase::prepareOn($database, 'SELECT %d', 3)));
        self::assertSame([], CustomTableDatabase::getColFrom($database, CustomTableDatabase::prepareOn($database, 'SELECT %d', 4)));
        self::assertSame(0, CustomTableDatabase::queryOn($database, CustomTableDatabase::prepareOn($database, 'DELETE FROM %i', 'example')));
        self::assertSame(1, CustomTableDatabase::insertInto($database, 'example', ['value' => 1]));
        self::assertSame(1, CustomTableDatabase::updateIn($database, 'example', ['value' => 2], ['id' => 1]));
        self::assertSame(1, CustomTableDatabase::deleteFrom($database, 'example', ['id' => 1]));
    }

    public function test_query_preserves_wordpress_boolean_schema_change_result(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(): bool => true;

        try {
            $result = CustomTableDatabase::query(
                CustomTableDatabase::prepare('ALTER TABLE %i ADD `value` int', 'example'),
            );
        } catch (\TypeError) {
            $result = null;
        }

        self::assertTrue($result);
    }

    public function test_raw_arbitrary_sql_cannot_reach_execution_methods(): void
    {
        $this->expectException(\TypeError::class);

        CustomTableDatabase::getResults('SELECT * FROM users');
    }

    public function test_prepared_query_constructor_requires_an_issuance_capability(): void
    {
        $constructor = (new \ReflectionClass(PreparedCustomTableQuery::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(
            ['sql', 'issuanceCapability'],
            array_map(
                static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters(),
            ),
        );
    }

    public function test_arbitrary_callers_cannot_mint_or_execute_a_raw_query_token(): void
    {
        $before = count($GLOBALS['_fchub_test_queries']);

        try {
            $forged = PreparedCustomTableQuery::fromPreparedSql('DELETE FROM `example`');
            CustomTableDatabase::queryOn($GLOBALS['wpdb'], $forged);
        } catch (\Throwable) {
            // The secure boundary rejects the forged token before wpdb execution.
        }

        self::assertFalse(method_exists(CustomTableDatabase::class, 'literal'));
        self::assertFalse(method_exists(PreparedCustomTableQuery::class, 'fromPreparedSql'));
        self::assertCount($before, $GLOBALS['_fchub_test_queries']);
    }

    public function test_raw_query_cannot_bypass_preparation_through_an_indirect_variable(): void
    {
        $rawQuery = 'DELETE FROM `example`';
        $before = count($GLOBALS['_fchub_test_queries']);

        try {
            $query = CustomTableDatabase::prepare($rawQuery);
            CustomTableDatabase::queryOn($GLOBALS['wpdb'], $query);
        } catch (\InvalidArgumentException) {
            // Expected: no placeholder means no issued query token.
        }

        self::assertCount($before, $GLOBALS['_fchub_test_queries']);
    }

    public function test_token_constructor_rejects_an_external_capability(): void
    {
        $this->expectException(\LogicException::class);

        new PreparedCustomTableQuery('DELETE FROM `example`', new \stdClass());
    }
}
