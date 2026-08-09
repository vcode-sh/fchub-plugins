<?php

declare(strict_types=1);

/**
 * Proof that a read-only command really is read-only.
 *
 * The plan's first P0 is that CartShift's existing dry run is not zero-write —
 * it writes simulated ID-map rows — and the subscription audit is the answer to
 * that. An assertion of the form "the audit did not call the repository" is not
 * that answer: it passes for code that reaches around the repository, it passes
 * for code that writes an option, and it passes for code nobody has written
 * yet. So this intercepts at the `$wpdb` level, where every write in the plugin
 * eventually has to arrive, and refuses.
 *
 * Options, transients and scheduled actions do NOT go through `$wpdb` in this
 * suite — the bootstrap stubs keep them in globals — so those are snapshotted
 * and compared instead. Between the two, there is nowhere for a write to hide.
 *
 * The guard is only worth having if it would catch a real write, which is why
 * `ZeroWriteGuardTest` makes one on purpose and requires the guard to say so.
 */

if (!class_exists('CartShiftZeroWriteViolation')) {
    /**
     * A write that happened where none was allowed.
     *
     * Thrown rather than merely recorded: a violation must stop the run at the
     * statement that caused it, so the stack trace names the culprit. The
     * guard catches it and re-reports it with the whole list.
     */
    final class CartShiftZeroWriteViolation extends RuntimeException
    {
    }
}

if (!class_exists('CartShiftZeroWriteWpdb')) {
    /**
     * A wpdb that reads normally and refuses to write at all.
     *
     * Every mutating entry point wpdb exposes is overridden. `query()` is the
     * interesting one: it is the door a hand-written INSERT walks through, so
     * the statement's leading keyword is inspected rather than trusted.
     */
    final class CartShiftZeroWriteWpdb extends wpdb
    {
        /** @var list<array{method: string, target: string}> */
        public array $violations = [];

        /** Statements that change something. Checked as the first bare word. */
        private const array WRITE_KEYWORDS = [
            'insert',
            'update',
            'delete',
            'replace',
            'create',
            'alter',
            'drop',
            'truncate',
            'rename',
            'grant',
            'set',
        ];

        public string $comments = 'wp_comments';
        public string $commentmeta = 'wp_commentmeta';

        #[\Override]
        public function insert(string $table, array $data, ?array $format = null): int|false
        {
            return $this->refuse('insert', $table);
        }

        #[\Override]
        public function replace(string $table, array $data, ?array $format = null): int|false
        {
            return $this->refuse('replace', $table);
        }

        #[\Override]
        public function update(
            string $table,
            array $data,
            array $where,
            ?array $format = null,
            ?array $where_format = null,
        ): int|false {
            return $this->refuse('update', $table);
        }

        #[\Override]
        public function delete(string $table, array $where, ?array $where_format = null): int|false
        {
            return $this->refuse('delete', $table);
        }

        #[\Override]
        public function query(string $query): int|false
        {
            return $this->isWrite($query)
                ? $this->refuse('query', $query)
                : parent::query($query);
        }

        private function isWrite(string $query): bool
        {
            $first = strtolower(strtok(ltrim($query), " \t\n\r("));

            return $first !== false && in_array($first, self::WRITE_KEYWORDS, true);
        }

        private function refuse(string $method, string $target): never
        {
            $this->violations[] = ['method' => $method, 'target' => $target];

            throw new CartShiftZeroWriteViolation(sprintf(
                'A zero-write command attempted %s on %s.',
                $method,
                $target,
            ));
        }
    }
}

if (!class_exists('CartShiftZeroWriteGuard')) {
    /**
     * Run a callable with the write-refusing wpdb installed, then report.
     *
     * @phpstan-type Report array{writes: list<string>, options: list<string>, transients: list<string>, actions: int}
     */
    final class CartShiftZeroWriteGuard
    {
        /**
         * @template T
         * @param callable(): T $run
         * @return array{result: mixed, violations: list<string>}
         */
        public static function watch(callable $run): array
        {
            $originalWpdb = $GLOBALS['wpdb'];
            $spy = new CartShiftZeroWriteWpdb();

            // EVERY GLOBAL A WRITE COULD LAND IN, not the four that were easy
            // to think of. The FluentCart ORM stub records into
            // `_cartshift_test_fc_models` and `_cartshift_test_fc_saved` and
            // never touches `$wpdb` — which made the LARGEST write surface in
            // the plugin invisible to the guard whose whole job is to prove
            // nothing writes. Posts, user meta and the term globals are the same
            // shape of hole one layer down. Nothing on the audited path writes
            // through any of them today; the claim was sound and the proof was
            // narrower than advertised.
            $watched = [
                'option'             => '_cartshift_test_options',
                'transient'          => '_cartshift_test_transients',
                'scheduled action'   => '_cartshift_test_as_scheduled',
                'post meta'          => '_cartshift_test_post_meta',
                'FluentCart model'   => '_cartshift_test_fc_models',
                'FluentCart save'    => '_cartshift_test_fc_saved',
                'FluentCart meta'    => '_cartshift_test_fc_meta',
                'post'               => '_cartshift_test_posts',
                'deleted post'       => '_cartshift_test_deleted_posts',
                'user meta'          => '_cartshift_test_user_meta',
                'term'               => '_cartshift_test_terms',
                'inserted term'      => '_cartshift_test_inserted_terms',
                'object term'        => '_cartshift_test_object_terms',
                'deleted term'       => '_cartshift_test_deleted_terms',
            ];

            $before = [];

            foreach ($watched as $global) {
                $before[$global] = $GLOBALS[$global] ?? [];
            }

            $GLOBALS['wpdb'] = $spy;

            $violations = [];
            $result = null;

            try {
                $result = $run();
            } catch (CartShiftZeroWriteViolation $violation) {
                $violations[] = $violation->getMessage();
            } finally {
                $GLOBALS['wpdb'] = $originalWpdb;
            }

            foreach ($spy->violations as $attempt) {
                $message = sprintf('%s on %s', $attempt['method'], $attempt['target']);

                if (!in_array($message, $violations, true)) {
                    $violations[] = $message;
                }
            }

            foreach ($watched as $label => $global) {
                if ($before[$global] !== ($GLOBALS[$global] ?? [])) {
                    $violations[] = sprintf('%s state changed', $label);
                }
            }

            return ['result' => $result, 'violations' => $violations];
        }
    }
}
