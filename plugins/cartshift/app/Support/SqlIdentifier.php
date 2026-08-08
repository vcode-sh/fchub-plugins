<?php

namespace CartShift\Support;

/**
 * The one place that decides whether a string may be spliced into SQL as a
 * column reference.
 *
 * There were three: WooStorage, ScopePredicate and ProductTypes each carried
 * their own, and two of the three were strip-lists — delete every character
 * outside `[A-Za-z0-9_.]` and use whatever survives. That is the weaker shape,
 * for a reason worth stating plainly: stripping turns
 * `o.status; DROP TABLE wp_posts` into `o.statusDROPTABLEwp_posts`, which is
 * not an injection but is not a column either. The query fails at the database
 * with an error naming a column nobody wrote, and the caller's actual mistake —
 * passing something that was never an identifier — is now invisible.
 *
 * An allow-list answers the question that was actually being asked. Either the
 * string has the shape of a column reference or it does not, and if it does not
 * the caller gets a valid column instead of a broken query. That keeps a
 * programming error a wrong answer rather than a fatal one, which matters here:
 * these clauses run inside a migration, and a migration that dies mid-run costs
 * an owner more than one that reports the wrong count.
 *
 * No user input reaches this today — every column in the codebase is a literal,
 * and the only parameterised one is productPredicate()'s, which every caller
 * passes 'p.ID'. This exists so that stays true by construction rather than by
 * everyone remembering.
 */
final class SqlIdentifier
{
    /**
     * A bare identifier, or a qualified one: `status`, `o.status`, `p.ID`.
     *
     * Deliberately does not permit a leading digit, because SQL does not.
     */
    private const string IDENTIFIER = '[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?';

    /**
     * `$column` if it is a column reference, `$fallback` if it is not.
     *
     * `CAST(<identifier> AS <TYPE>)` is allowed because ScopeConsequences needs
     * it to compare a line-item meta value against a post ID, and wrapping that
     * in a second helper would put us back at two implementations.
     *
     * `$fallback` is validated too. A caller that supplies a broken fallback
     * has made the same mistake one level up, and silently trusting it would
     * reintroduce exactly the hole this closes.
     */
    public static function column(string $column, string $fallback = 'p.ID'): string
    {
        if (self::isColumn($column)) {
            return trim($column);
        }

        return self::isColumn($fallback) ? trim($fallback) : 'p.ID';
    }

    private static function isColumn(string $candidate): bool
    {
        $candidate = trim($candidate);

        if (preg_match('/^' . self::IDENTIFIER . '$/', $candidate) === 1) {
            return true;
        }

        return preg_match('/^CAST\(' . self::IDENTIFIER . ' AS [A-Za-z]+\)$/', $candidate) === 1;
    }
}
