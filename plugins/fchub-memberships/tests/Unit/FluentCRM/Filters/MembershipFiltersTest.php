<?php

declare(strict_types=1);

namespace {
    if (!function_exists('fluentCrmDb')) {
        function fluentCrmDb(): object
        {
            return new class {
                public function raw(mixed $value): string
                {
                    return 'RAW(' . (string) $value . ')';
                }
            };
        }
    }
}

namespace FChubMemberships\Tests\Unit\FluentCRM\Filters {

    use FChubMemberships\FluentCRM\Filters\MembershipFilters;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use PHPUnit\Framework\Attributes\DataProvider;

    final class MembershipFiltersTest extends PluginTestCase
    {
        private const EXPECTED_TABLE = 'fchub_membership_grants as fchub_grants';

        public function test_registers_all_six_advanced_filters_and_the_runtime_callback(): void
        {
            MembershipFilters::register();

            self::assertSame(
                [[MembershipFilters::class, 'addFilterOptions']],
                $GLOBALS['_fchub_test_filters']['fluentcrm_advanced_filter_options'] ?? []
            );

            $groups = apply_filters('fluentcrm_advanced_filter_options', []);

            self::assertSame([
                'fchub_has_membership',
                'fchub_membership_status',
                'fchub_days_until_expiry',
                'fchub_renewal_count',
                'fchub_member_duration',
                'fchub_in_trial',
            ], array_column($groups['fchub_memberships']['children'], 'value'));
            self::assertCount(
                1,
                $GLOBALS['_fchub_test_actions']['fluentcrm_contacts_filter_fchub_memberships'] ?? []
            );
        }

        /**
         * @param list<string> $operators
         */
        #[DataProvider('filterCases')]
        public function test_filter_uses_the_unprefixed_aliased_grants_table_for_every_supported_operator(
            string $property,
            array $operators,
            mixed $value
        ): void {
            MembershipFilters::register();
            $callback = $GLOBALS['_fchub_test_actions']['fluentcrm_contacts_filter_fchub_memberships'][0];

            foreach ($operators as $operator) {
                $query = new RecordingMembershipFilterQuery();

                $result = $callback($query, [[
                    'property' => $property,
                    'operator' => $operator,
                    'value' => $value,
                ]]);

                self::assertSame($query, $result, "{$property} with {$operator} must remain chainable");
                self::assertCount(1, $query->subqueries, "{$property} with {$operator} must add one subquery");

                $subquery = $query->subqueries[0];
                self::assertSame(
                    self::EXPECTED_TABLE,
                    $subquery->fromCalls[0] ?? null,
                    "{$property} with {$operator} must pass an unprefixed table to FluentCRM's builder"
                );

                $sql = $query->toSql();
                self::assertStringNotContainsString('wp_wp_', $sql);
                self::assertStringContainsString('fchub_grants.user_id', $sql);

                $this->assertFilterOperations($property, $operator, $subquery, $query);
            }
        }

        /**
         * @return array<string, array{string, list<string>, mixed}>
         */
        public static function filterCases(): array
        {
            $numericOperators = ['=', '!=', '<>', '>', '<', '>=', '<='];

            return [
                'membership plan' => ['fchub_has_membership', ['exist', 'not_exist'], ['5', '8']],
                'membership status' => ['fchub_membership_status', ['exist', 'not_exist'], 'paused'],
                'days until expiry' => ['fchub_days_until_expiry', $numericOperators, 7],
                'renewal count' => ['fchub_renewal_count', $numericOperators, 2],
                'member duration' => ['fchub_member_duration', $numericOperators, 30],
                'trial state' => ['fchub_in_trial', ['exist', 'not_exist'], ''],
            ];
        }

        #[DataProvider('numericFilterProperties')]
        public function test_numeric_filter_rejects_an_unsupported_operator(string $property): void
        {
            MembershipFilters::register();
            $callback = $GLOBALS['_fchub_test_actions']['fluentcrm_contacts_filter_fchub_memberships'][0];
            $query = new RecordingMembershipFilterQuery();

            $result = $callback($query, [[
                'property' => $property,
                'operator' => 'LIKE',
                'value' => 3,
            ]]);

            self::assertSame($query, $result);
            self::assertSame([], $query->subqueries);
        }

        /** @return array<string, array{string}> */
        public static function numericFilterProperties(): array
        {
            return [
                'days until expiry' => ['fchub_days_until_expiry'],
                'renewal count' => ['fchub_renewal_count'],
                'member duration' => ['fchub_member_duration'],
            ];
        }

