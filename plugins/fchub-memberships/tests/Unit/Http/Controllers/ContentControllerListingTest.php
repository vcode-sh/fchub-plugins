<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Adapters\Contracts\BatchResourceLabelAdapterInterface;
use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Http\Controllers\ContentController;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ContentControllerListingTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResourceTypeRegistry::reset();
        AccessEvaluator::clearCache();
        ProviderLabelAdapter::$singleCalls = 0;
        ProviderLabelAdapter::$batchCalls = 0;
        ScalarProviderLabelAdapter::$singleCalls = 0;
    }

    protected function tearDown(): void
    {
        ResourceTypeRegistry::reset();
        AccessEvaluator::clearCache();
        parent::tearDown();
    }

    public function test_title_search_filters_the_complete_result_set_before_pagination(): void
    {
        foreach ([
            101 => 'Ordinary Alpha',
            102 => 'Ordinary Beta',
            103 => 'Needle Membership Resource',
        ] as $id => $title) {
            $post = new \WP_Post();
            $post->ID = $id;
            $post->post_type = 'post';
            $post->post_title = $title;
            $GLOBALS['_fchub_test_posts'][$id] = $post;
        }
        $GLOBALS['_fchub_test_post_types'] = ['post'];

        $rows = [
            $this->ruleRow(1, '101'),
            $this->ruleRow(2, '102'),
            $this->ruleRow(3, '103'),
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($rows): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            if (!str_contains($query, 'fchub_membership_protection_rules')) {
                return [];
            }

            return str_contains($query, 'LIMIT 2 OFFSET 0')
                ? array_slice($rows, 0, 2)
                : $rows;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 0;

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'needle',
            'page' => 1,
            'per_page' => 2,
        ]))->get_data();

        self::assertSame(1, $response['total']);
        self::assertSame([3], array_column($response['data'], 'id'));
        self::assertSame('Needle Membership Resource', $response['data'][0]['resource_title']);
    }

    public function test_title_search_paginates_matches_and_reports_empty_results_truthfully(): void
    {
        $rows = [];
        foreach ([201, 202, 203] as $index => $id) {
            $post = new \WP_Post();
            $post->ID = $id;
            $post->post_type = 'post';
            $post->post_title = 'Needle ' . ($index + 1);
            $GLOBALS['_fchub_test_posts'][$id] = $post;
            $rows[] = $this->ruleRow($index + 1, (string) $id);
        }
        $GLOBALS['_fchub_test_post_types'] = ['post'];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($rows): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            return str_contains($query, 'fchub_membership_protection_rules') ? $rows : [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 0;

        $secondPage = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'NEEDLE',
            'page' => 2,
            'per_page' => 2,
        ]))->get_data();
        $empty = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'missing title',
            'page' => 1,
            'per_page' => 2,
        ]))->get_data();

        self::assertSame(3, $secondPage['total']);
        self::assertSame([3], array_column($secondPage['data'], 'id'));
        self::assertSame(0, $empty['total']);
        self::assertSame([], $empty['data']);
    }

    public function test_no_search_keeps_database_pagination_and_unpaginated_total(): void
    {
        $rows = [
            $this->ruleRow(1, '301'),
            $this->ruleRow(2, '302'),
            $this->ruleRow(3, '303'),
        ];
        foreach ($rows as $row) {
            $post = new \WP_Post();
            $post->ID = (int) $row['resource_id'];
            $post->post_type = 'post';
            $post->post_title = 'Resource ' . $row['resource_id'];
            $GLOBALS['_fchub_test_posts'][$post->ID] = $post;
        }
        $GLOBALS['_fchub_test_post_types'] = ['post'];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($rows): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            if (str_contains($query, 'fchub_membership_protection_rules')) {
                return str_contains($query, 'LIMIT 2 OFFSET 0') ? array_slice($rows, 0, 2) : $rows;
            }
            return [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int => str_contains(
            $query,
            'fchub_membership_protection_rules'
        ) ? 3 : 0;

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'page' => 1,
            'per_page' => 2,
        ]))->get_data();

        self::assertSame(3, $response['total']);
        self::assertSame([1, 2], array_column($response['data'], 'id'));
        $listQueries = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'get_results'
                && str_contains((string) $query[1], 'fchub_membership_protection_rules')
                && !str_contains((string) $query[1], 'GROUP BY')
        ));
        self::assertStringContainsString('LIMIT 2 OFFSET 0', (string) $listQueries[0][1]);
    }

    public function test_page_enrichment_batches_plan_names_and_uses_provider_adapter_labels(): void
    {
        ResourceTypeRegistry::getInstance()->register('external_resource', [
            'label' => 'External resource',
            'group' => 'content',
            'provider' => 'example_provider',
            'adapter' => ProviderLabelAdapter::class,
        ]);
        $rows = [];
        for ($id = 1; $id <= 20; $id++) {
            $rows[] = array_merge($this->ruleRow($id, (string) (900 + $id)), [
                'resource_type' => 'external_resource',
                'plan_ids' => '[5]',
            ]);
        }
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $query) use ($rows): array {
            return match (true) {
                str_contains($query, 'GROUP BY resource_type') => [],
                str_contains($query, 'fchub_membership_protection_rules') => $rows,
                str_contains($query, 'fchub_membership_plans') => [$this->planRow()],
                str_contains($query, 'GROUP BY access_rows._resource_key') => [
                    ['_resource_key' => '0', 'member_count' => '4'],
                ],
                default => [],
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int => str_contains(
            $query,
            'fchub_membership_protection_rules'
        ) ? 20 : 0;

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'page' => 1,
            'per_page' => 20,
        ]))->get_data();

        self::assertCount(20, $response['data']);
        self::assertSame('External label 901', $response['data'][0]['resource_title']);
        self::assertSame('Gold', $response['data'][0]['plan_names'][0]);
        self::assertSame(4, $response['data'][0]['member_count']);

        $planBatchReads = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'get_results'
                && str_contains((string) $query[1], 'fchub_membership_plans')
                && str_contains((string) $query[1], 'WHERE id IN')
        ));
        self::assertCount(1, $planBatchReads);
        self::assertStringContainsString('WHERE id IN (5)', (string) $planBatchReads[0][1]);
        self::assertCount(0, array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'get_row'
                && str_contains((string) $query[1], 'fchub_membership_plans')
        ));
    }

    public function test_listing_clamps_per_page_and_rejects_untrusted_label_adapters(): void
    {
        ResourceTypeRegistry::getInstance()->register('unsafe_resource', [
            'label' => 'Unsafe',
            'group' => 'advanced',
            'provider' => 'unsafe_provider',
            'adapter' => InvalidProviderLabelAdapter::class,
        ]);
        $row = array_merge($this->ruleRow(1, '77'), [
            'resource_type' => 'unsafe_resource',
        ]);
        $listQueries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (
            $row,
            &$listQueries
        ): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            if (str_contains($query, 'fchub_membership_protection_rules')) {
                $listQueries[] = $query;
                return [$row];
            }
            return [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int => str_contains(
            $query,
            'fchub_membership_protection_rules'
        ) ? 1 : 0;

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'page' => 1,
            'per_page' => 999,
        ]))->get_data();

        self::assertNotEmpty(array_filter(
            $listQueries,
            static fn(string $query): bool => str_contains($query, 'LIMIT 100 OFFSET 0')
        ));
        self::assertSame('Unsafe #77', $response['data'][0]['resource_title']);
    }

    public function test_title_search_batches_provider_labels_before_matching_and_slicing(): void
    {
        ResourceTypeRegistry::getInstance()->register('external_resource', [
            'label' => 'External resource',
            'group' => 'content',
            'provider' => 'example_provider',
            'adapter' => ProviderLabelAdapter::class,
        ]);
        $rows = [];
        for ($id = 1; $id <= 20; $id++) {
            $rows[] = array_merge($this->ruleRow($id, (string) (900 + $id)), [
                'resource_type' => 'external_resource',
            ]);
        }
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($rows): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            return str_contains($query, 'fchub_membership_protection_rules') ? $rows : [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 0;

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'LABEL 919',
            'page' => 1,
            'per_page' => 10,
        ]))->get_data();

        self::assertSame(1, $response['total']);
        self::assertSame([19], array_column($response['data'], 'id'));
        self::assertSame(1, ProviderLabelAdapter::$batchCalls);
        self::assertSame(0, ProviderLabelAdapter::$singleCalls);
    }

    public function test_mixed_twenty_row_page_has_an_exact_constant_membership_query_budget(): void
    {
        ResourceTypeRegistry::getInstance()->register('external_resource', [
            'label' => 'External resource',
            'group' => 'content',
            'provider' => 'example_provider',
            'adapter' => ProviderLabelAdapter::class,
        ]);
        $GLOBALS['_fchub_test_post_types'] = ['post', 'page'];
        $rows = [];
        for ($index = 0; $index < 20; $index++) {
            $resourceType = match (true) {
                $index < 5 => 'post',
                $index < 10 => 'page',
                $index < 15 => 'external_resource',
                default => 'url_pattern',
            };
            $resourceId = $resourceType === 'url_pattern'
                ? '/members/' . ($index + 1)
                : (string) (1000 + $index);
            $rows[] = array_merge($this->ruleRow($index + 1, $resourceId), [
                'resource_type' => $resourceType,
                'plan_ids' => '[5]',
            ]);

            if (in_array($resourceType, ['post', 'page'], true)) {
                $post = new \WP_Post();
                $post->ID = (int) $resourceId;
                $post->post_type = $resourceType;
                $post->post_title = ucfirst($resourceType) . ' ' . $resourceId;
                $GLOBALS['_fchub_test_posts'][$post->ID] = $post;
            }
        }
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $query) use ($rows): array {
            return match (true) {
                str_contains($query, 'GROUP BY resource_type') => [],
                str_contains($query, 'fchub_membership_protection_rules') => $rows,
                str_contains($query, 'fchub_membership_plans') => [$this->planRow()],
                str_contains($query, 'fchub_membership_plan_rules') => [],
                str_contains($query, 'GROUP BY access_rows._resource_key') => array_map(
                    static fn(int $key): array => [
                        '_resource_key' => (string) $key,
                        'member_count' => '3',
                    ],
                    array_keys($rows)
                ),
                default => [],
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int => str_contains(
            $query,
            'fchub_membership_protection_rules'
        ) ? 20 : 0;

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'page' => 1,
            'per_page' => 20,
        ]))->get_data();

        self::assertCount(20, $response['data']);
        self::assertSame(array_fill(0, 20, 3), array_column($response['data'], 'member_count'));
        self::assertSame(1, ProviderLabelAdapter::$batchCalls);
        self::assertSame(0, ProviderLabelAdapter::$singleCalls);
        self::assertCount(2, $GLOBALS['_fchub_test_get_posts_args']);

        $membershipReads = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => in_array($query[0], ['get_results', 'get_row', 'get_var'], true)
                && str_contains((string) $query[1], 'fchub_membership_')
        ));
        self::assertCount(8, $membershipReads);
        self::assertSame(
            [
                'protection:list',
                'protection:count',
                'plans:find-many',
                'protection:policy-batch',
                'plans:active',
                'plan-rules:batch',
                'grants:count-batch',
                'protection:summary',
            ],
            array_map(static function (array $query): string {
                $sql = (string) $query[1];
                return match (true) {
                    str_contains($sql, 'fchub_membership_protection_rules')
                        && str_contains($sql, 'LIMIT 20 OFFSET 0') => 'protection:list',
                    $query[0] === 'get_var'
                        && str_contains($sql, 'fchub_membership_protection_rules') => 'protection:count',
                    str_contains($sql, 'fchub_membership_plans')
                        && str_contains($sql, 'WHERE id IN') => 'plans:find-many',
                    str_contains($sql, 'fchub_membership_protection_rules')
                        && str_contains($sql, 'ORDER BY created_at DESC') => 'protection:policy-batch',
                    str_contains($sql, 'fchub_membership_plans')
                        && str_contains($sql, "status = 'active'") => 'plans:active',
                    str_contains($sql, 'fchub_membership_plan_rules') => 'plan-rules:batch',
                    str_contains($sql, 'GROUP BY access_rows._resource_key') => 'grants:count-batch',
                    str_contains($sql, 'fchub_membership_protection_rules')
                        && str_contains($sql, 'GROUP BY resource_type') => 'protection:summary',
                    default => 'unexpected',
                };
            }, $membershipReads)
        );
    }

    #[DataProvider('storageFailureProvider')]
    public function test_index_returns_service_unavailable_when_an_admin_storage_read_fails(
        string $failureStage
    ): void {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (
            string $query,
            string $output,
            \wpdb $wpdb
        ) use ($failureStage): array {
            $isList = str_contains($query, 'fchub_membership_protection_rules')
                && str_contains($query, 'ORDER BY created_at DESC');
            $isSummary = str_contains($query, 'fchub_membership_protection_rules')
                && str_contains($query, 'GROUP BY resource_type');
            $wpdb->last_error = ($failureStage === 'all' && $isList)
                || ($failureStage === 'summary' && $isSummary)
                ? 'read failed'
                : '';
            return [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (
            string $query,
            \wpdb $wpdb
        ) use ($failureStage): int {
            $wpdb->last_error = $failureStage === 'count'
                && str_contains($query, 'fchub_membership_protection_rules')
                ? 'read failed'
                : '';
            return 0;
        };

        $response = ContentController::index(new \WP_REST_Request('GET', '/content'));

        self::assertSame(503, $response->get_status());
        self::assertSame([
            'code' => 'fchub_content_storage_unavailable',
            'message' => 'Membership content could not be loaded. Please retry.',
        ], $response->get_data());
    }

    /** @return array<string, array{string}> */
    public static function storageFailureProvider(): array
    {
        return [
            'list failure' => ['all'],
            'count failure' => ['count'],
            'summary failure' => ['summary'],
        ];
    }

    public function test_scalar_only_custom_provider_labels_remain_searchable_with_a_hard_call_bound(): void
    {
        ResourceTypeRegistry::getInstance()->register('scalar_resource', [
            'label' => 'Scalar resource',
            'group' => 'content',
            'provider' => 'scalar_provider',
            'adapter' => ScalarProviderLabelAdapter::class,
        ]);
        $rows = [];
        for ($id = 1; $id <= 100; $id++) {
            $rows[] = array_merge($this->ruleRow($id, (string) $id), [
                'resource_type' => 'scalar_resource',
            ]);
        }
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($rows): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            return str_contains($query, 'fchub_membership_protection_rules') ? $rows : [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 0;

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'Scalar label 100',
            'page' => 1,
            'per_page' => 20,
        ]))->get_data();

        self::assertSame(1, $response['total']);
        self::assertSame([100], array_column($response['data'], 'id'));
        self::assertSame('Scalar label 100', $response['data'][0]['resource_title']);
        self::assertSame(100, ScalarProviderLabelAdapter::$singleCalls);
    }

    public function test_scalar_only_title_search_rejects_candidate_one_hundred_and_one_before_enrichment(): void
    {
        ResourceTypeRegistry::getInstance()->register('scalar_resource', [
            'label' => 'Scalar resource',
            'group' => 'content',
            'provider' => 'scalar_provider',
            'adapter' => ScalarProviderLabelAdapter::class,
        ]);
        $rows = [];
        for ($id = 1; $id <= 101; $id++) {
            $rows[] = array_merge($this->ruleRow($id, (string) $id), [
                'resource_type' => 'scalar_resource',
            ]);
        }
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => str_contains(
            $query,
            'fchub_membership_protection_rules'
        ) ? $rows : [];

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'Scalar label 101',
        ]));

        self::assertSame(422, $response->get_status());
        self::assertSame([
            'code' => 'fchub_content_search_too_broad',
            'message' => 'Content search exceeds 100 candidates for a provider without batch label support. '
                . 'Add a filter and retry.',
        ], $response->get_data());
        self::assertSame(0, ScalarProviderLabelAdapter::$singleCalls);
    }

    public function test_menu_item_batch_labels_match_nav_menu_setup_during_title_search(): void
    {
        $menuItem = new \WP_Post();
        $menuItem->ID = 77;
        $menuItem->post_type = 'nav_menu_item';
        $menuItem->post_title = '';
        $menuItem->title = 'Configured Menu Label';
        $GLOBALS['_fchub_test_posts'][77] = $menuItem;
        $GLOBALS['_fchub_test_posts_by_type']['nav_menu_item'] = [$menuItem];
        $row = array_merge($this->ruleRow(77, '77'), [
            'resource_type' => 'menu_item',
        ]);
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($row): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            return str_contains($query, 'fchub_membership_protection_rules') ? [$row] : [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 0;

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'Configured Menu',
        ]))->get_data();

        self::assertSame(1, $response['total']);
        self::assertSame('Configured Menu Label', $response['data'][0]['resource_title']);
        self::assertCount(1, $GLOBALS['_fchub_test_get_posts_args']);
    }

    public function test_title_search_rejects_more_than_one_thousand_candidates_before_enrichment(): void
    {
        ResourceTypeRegistry::getInstance()->register('external_resource', [
            'label' => 'External resource',
            'group' => 'content',
            'provider' => 'example_provider',
            'adapter' => ProviderLabelAdapter::class,
        ]);
        $rows = [];
        for ($id = 1; $id <= 1001; $id++) {
            $rows[] = array_merge($this->ruleRow($id, (string) $id), [
                'resource_type' => 'external_resource',
            ]);
        }
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => str_contains(
            $query,
            'fchub_membership_protection_rules'
        ) ? $rows : [];

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'label',
        ]));

        self::assertSame(422, $response->get_status());
        self::assertSame([
            'code' => 'fchub_content_search_too_broad',
            'message' => 'Content search exceeds 1,000 candidates. Add a filter and retry.',
        ], $response->get_data());
        self::assertSame(0, ProviderLabelAdapter::$batchCalls);
        self::assertNotEmpty(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'get_results'
                && str_contains((string) $query[1], 'LIMIT 1001 OFFSET 0')
        ));
    }

    public function test_title_search_chunks_batch_provider_label_reads_to_one_hundred_ids(): void
    {
        ResourceTypeRegistry::getInstance()->register('external_resource', [
            'label' => 'External resource',
            'group' => 'content',
            'provider' => 'example_provider',
            'adapter' => ProviderLabelAdapter::class,
        ]);
        $rows = [];
        for ($id = 1; $id <= 250; $id++) {
            $rows[] = array_merge($this->ruleRow($id, (string) $id), [
                'resource_type' => 'external_resource',
            ]);
        }
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($rows): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            return str_contains($query, 'fchub_membership_protection_rules') ? $rows : [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): int => 0;

        $response = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'External label 249',
        ]))->get_data();

        self::assertSame(1, $response['total']);
        self::assertSame([249], array_column($response['data'], 'id'));
        self::assertSame(3, ProviderLabelAdapter::$batchCalls);
    }

    public function test_search_surfaces_provider_label_failure_while_display_listing_falls_back(): void
    {
        ResourceTypeRegistry::getInstance()->register('throwing_resource', [
            'label' => 'Throwing resource',
            'group' => 'content',
            'provider' => 'throwing_provider',
            'adapter' => ThrowingProviderLabelAdapter::class,
        ]);
        $row = array_merge($this->ruleRow(1, '77'), [
            'resource_type' => 'throwing_resource',
        ]);
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($row): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            return str_contains($query, 'fchub_membership_protection_rules') ? [$row] : [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int => str_contains(
            $query,
            'fchub_membership_protection_rules'
        ) ? 1 : 0;

        $searchResponse = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'anything',
        ]));
        $listingResponse = ContentController::index(new \WP_REST_Request('GET', '/content'))->get_data();

        self::assertSame(503, $searchResponse->get_status());
        self::assertSame([
            'code' => 'fchub_content_label_provider_unavailable',
            'message' => 'Provider resource labels could not be loaded. Please retry.',
        ], $searchResponse->get_data());
        self::assertSame('Throwing resource #77', $listingResponse['data'][0]['resource_title']);
    }

    public function test_taxonomy_label_failure_is_strict_for_search_and_safe_for_listing(): void
    {
        $GLOBALS['_fchub_test_taxonomies'] = ['category'];
        $GLOBALS['_fchub_test_get_terms_override'] = static fn(array $args): \WP_Error => new \WP_Error(
            'taxonomy_read_failed',
            'Taxonomy storage unavailable.'
        );
        $row = array_merge($this->ruleRow(1, '5'), [
            'resource_type' => 'category',
        ]);
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($row): array {
            if (str_contains($query, 'GROUP BY resource_type')) {
                return [];
            }
            return str_contains($query, 'fchub_membership_protection_rules') ? [$row] : [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int => str_contains(
            $query,
            'fchub_membership_protection_rules'
        ) ? 1 : 0;

        try {
            $searchResponse = ContentController::index(new \WP_REST_Request('GET', '/content', [
                'search' => 'Gold',
            ]));
            $listingResponse = ContentController::index(new \WP_REST_Request('GET', '/content'));
        } finally {
            unset($GLOBALS['_fchub_test_get_terms_override']);
        }

        self::assertSame(503, $searchResponse->get_status());
        self::assertSame([
            'code' => 'fchub_content_label_provider_unavailable',
            'message' => 'Provider resource labels could not be loaded. Please retry.',
        ], $searchResponse->get_data());
        self::assertSame(200, $listingResponse->get_status());
        self::assertSame('Categories #5', $listingResponse->get_data()['data'][0]['resource_title']);
    }

    /** @return array<string, mixed> */
    private function ruleRow(int $id, string $resourceId): array
    {
        return [
            'id' => $id,
            'resource_type' => 'post',
            'resource_id' => $resourceId,
            'plan_ids' => '[]',
            'protection_mode' => 'explicit',
            'restriction_message' => null,
            'redirect_url' => null,
            'show_teaser' => 'no',
            'meta' => '{}',
            'created_at' => '2026-07-23 10:00:00',
            'updated_at' => '2026-07-23 10:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function planRow(): array
    {
        return [
            'id' => 5,
            'title' => 'Gold',
            'slug' => 'gold',
            'description' => '',
            'status' => 'active',
            'level' => 1,
            'duration_type' => 'lifetime',
            'duration_days' => null,
            'trial_days' => 0,
            'grace_period_days' => 0,
            'includes_plan_ids' => '[]',
            'restriction_message' => null,
            'redirect_url' => null,
            'settings' => '{}',
            'meta' => '{}',
            'scheduled_status' => null,
            'scheduled_at' => null,
            'created_at' => '2026-07-23 10:00:00',
            'updated_at' => '2026-07-23 10:00:00',
        ];
    }
}

final class ProviderLabelAdapter implements AccessAdapterInterface, BatchResourceLabelAdapterInterface
{
    public static int $singleCalls = 0;
    public static int $batchCalls = 0;

    public function supports(string $resourceType): bool
    {
        return $resourceType === 'external_resource';
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => true];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => true];
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        return true;
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        self::$singleCalls++;
        return 'External label ' . $resourceId;
    }

    public function getResourceLabels(string $resourceType, array $resourceIds): array
    {
        self::$batchCalls++;
        $labels = [];
        foreach ($resourceIds as $resourceId) {
            $labels[(string) $resourceId] = 'External label ' . $resourceId;
        }

        return $labels;
    }

    public function searchResources(string $query, string $resourceType, int $limit = 20): array
    {
        return [];
    }

    public function getResourceTypes(): array
    {
        return ['external_resource' => 'External resource'];
    }
}

final class InvalidProviderLabelAdapter
{
    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        return 'Untrusted label';
    }
}

final class ScalarProviderLabelAdapter implements AccessAdapterInterface
{
    public static int $singleCalls = 0;

    public function supports(string $resourceType): bool
    {
        return $resourceType === 'scalar_resource';
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => true];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => true];
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        return true;
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        self::$singleCalls++;
        return 'Scalar label ' . $resourceId;
    }

    public function searchResources(string $query, string $resourceType, int $limit = 20): array
    {
        return [];
    }

    public function getResourceTypes(): array
    {
        return ['scalar_resource' => 'Scalar resource'];
    }
}

final class ThrowingProviderLabelAdapter implements AccessAdapterInterface, BatchResourceLabelAdapterInterface
{
    public function supports(string $resourceType): bool
    {
        return $resourceType === 'throwing_resource';
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => true];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => true];
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        return true;
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        throw new \RuntimeException('Provider unavailable.');
    }

    public function getResourceLabels(string $resourceType, array $resourceIds): array
    {
        throw new \RuntimeException('Provider unavailable.');
    }

    public function searchResources(string $query, string $resourceType, int $limit = 20): array
    {
        return [];
    }

    public function getResourceTypes(): array
    {
        return ['throwing_resource' => 'Throwing resource'];
    }
}
