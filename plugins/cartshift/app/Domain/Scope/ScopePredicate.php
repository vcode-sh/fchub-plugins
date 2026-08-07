<?php

declare(strict_types=1);

namespace CartShift\Domain\Scope;

defined('ABSPATH') || exit;

/**
 * An unprepared SQL fragment plus the values its placeholders want.
 *
 * Same shape WooStorage::orderScopeParts() returns, and for the same reason:
 * the scope has to fold into the *same* $wpdb->prepare() call as the keyset
 * range and the LIMIT, rather than being prepared separately and nested — a
 * prepared string inside a prepared string is how percent signs in real data
 * turn into corrupted queries.
 *
 * Two kinds of nothing, and the difference is the whole point:
 *
 *   none()          — no clause at all; the caller's query is unrestricted.
 *   matchesNothing() — '1 = 0'; the caller asked for an empty set and gets one.
 *
 * An empty IN list rendered as none() is how "migrate these three customers"
 * silently becomes "migrate all of them".
 *
 * @see \CartShift\Support\WooStorage::orderScopeParts()
 */
final class ScopePredicate
{
    /**
     * @param list<int|string> $values
     */
    private function __construct(
        private readonly string $sql,
        private readonly array $values,
        private readonly bool $matchesNoRows = false,
    ) {
    }

    public static function none(): self
    {
        return new self('', []);
    }

    public static function matchesNothing(): self
    {
        return new self('1 = 0', [], true);
    }

    /**
     * @param list<int|string> $values
     */
    public static function raw(string $sql, array $values): self
    {
        $sql = trim($sql);

        // Never flagged, whatever it renders as. A raw fragment that happens to
        // spell '1 = 0' is a caller's deliberate clause, not this class's empty
        // set, and any() must not drop it.
        return $sql === '' ? self::none() : new self($sql, array_values($values));
    }

    /**
     * @param list<int> $ids
     */
    public static function intIn(string $column, array $ids): self
    {
        $ids = array_values(array_map(intval(...), $ids));

        if ($ids === []) {
            return self::matchesNothing();
        }

        return new self(
            self::sanitizeIdentifier($column) . ' IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            $ids,
        );
    }

    /**
     * @param list<string> $values
     */
    public static function stringIn(string $column, array $values): self
    {
        $values = array_values(array_map(strval(...), $values));

        if ($values === []) {
            return self::matchesNothing();
        }

        return new self(
            self::sanitizeIdentifier($column) . ' IN (' . implode(', ', array_fill(0, count($values), '%s')) . ')',
            $values,
        );
    }

    /**
     * Conjunction. Empty parts drop out; a matchesNothing() part does not,
     * because an AND with an empty set genuinely is an empty set.
     */
    public static function all(self ...$parts): self
    {
        return self::join('AND', $parts, static fn (self $part): bool => $part->isEmpty());
    }

    /**
     * Disjunction. Both empty *and* matches-nothing parts drop out: an OR with
     * an empty set is the other side of the OR, and a none() part would make
     * the whole disjunction unrestricted, which is the wrong direction.
     */
    public static function any(self ...$parts): self
    {
        return self::join(
            'OR',
            $parts,
            static fn (self $part): bool => $part->isEmpty() || $part->matchesNoRows(),
        );
    }

    public function isEmpty(): bool
    {
        return $this->sql === '';
    }

    /**
     * Is this the empty set — as opposed to no clause at all?
     *
     * A flag rather than `sql() === '1 = 0'`. Inferring it from the rendered SQL
     * would make any raw() fragment that spells the same string disappear out of
     * an any(), silently, and the failure would look like a scope that widened
     * for no reason.
     */
    public function matchesNoRows(): bool
    {
        return $this->matchesNoRows;
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * @return list<int|string>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Spliceable form: `' AND (…)'`, or `''` when there is nothing to add.
     */
    public function andSql(): string
    {
        return $this->sql === '' ? '' : ' AND (' . $this->sql . ')';
    }

    /**
     * @param array<int, self> $parts
     * @param callable(self): bool $skip
     */
    private static function join(string $operator, array $parts, callable $skip): self
    {
        $sql = [];
        $values = [];

        foreach ($parts as $part) {
            if ($skip($part)) {
                continue;
            }

            $sql[] = '(' . $part->sql . ')';
            $values = [...$values, ...$part->values];
        }

        if ($sql === []) {
            return self::none();
        }

        return new self(implode(' ' . $operator . ' ', $sql), $values);
    }

    /**
     * Column names are never user input here, but strip anything that is not a
     * plausible identifier before it reaches a query string. Same guard
     * WooStorage applies, and for the same reason.
     */
    private static function sanitizeIdentifier(string $identifier): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.]/', '', $identifier) ?? '';

        return $clean === '' ? 'id' : $clean;
    }
}
