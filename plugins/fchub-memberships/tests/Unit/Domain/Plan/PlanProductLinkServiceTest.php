<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanProductLinkService;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanProductLinkServiceTest extends PluginTestCase
{
    private function plan(array $overrides = []): array
    {
        return array_merge([
            'id' => 5,
            'title' => 'Gold Plan',
            'slug' => 'gold-plan',
            'duration_type' => 'fixed_anchor',
            'duration_days' => 30,
            'grace_period_days' => 5,
            'meta' => [
                'billing_anchor_day' => 12,
                'membership_term' => [
                    'mode' => 'date',
                    'date' => '2026-12-31',
                ],
            ],
        ], $overrides);
    }

    public function test_linked_products_and_search_products_transform_variations(): void
    {
        $planService = new class($this->plan()) extends PlanService {
            public function __construct(private array $plan)
            {
            }

            public function find(int $id): ?array
            {
                return $id === 5 ? $this->plan : null;
            }
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, 'FROM wp_fct_product_meta m') => [[
                    'feed_id' => 70,
                    'product_id' => 200,
                    'meta_value' => json_encode([
                        'plan_id' => 5,
                        'name' => 'Gold Plan - Product',
                        'triggers' => ['order_paid_done'],
                        'enabled' => 'yes',
                    ]),
                    'product_title' => 'Membership Product',
                ]],
                str_contains($query, 'FROM wp_fct_product_variations') => [[
                    'post_id' => 200,
                    'variation_title' => 'Monthly',
                    'item_price' => 4900,
                    'payment_type' => 'monthly',
                ]],
                str_contains($query, 'FROM wp_posts p WHERE') => [[
                    'id' => 200,
                    'title' => 'Membership Product',
                ]],
                default => [],
            };
        };

        $service = new PlanProductLinkService($planService);
        $linked = $service->linkedProducts(5);
        $search = $service->searchProducts('Membership');

        self::assertSame('Membership Product', $linked['data'][0]['product_title']);
        self::assertSame(4900, $linked['data'][0]['price']);
        self::assertSame('monthly', $linked['data'][0]['billing_period']);
        self::assertSame('Membership Product', $search['data'][0]['title']);
        self::assertSame('monthly', $search['data'][0]['payment_type']);
    }

    public function test_link_product_rejects_missing_plan_product_and_duplicates_then_builds_feed_settings(): void
    {
        $planService = new class($this->plan()) extends PlanService {
            public function __construct(private array $plan)
            {
            }

            public function find(int $id): ?array
            {
                return $id === 5 ? $this->plan : null;
            }
        };

        $service = new PlanProductLinkService($planService);

        self::assertSame(404, $service->linkProduct(999, 200)['status']);
        self::assertSame(422, $service->linkProduct(5, 0)['status']);

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => null;
        self::assertSame(404, $service->linkProduct(5, 200)['status']);

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => str_contains($query, 'FROM wp_posts')
            ? ['id' => 200, 'title' => 'Membership Product']
            : null;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => [[
            'id' => 70,
            'meta_value' => json_encode(['plan_id' => 5]),
        ]];
        self::assertSame(422, $service->linkProduct(5, 200)['status']);

        $inserted = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => [];
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$inserted): int {
            $inserted[] = [$table, $data];
            $wpdb->insert_id = 88;
            return 1;
        };

        $result = $service->linkProduct(5, 200);

        self::assertSame(201, $result['status']);
        self::assertSame(88, $result['data']['feed_id']);
        self::assertSame('wp_fct_product_meta', $inserted[0][0]);

        $settings = json_decode($inserted[0][1]['meta_value'], true);
        self::assertSame('anchor_billing', $settings['validity_mode']);
        self::assertSame(12, $settings['billing_anchor_day']);
        self::assertSame('date', $settings['membership_term_mode']);
        self::assertSame('2026-12-31', $settings['membership_term_date']);
    }

    public function test_unlink_product_rejects_wrong_feed_and_deletes_matching_feed(): void
    {
        $planService = new class($this->plan()) extends PlanService {
            public function __construct(private array $plan)
            {
            }

            public function find(int $id): ?array
            {
                return $id === 5 ? $this->plan : null;
            }
        };

        $service = new PlanProductLinkService($planService);

        self::assertSame(404, $service->unlinkProduct(999, 10)['status']);

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => null;
        self::assertSame(404, $service->unlinkProduct(5, 10)['status']);

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => [
            'id' => 10,
            'meta_value' => json_encode(['plan_id' => 99, 'plan_slug' => 'other-plan']),
        ];
        self::assertSame(422, $service->unlinkProduct(5, 10)['status']);

        $deleted = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => [
            'id' => 10,
            'meta_value' => json_encode(['plan_id' => 5, 'plan_slug' => 'gold-plan']),
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static function (string $table, array $where) use (&$deleted): int {
            $deleted[] = [$table, $where];
            return 1;
        };

        $success = $service->unlinkProduct(5, 10);

        self::assertSame('Product unlinked successfully.', $success['message']);
        self::assertSame([['wp_fct_product_meta', ['id' => 10]]], $deleted);
    }
}
