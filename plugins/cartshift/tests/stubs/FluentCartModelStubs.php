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

            $row = (object) array_merge($attributes, ['id' => $nextId]);

            $GLOBALS['_cartshift_test_fc_models'][$model][] = $row;

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

if (!class_exists('CartShiftFcQuery')) {
    final class CartShiftFcQuery
    {
        public function __construct(private readonly string $model) {}

        public function create(array $attributes): object
        {
            return CartShiftFcModelStore::record($this->model, $attributes);
        }

        public function where(mixed ...$args): self
        {
            return $this;
        }

        public function first(): ?object
        {
            return CartShiftFcModelStore::existing($this->model);
        }
    }
}
