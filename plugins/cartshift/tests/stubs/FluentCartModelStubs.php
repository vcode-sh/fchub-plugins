<?php

declare(strict_types=1);

/**
 * A working fake behind FluentCart's model stubs, so a migration can be run end
 * to end in a unit test.
 *
 * Most of the suite deliberately stops before any model call — a coupon with no
 * code never reaches Coupon::query(). The gap-policy tests cannot do that: the
 * whole question is what a migrated record looks like when a reference is
 * missing, and there is no migrated record without something to create it.
 *
 * The classes themselves already exist: stubs/fluent-cart-stubs.php declares
 * them for the IDE and Composer loads it for every test run. What they lack is
 * behaviour, which arrives here as a handler installed on
 * `_cartshift_test_fc_model_handler` — a `_cartshift_test_*` global, so
 * PluginTestCase clears it between tests. Install it in setUp() and only the
 * test that asked for it ever sees a working ORM; every other test in the suite
 * behaves exactly as it did before, whatever order they run in.
 *
 * Created rows land in `_cartshift_test_fc_models`, cleared the same way, so IDs
 * start from 1 in each test.
 */

if (!class_exists('CartShiftFcModelStore')) {
    final class CartShiftFcModelStore
    {
        /**
         * Make FluentCart's model stubs answer query() for this test.
         */
        public static function install(): void
        {
            $GLOBALS['_cartshift_test_fc_model_handler'] = static function (
                string $class,
                string $method,
                array $arguments,
            ): mixed {
                if ($method !== 'query') {
                    throw new BadMethodCallException(sprintf(
                        'The CartShift model fake implements query() only; %s::%s() was called.',
                        $class,
                        $method,
                    ));
                }

                return new CartShiftFcQuery(self::shortName($class));
            };
        }

        /**
         * Record a create() and hand back a row object with an ID, the way an
         * Eloquent model does.
         */
        public static function record(string $model, array $attributes): object
        {
            $nextId = (int) ($GLOBALS['_cartshift_test_fc_next_id'] ?? 0) + 1;
            $GLOBALS['_cartshift_test_fc_next_id'] = $nextId;

            $row = CartShiftFcRow::of(array_merge($attributes, ['id' => $nextId]));

            $GLOBALS['_cartshift_test_fc_models'][$model][] = $row;

            return $row;
        }

        /**
         * Put a row where `where(...)->first()` will find it.
         *
         * The counterpart to existing(): that one answers with a single row
         * whatever was asked, which is all most callers here need. This one
         * takes a real row so a test can hand back something with working
         * `save()` — the orphan path reads the product detail, mutates its
         * price range and stock availability, and saves it again.
         */
        public static function seed(string $model, array $attributes): object
        {
            $row = CartShiftFcRow::of($attributes);

            $GLOBALS['_cartshift_test_fc_existing'][$model] = $row;

            return $row;
        }

        /**
         * Every row created for one model, in creation order.
         *
         * @return list<object>
         */
        public static function all(string $model): array
        {
            return array_values($GLOBALS['_cartshift_test_fc_models'][$model] ?? []);
        }

        /**
         * What a `where(...)->first()` on this model should return. Absent means
         * "no such row", which is what an empty FluentCart install looks like.
         */
        public static function existing(string $model): ?object
        {
            $row = $GLOBALS['_cartshift_test_fc_existing'][$model] ?? null;

            return is_object($row) ? $row : null;
        }

        private static function shortName(string $class): string
        {
            $position = strrpos($class, '\\');

            return $position === false ? $class : substr($class, $position + 1);
        }
    }
}

if (!class_exists('CartShiftFcRow')) {
    /**
     * What create() and first() hand back: a row that reads like the stdClass
     * this used to be, and additionally answers save().
     *
     * FluentCart's models are saved as well as created — the orphan path reads
     * the linked product's detail row, recomputes its price range and stock
     * availability from the variants, and saves it — and `stdClass::save()` is
     * a fatal, not a no-op. Dynamic properties so every existing `$row->column`
     * read keeps working unchanged.
     */
    #[AllowDynamicProperties]
    final class CartShiftFcRow
    {
        public static function of(array $attributes): self
        {
            $row = new self();

            foreach ($attributes as $key => $value) {
                $row->{$key} = $value;
            }

            return $row;
        }

        /**
         * Records itself in `_cartshift_test_fc_saved`, so a test can assert
         * that a row was written back and not merely mutated in memory.
         */
        public function save(): bool
        {
            $GLOBALS['_cartshift_test_fc_saved'][] = $this;

            return true;
        }
    }
}

if (!class_exists('CartShiftFcQuery')) {
    final class CartShiftFcQuery
    {
        /** @var list<array{0: string, 1: mixed}> Two-argument where() constraints, in order. */
        private array $wheres = [];

        public function __construct(private readonly string $model) {}

        public function create(array $attributes): object
        {
            return CartShiftFcModelStore::record($this->model, $attributes);
        }

        public function where(mixed ...$args): self
        {
            if (count($args) === 2 && is_string($args[0])) {
                $this->wheres[] = [$args[0], $args[1]];
            }

            return $this;
        }

        /**
         * The seeded row for this model, if it matches every recorded
         * constraint.
         *
         * Constraints are honoured rather than ignored so a SKU probe can be
         * asked about two different SKUs in one test and answer differently —
         * which is the whole of what SkuAllocator does.
         */
        public function first(): ?object
        {
            $row = CartShiftFcModelStore::existing($this->model);

            return $row !== null && $this->matches($row) ? $row : null;
        }

        public function max(string $column): mixed
        {
            return $this->aggregate($column, 'max');
        }

        public function min(string $column): mixed
        {
            return $this->aggregate($column, 'min');
        }

        public function exists(): bool
        {
            return $this->rows() !== [];
        }

        /** @return list<object> Created rows for this model matching every constraint. */
        private function rows(): array
        {
            return array_values(array_filter(
                CartShiftFcModelStore::all($this->model),
                fn (object $row): bool => $this->matches($row),
            ));
        }

        private function aggregate(string $column, string $which): mixed
        {
            $values = [];

            foreach ($this->rows() as $row) {
                if (isset($row->{$column})) {
                    $values[] = $row->{$column};
                }
            }

            if ($values === []) {
                return null;
            }

            return $which === 'max' ? max($values) : min($values);
        }

        private function matches(object $row): bool
        {
            foreach ($this->wheres as [$column, $value]) {
                if (($row->{$column} ?? null) != $value) { // phpcs:ignore -- '900' from SQL vs 900 in PHP
                    return false;
                }
            }

            return true;
        }
    }
}