        private function assertFilterOperations(
            string $property,
            string $operator,
            RecordingMembershipFilterQuery $subquery,
            RecordingMembershipFilterQuery $query
        ): void {
            $expectedExistence = in_array($property, [
                'fchub_has_membership',
                'fchub_membership_status',
                'fchub_in_trial',
            ], true) && $operator === 'not_exist'
                ? 'NOT EXISTS'
                : 'EXISTS';

            self::assertSame([[$expectedExistence, []]], $query->recordedOperations());

            $common = [
                ['SELECT', ['RAW(1)']],
                ['WHERE_COLUMN', ['fchub_grants.user_id', 'fc_subscribers.user_id']],
            ];

            $expected = match ($property) {
                'fchub_has_membership' => [
                    ...$common,
                    ['WHERE', ['fchub_grants.status', 'active']],
                    ['WHERE_IN', ['fchub_grants.plan_id', [5, 8]]],
                ],
                'fchub_membership_status' => [
                    ...$common,
                    ['WHERE', ['fchub_grants.status', 'paused']],
                ],
                'fchub_days_until_expiry' => [
                    ...$common,
                    ['WHERE', ['fchub_grants.status', 'active']],
                    ['WHERE_NOT_NULL', ['fchub_grants.expires_at']],
                    ['WHERE_RAW', [
                        "DATEDIFF(fchub_grants.expires_at, NOW()) {$operator} ?",
                        [7],
                    ]],
                ],
                'fchub_renewal_count' => [
                    ...$common,
                    ['WHERE', ['fchub_grants.status', 'active']],
                    ['WHERE', ['fchub_grants.renewal_count', $operator, 2]],
                ],
                'fchub_member_duration' => [
                    ...$common,
                    ['WHERE_RAW', [
                        "DATEDIFF(NOW(), fchub_grants.created_at) {$operator} ?",
                        [30],
                    ]],
                ],
                'fchub_in_trial' => [
                    ...$common,
                    ['WHERE', ['fchub_grants.status', 'active']],
                    ['WHERE_NOT_NULL', ['fchub_grants.trial_ends_at']],
                    ['WHERE_RAW', ['fchub_grants.trial_ends_at > NOW()', []]],
                ],
                default => self::fail("Unexpected filter property: {$property}"),
            };

            self::assertSame($expected, $subquery->recordedOperations());
        }
    }

    final class RecordingMembershipFilterQuery
    {
        /** @var list<string> */
        public array $fromCalls = [];

        /** @var list<self> */
        public array $subqueries = [];

        /** @var list<array{string, list<mixed>}> */
        private array $operations = [];

        public function whereExists(callable $callback): self
        {
            return $this->recordSubquery('EXISTS', $callback);
        }

        public function whereNotExists(callable $callback): self
        {
            return $this->recordSubquery('NOT EXISTS', $callback);
        }

        public function select(mixed $expression): self
        {
            $this->operations[] = ['SELECT', [$expression]];
            return $this;
        }

        public function from(string $table): self
        {
            $this->fromCalls[] = $table;
            return $this;
        }

        public function whereColumn(string $first, string $second): self
        {
            $this->operations[] = ['WHERE_COLUMN', [$first, $second]];
            return $this;
        }

        public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
        {
            $arguments = func_num_args() === 2
                ? [$column, $operatorOrValue]
                : [$column, $operatorOrValue, $value];
            $this->operations[] = ['WHERE', $arguments];
            return $this;
        }

        /** @param list<int> $values */
        public function whereIn(string $column, array $values): self
        {
            $this->operations[] = ['WHERE_IN', [$column, $values]];
            return $this;
        }

        public function whereNotNull(string $column): self
        {
            $this->operations[] = ['WHERE_NOT_NULL', [$column]];
            return $this;
        }

        /** @param list<int> $bindings */
        public function whereRaw(string $sql, array $bindings = []): self
        {
            $this->operations[] = ['WHERE_RAW', [$sql, $bindings]];
            return $this;
        }

        public function toSql(): string
        {
            $parts = [];

            foreach ($this->fromCalls as $from) {
                $parts[] = 'FROM ' . $this->prefixTable($from);
            }

            foreach ($this->operations as [$operation, $arguments]) {
                $parts[] = $operation . ' ' . json_encode($arguments, JSON_THROW_ON_ERROR);
            }

            foreach ($this->subqueries as $subquery) {
                $parts[] = '(' . $subquery->toSql() . ')';
            }

            return implode(' ', $parts);
        }

        /** @return list<array{string, list<mixed>}> */
        public function recordedOperations(): array
        {
            return $this->operations;
        }

        private function recordSubquery(string $operation, callable $callback): self
        {
            $subquery = new self();
            $callback($subquery);
            $this->operations[] = [$operation, []];
            $this->subqueries[] = $subquery;
            return $this;
        }

        private function prefixTable(string $from): string
        {
            $segments = preg_split('/\s+as\s+/i', $from);
            $table = $segments[0];
            $alias = $segments[1] ?? null;

            return 'wp_' . $table . ($alias ? ' as ' . $alias : '');
        }
    }
}
