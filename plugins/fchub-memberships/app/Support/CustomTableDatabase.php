<?php

declare(strict_types=1);

namespace FChubMemberships\Support;

final class CustomTableDatabase
{
    private static ?object $queryIssuanceCapability = null;

    private static function queryIssuanceCapability(): object
    {
        return self::$queryIssuanceCapability ??= new \stdClass();
    }

    public static function acceptsQueryIssuanceCapability(object $capability): bool
    {
        return $capability === self::queryIssuanceCapability();
    }

    private static function issuePreparedQuery(string $sql): PreparedCustomTableQuery
    {
        return new PreparedCustomTableQuery($sql, self::queryIssuanceCapability());
    }

    public static function identifier(string $identifier): string
    {
        global $wpdb;

        return self::identifierOn($wpdb, $identifier);
    }

    public static function identifierOn(object $database, string $identifier): string
    {
        return trim($database->prepare('%i', $identifier), '`');
    }

    public static function prepare(string $query, mixed ...$args): PreparedCustomTableQuery
    {
        global $wpdb;

        return self::prepareOn($wpdb, $query, ...$args);
    }

    public static function prepareOn(object $database, string $query, mixed ...$args): PreparedCustomTableQuery
    {
        if (
            $args === []
            || preg_match('/(?<!%)%(?:[1-9][0-9]*\$)?[dfis]/', $query) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Custom-table query preparation requires at least one value or identifier placeholder.',
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Callers prepare identifiers through identifier() and pass runtime values only as arguments to this central wpdb::prepare boundary.
        return self::issuePreparedQuery($database->prepare($query, ...$args));
    }

    public static function getResults(PreparedCustomTableQuery $query, string $output = OBJECT): array
    {
        global $wpdb;

        return self::getResultsFrom($wpdb, $query, $output);
    }

    public static function getResultsFrom(
        object $database,
        PreparedCustomTableQuery $query,
        string $output = OBJECT,
    ): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutable custom membership tables require transaction-current reads; the type contract accepts only prepared query values.
        return $database->get_results($query->sql(), $output);
    }

    public static function getRow(PreparedCustomTableQuery $query, string $output = OBJECT): array|object|null
    {
        global $wpdb;

        return self::getRowFrom($wpdb, $query, $output);
    }

    public static function getRowFrom(
        object $database,
        PreparedCustomTableQuery $query,
        string $output = OBJECT,
    ): array|object|null
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutable custom membership tables require transaction-current reads; the type contract accepts only prepared query values.
        return $database->get_row($query->sql(), $output);
    }

    public static function getVar(PreparedCustomTableQuery $query): string|int|float|null
    {
        global $wpdb;

        return self::getVarFrom($wpdb, $query);
    }

    public static function getVarFrom(
        object $database,
        PreparedCustomTableQuery $query,
    ): string|int|float|null
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutable custom membership tables require transaction-current reads; the type contract accepts only prepared query values.
        return $database->get_var($query->sql());
    }

    public static function getCol(PreparedCustomTableQuery $query, int $columnOffset = 0): array
    {
        global $wpdb;

        return self::getColFrom($wpdb, $query, $columnOffset);
    }

    public static function getColFrom(
        object $database,
        PreparedCustomTableQuery $query,
        int $columnOffset = 0,
    ): array {
        return $database->get_col($query->sql(), $columnOffset);
    }

    public static function query(PreparedCustomTableQuery $query): int|bool
    {
        global $wpdb;

        return self::queryOn($wpdb, $query);
    }

    public static function queryOn(
        object $database,
        PreparedCustomTableQuery $query,
    ): int|bool {
        return $database->query($query->sql());
    }

    public static function beginTransaction(): int|bool
    {
        global $wpdb;

        return self::queryOn($wpdb, self::issuePreparedQuery('START TRANSACTION'));
    }

    public static function commit(): int|bool
    {
        global $wpdb;

        return self::queryOn($wpdb, self::issuePreparedQuery('COMMIT'));
    }

    public static function rollBack(): int|bool
    {
        global $wpdb;

        return self::queryOn($wpdb, self::issuePreparedQuery('ROLLBACK'));
    }

    public static function insert(string $table, array $data, ?array $format = null): int|false
    {
        global $wpdb;

        return self::insertInto($wpdb, $table, $data, $format);
    }

    public static function insertInto(object $database, string $table, array $data, ?array $format = null): int|false
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WordPress prepares values inside wpdb::insert for this plugin-owned custom table.
        return $database->insert($table, $data, $format);
    }

    public static function update(
        string $table,
        array $data,
        array $where,
        ?array $format = null,
        ?array $whereFormat = null,
    ): int|false {
        global $wpdb;

        return self::updateIn($wpdb, $table, $data, $where, $format, $whereFormat);
    }

    public static function updateIn(
        object $database,
        string $table,
        array $data,
        array $where,
        ?array $format = null,
        ?array $whereFormat = null,
    ): int|false {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress prepares values inside wpdb::update and the calling repository performs its existing cache invalidation.
        return $database->update($table, $data, $where, $format, $whereFormat);
    }

    public static function delete(string $table, array $where, ?array $whereFormat = null): int|false
    {
        global $wpdb;

        return self::deleteFrom($wpdb, $table, $where, $whereFormat);
    }

    public static function deleteFrom(
        object $database,
        string $table,
        array $where,
        ?array $whereFormat = null,
    ): int|false {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress prepares predicates inside wpdb::delete and the calling repository performs its existing cache invalidation.
        return $database->delete($table, $where, $whereFormat);
    }
}
