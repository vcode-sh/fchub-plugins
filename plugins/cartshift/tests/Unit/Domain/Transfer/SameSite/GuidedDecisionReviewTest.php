<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedCustomerDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedCollisionDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedDecisionReview;
use CartShift\Domain\Transfer\SameSite\GuidedProductDecisionBuilder;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedDecisionReviewTest extends PluginTestCase
{
    public function testPresentationShowsEveryNewDecisionWithoutTechnicalIdentityOrEvidence(): void
    {
        $review = new GuidedDecisionReview($this->customerBuilder());

        $presentation = $review->presentation($this->proposal());

        self::assertMatchesRegularExpression('/\Adecision-[a-f0-9]{12}\z/D', $presentation['items'][0]['review_id']);
        self::assertMatchesRegularExpression('/\Adecision-[a-f0-9]{12}\z/D', $presentation['items'][1]['review_id']);
        self::assertMatchesRegularExpression('/\Acustomer-[a-f0-9]{12}\z/D', $presentation['items'][2]['review_id']);
        self::assertSame('Order 42', $presentation['items'][0]['title']);
        self::assertStringContainsString('Reconfirm because the source evidence changed', $presentation['items'][0]['summary']);
        self::assertSame('Product 10', $presentation['items'][1]['title']);
        self::assertStringContainsString('visible in FluentCart', $presentation['items'][1]['summary']);
        self::assertStringContainsString('Reconfirm because the source evidence changed', $presentation['items'][1]['summary']);
        self::assertSame('Ada Lovelace', $presentation['items'][2]['title']);
        self::assertStringContainsString('same WordPress account', $presentation['items'][2]['summary']);
        self::assertSame(['orders', 'products', 'customers'], array_column($presentation['items'], 'group'));

        $encoded = json_encode($presentation, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('shop-alpha', $encoded);
        self::assertStringNotContainsString('source_fingerprint', $encoded);
        self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $encoded);
    }

    public function testPresentationTurnsAnOrderIdentityIntoAHumanCommerceStory(): void
    {
        $proposal = $this->proposal();
        $proposal['review_context'] = [
            'shop-alpha:order:42' => [
                'kind' => 'order',
                'customer_name' => 'Ada Lovelace',
                'customer_email' => 'ada@example.test',
                'created_utc' => '2025-01-20T11:12:13Z',
                'status' => 'completed',
                'currency' => 'PLN',
                'gross_total' => 2400,
                'items' => [['name' => 'Store membership', 'sku' => 'MEMBERSHIP', 'quantity' => 1]],
                'item_count' => 1,
            ],
            'shop-alpha:product:10' => [
                'kind' => 'product',
                'name' => 'Store membership',
                'sku' => 'MEMBERSHIP',
                'status' => 'publish',
                'product_type' => 'simple',
                'dependent_orders' => 1,
                'dependent_subscriptions' => 0,
            ],
        ];

        $presentation = (new GuidedDecisionReview($this->customerBuilder()))->presentation($proposal);
        $order = array_values(array_filter(
            $presentation['items'],
            static fn (array $item): bool => $item['group'] === 'orders',
        ))[0];

        self::assertSame('Ada Lovelace', $order['title']);
        self::assertSame('safe_plan', $order['section']);
        self::assertSame([
            'kind' => 'order',
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'created_utc' => '2025-01-20T11:12:13Z',
            'status' => 'completed',
            'currency' => 'PLN',
            'gross_total' => 2400,
            'items' => [['name' => 'Store membership', 'sku' => 'MEMBERSHIP', 'quantity' => 1]],
            'item_count' => 1,
        ], $order['story']);

        $encoded = json_encode($presentation, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('shop-alpha', $encoded);
        self::assertStringNotContainsString('source_fingerprint', $encoded);
    }

    public function testPresentationSeparatesDeterministicOutcomesFromGenuineChoices(): void
    {
        $proposal = $this->proposal();
        $proposal['product_questions'] = [
            $this->productQuestion('product-create', '11', [['choice_id' => 'create-11', 'action' => 'create']]),
            $this->productQuestion('product-skip', '12', [['choice_id' => 'skip-12', 'action' => 'skip']]),
            $this->productQuestion('product-choice', '13', [
                ['choice_id' => 'create-13', 'action' => 'create'],
                ['choice_id' => 'skip-13', 'action' => 'skip'],
            ]),
        ];
        $proposal['customer_questions'] = [[
            'review_id' => 'customer-safe',
            'identity' => 'shop-alpha:customer:7',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'classification' => 'registered',
            'action' => 'attach_exact_same_site_user',
            'has_downloads' => false,
        ], [
            'review_id' => 'customer-choice',
            'identity' => 'shop-alpha:customer:8',
            'name' => 'Grace Hopper',
            'email' => 'grace@example.test',
            'classification' => 'registered',
            'choices' => [[
                'choice_id' => 'reuse-8',
                'action' => 'reuse',
                'target_label' => 'Grace Hopper (grace@example.test)',
            ], [
                'choice_id' => 'create-8',
                'action' => 'create',
            ]],
        ]];
        $proposal['collision_questions'] = [[
            'review_id' => 'collision-42',
            'identity' => 'shop-alpha:order:42',
            'record_kind' => 'order',
            'source_fingerprint' => str_repeat('c', 64),
            'target_id' => 901,
            'target_fingerprint' => str_repeat('d', 64),
            'closure' => [[
                'identity' => 'shop-alpha:order:42',
                'source_fingerprint' => str_repeat('c', 64),
            ]],
            'dependent_orders' => 0,
            'dependent_subscriptions' => 0,
            'target_story' => [
                'kind' => 'order',
                'customer_name' => 'Ada Lovelace',
                'created_utc' => '2025-01-20 11:12:13',
                'status' => 'completed',
                'currency' => 'PLN',
                'gross_total' => 2400,
                'items' => [['name' => 'Store membership', 'quantity' => 1]],
                'item_count' => 1,
            ],
            'choices' => [['choice_id' => 'skip-42', 'action' => 'skip']],
        ]];

        $items = (new GuidedDecisionReview($this->customerBuilder(), $this->productBuilder()))
            ->presentation($proposal)['items'];
        $byReview = array_column($items, null, 'review_id');

        self::assertSame('safe_plan', $byReview['product-create']['section']);
        self::assertSame('create-11', $byReview['product-create']['recommended_choice_id']);
        self::assertSame('stays_behind', $byReview['product-skip']['section']);
        self::assertSame('skip-12', $byReview['product-skip']['recommended_choice_id']);
        self::assertSame('choices', $byReview['product-choice']['section']);
        self::assertSame('create-13', $byReview['product-choice']['recommended_choice_id']);
        self::assertSame('safe_plan', $byReview['customer-safe']['section']);
        self::assertSame('choices', $byReview['customer-choice']['section']);
        self::assertSame('reuse-8', $byReview['customer-choice']['recommended_choice_id']);
        self::assertSame('stays_behind', $byReview['collision-42']['section']);
        self::assertSame('skip-42', $byReview['collision-42']['recommended_choice_id']);
        self::assertStringContainsString('uses the migration identity', $byReview['collision-42']['summary']);
        self::assertStringNotContainsString('already has this record', $byReview['collision-42']['summary']);
        self::assertSame('Ada Lovelace', $byReview['collision-42']['target_story']['customer_name']);
        self::assertStringNotContainsString('901', json_encode($byReview['collision-42']['target_story'], JSON_THROW_ON_ERROR));
    }

    public function testPresentationExplainsTheDeliberatelyNarrowSourceScopeAndApprovalKeepsItForTheReport(): void
    {
        $proposal = $this->proposal();
        $proposal['source_scope'] = [
            'included_subscriptions' => 13,
            'omitted_subscriptions' => 17,
            'included_registered_customers' => 361,
            'omitted_wordpress_accounts' => 322,
            'guest_order_profiles' => 7,
            'unique_guest_emails' => 2,
            'unlinked_order_profiles' => 0,
        ];
        $proposal['migration_exceptions'] = [[
            'kind' => 'source_scope',
            ...$proposal['source_scope'],
        ]];
        $expectedScope = $proposal['source_scope'];
        ksort($proposal['source_scope']); // Persisted guided state is canonical JSON and sorts object keys.
        $review = new GuidedDecisionReview($this->customerBuilder());

        $presentation = $review->presentation($proposal);

        self::assertSame($expectedScope, $presentation['source_scope']);
        self::assertStringNotContainsString('shop-alpha', json_encode($presentation['source_scope'], JSON_THROW_ON_ERROR));

        $approved = $review->approve(
            $proposal,
            array_column($presentation['items'], 'review_id'),
            'wp-user:9',
            '2026-08-12T12:05:00Z',
        );

        self::assertSame('source_scope', $approved['migration_exceptions'][0]['kind']);
        self::assertSame(17, $approved['migration_exceptions'][0]['omitted_subscriptions']);
    }

    public function testPresentationExplainsSourceAnomalySkipsWithoutInternalCodes(): void
    {
        $proposal = $this->proposal();
        $skip = [
            'identity' => 'shop-alpha:product:1:lookup:stale',
            'scope' => 'audit_finding',
            'finding_code' => 'product_lookup_stale',
            'action' => 'excluded_by_policy',
            'source_fingerprint' => str_repeat('e', 64),
            'operator' => 'owner',
            'reason' => 'Exact source anomaly requires an owner-reviewed skip.',
            'decided_at' => '2026-08-12T12:00:00Z',
        ];
        $proposal['proposal_decisions'][] = $skip;
        $proposal['decision_set']['decisions'][] = $skip;
        $recordSkip = $skip;
        $recordSkip['identity'] = 'shop-alpha:product:77';
        $recordSkip['scope'] = 'record';
        unset($recordSkip['finding_code']);
        $proposal['proposal_decisions'][] = $recordSkip;
        $proposal['decision_set']['decisions'][] = $recordSkip;

        $presentation = (new GuidedDecisionReview($this->customerBuilder()))->presentation($proposal);
        $item = array_values(array_filter(
            $presentation['items'],
            static fn (array $candidate): bool => $candidate['title'] === 'Product 1',
        ))[0];

        self::assertStringContainsString('Skip this product', $item['summary']);
        self::assertStringNotContainsString('product_lookup_stale', json_encode($item, JSON_THROW_ON_ERROR));
        self::assertNotEmpty(array_filter(
            $presentation['items'],
            static fn (array $candidate): bool => $candidate['title'] === 'Product 77'
                && str_contains($candidate['summary'], 'Leave this record in WooCommerce'),
        ));
    }

    public function testEveryPresentedItemMustBeApprovedBeforeCustomerRowsAreResolved(): void
    {
        $review = new GuidedDecisionReview($this->customerBuilder());
        $reviewIds = array_column($review->presentation($this->proposal())['items'], 'review_id');

        try {
            $review->approve(
                $this->proposal(),
                array_slice($reviewIds, 0, 2),
                'wp-user:1',
                '2026-08-12T12:00:00Z',
            );
            self::fail('An unseen customer decision was accepted.');
        } catch (\RuntimeException $failure) {
            self::assertSame('guided_decision_review_incomplete', $failure->getMessage());
        }

        $resolved = $review->approve(
            $this->proposal(),
            $reviewIds,
            'wp-user:9',
            '2026-08-12T12:05:00Z',
        );
        $rows = array_column($resolved['decision_set']['decisions'], null, 'identity');

        self::assertSame('owner_review_required', $resolved['status']);
        self::assertSame([], $resolved['blockers']);
        self::assertSame('attach_exact_same_site_user', $rows['shop-alpha:customer:7']['action']);
        self::assertSame('wp-user:9', $rows['shop-alpha:order:42']['operator']);
        self::assertSame('wp-user:9', $rows['shop-alpha:product:10']['operator']);
        self::assertSame('wp-user:9', $rows['shop-alpha:customer:7']['operator']);
        self::assertSame('2026-08-12T12:05:00Z', $rows['shop-alpha:order:42']['decided_at']);
        self::assertSame('2026-08-12T12:05:00Z', $rows['shop-alpha:product:10']['decided_at']);
        self::assertSame('2026-08-12T12:05:00Z', $rows['shop-alpha:customer:7']['decided_at']);
        self::assertCount(3, $rows);
    }

    public function testApprovalRestampsOnlyRowsThatWereActuallyReviewed(): void
    {
        $proposal = $this->proposal();
        $retained = $proposal['proposal_decisions'][1];
        $retained['identity'] = 'shop-alpha:product:99';
        $retained['operator'] = 'wp-user:2';
        $retained['decided_at'] = '2026-08-10T09:00:00Z';
        $proposal['decision_set']['decisions'][] = $retained;
        $review = new GuidedDecisionReview($this->customerBuilder());
        $ids = array_column($review->presentation($proposal)['items'], 'review_id');

        $resolved = $review->approve(
            $proposal,
            $ids,
            'wp-user:9',
            '2026-08-12T12:05:00Z',
        );
        $rows = array_column($resolved['decision_set']['decisions'], null, 'identity');

        self::assertSame(
            CanonicalJson::encode($retained),
            CanonicalJson::encode($rows['shop-alpha:product:99']),
        );
        self::assertSame('wp-user:9', $rows['shop-alpha:product:10']['operator']);
    }

    public function testProductConflictPresentsPlainChoicesAndRequiresTheExactAnswer(): void
    {
        $proposal = $this->proposal();
        $productRow = $proposal['proposal_decisions'][1];
        $proposal['proposal_decisions'] = [$proposal['proposal_decisions'][0]];
        $proposal['decision_set']['decisions'] = [$proposal['decision_set']['decisions'][0]];
        $proposal['product_questions'] = [[
            'review_id' => 'product-0123456789ab',
            'identity' => 'shop-alpha:product:10',
            'product_name' => 'Store membership',
            'source_fingerprint' => str_repeat('b', 64),
            'dependent_orders' => 0,
            'dependent_subscriptions' => 0,
            'original_decision' => $productRow,
            'choices' => [[
                'choice_id' => 'choice-111111111111',
                'action' => 'create',
            ], [
                'choice_id' => 'choice-222222222222',
                'action' => 'skip',
            ]],
        ]];
        $review = new GuidedDecisionReview($this->customerBuilder(), $this->productBuilder());

        $presentation = $review->presentation($proposal);
        $product = array_values(array_filter(
            $presentation['items'],
            static fn (array $item): bool => $item['kind'] === 'product_conflict',
        ))[0];

        self::assertSame('Store membership', $product['title']);
        self::assertSame(['Create a separate product', 'Skip this product'], array_column($product['choices'], 'label'));
        self::assertStringNotContainsString('shop-alpha', json_encode($product, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('overwrite', strtolower(json_encode($product, JSON_THROW_ON_ERROR)));

        $reviewIds = array_column($presentation['items'], 'review_id');
        $resolved = $review->approve(
            $proposal,
            $reviewIds,
            'wp-user:9',
            '2026-08-12T21:00:00Z',
            [['review_id' => $product['review_id'], 'choice_id' => 'choice-111111111111']],
        );
        $rows = array_column($resolved['decision_set']['decisions'], null, 'identity');

        self::assertSame('activate_catalogue', $rows['shop-alpha:product:10']['action']);
        self::assertSame('wp-user:9', $rows['shop-alpha:product:10']['operator']);
    }

    public function testCustomerReuseAndCollisionSkipShareTheSamePlainChoiceReview(): void
    {
        $proposal = $this->proposal();
        $proposal['blockers'] = [];
        $proposal['status'] = 'owner_review_required';
        $proposal['product_questions'] = [];
        $proposal['customer_questions'] = [[
            'review_id' => 'customer-0123456789ab',
            'identity' => 'shop-alpha:customer:7',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'classification' => 'registered',
            'action' => 'attach_exact_same_site_user',
            'has_downloads' => false,
            'source_fingerprint' => RecordEnvelope::forPayload(
                2,
                new SourceIdentity('shop-alpha', 'customer', '7'),
                [
                    'identity' => 'shop-alpha:customer:7',
                    'source_user_id' => 7,
                    'classification' => 'registered',
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'email' => 'ada@example.test',
                ],
            )->sourceContentDigest,
            'choices' => [[
                'choice_id' => 'choice-333333333333',
                'action' => 'reuse',
                'target_id' => 71,
                'target_fingerprint' => str_repeat('c', 64),
                'target_label' => 'Ada Lovelace (ada@example.test)',
            ], [
                'choice_id' => 'choice-444444444444',
                'action' => 'create',
            ]],
        ]];
        $proposal['collision_questions'] = [[
            'review_id' => 'collision-0123456789ab',
            'identity' => 'shop-alpha:order:88',
            'record_kind' => 'order',
            'source_fingerprint' => str_repeat('d', 64),
            'target_id' => 88,
            'target_fingerprint' => str_repeat('e', 64),
            'closure' => [[
                'identity' => 'shop-alpha:order:88',
                'source_fingerprint' => str_repeat('d', 64),
            ]],
            'dependent_orders' => 0,
            'dependent_subscriptions' => 0,
            'choices' => [[
                'choice_id' => 'skip-555555555555',
                'action' => 'skip',
                'label' => 'Keep the existing FluentCart order and skip this WooCommerce copy',
            ]],
        ]];
        $review = new GuidedDecisionReview(
            $this->customerBuilder(),
            $this->productBuilder(),
            new GuidedCollisionDecisionBuilder(static fn (): iterable => [], static fn (): array => []),
        );

        $presentation = $review->presentation($proposal);
        $customer = array_values(array_filter(
            $presentation['items'],
            static fn (array $item): bool => $item['kind'] === 'customer_match',
        ))[0];
        $collision = array_values(array_filter(
            $presentation['items'],
            static fn (array $item): bool => $item['kind'] === 'record_collision',
        ))[0];

        self::assertSame(['Use existing customer', 'Create a separate customer'], array_column($customer['choices'], 'label'));
        self::assertSame(['Skip this WooCommerce copy'], array_column($collision['choices'], 'label'));
        self::assertStringNotContainsString('target_id', json_encode($presentation, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('overwrite', strtolower(json_encode($presentation, JSON_THROW_ON_ERROR)));

        $resolved = $review->approve(
            $proposal,
            array_column($presentation['items'], 'review_id'),
            'wp-user:9',
            '2026-08-12T21:00:00Z',
            [
                ['review_id' => $customer['review_id'], 'choice_id' => 'choice-333333333333'],
                ['review_id' => $collision['review_id'], 'choice_id' => 'skip-555555555555'],
            ],
        );
        $rows = array_column($resolved['decision_set']['decisions'], null, 'identity');

        self::assertSame('reuse_explicit_target_customer', $rows['shop-alpha:customer:7']['action']);
        self::assertSame('excluded_by_policy', $rows['shop-alpha:order:88']['action']);
    }

    public function testAnIncompatibleProductExplainsItsCascadeSkipWithoutPretendingNoMatchExists(): void
    {
        $proposal = $this->proposal();
        $proposal['product_questions'] = [[
            'review_id' => 'product-0123456789ab',
            'identity' => 'shop-alpha:product:10',
            'product_name' => 'Store membership',
            'source_fingerprint' => str_repeat('b', 64),
            'dependent_orders' => 1,
            'dependent_subscriptions' => 2,
            'closure' => [],
            'original_decision' => $proposal['proposal_decisions'][1],
            'choices' => [[
                'choice_id' => 'choice-222222222222',
                'action' => 'skip',
            ]],
        ]];
        $proposal['proposal_decisions'] = [$proposal['proposal_decisions'][0]];
        $proposal['decision_set']['decisions'] = [$proposal['decision_set']['decisions'][0]];

        $item = array_values(array_filter(
            (new GuidedDecisionReview($this->customerBuilder(), $this->productBuilder()))
                ->presentation($proposal)['items'],
            static fn (array $item): bool => $item['kind'] === 'product_conflict',
        ))[0];

        self::assertStringContainsString('variations cannot be linked safely', $item['summary']);
        self::assertStringContainsString('1 related order and 2 related subscriptions', $item['summary']);
        self::assertStringNotContainsString('No likely', $item['summary']);
    }

    public function testIncompatibleExistingProductExplainsTheSafeStopWithoutInternalCodes(): void
    {
        $proposal = $this->proposal();
        $proposal['status'] = 'blocked';
        $proposal['blockers'] = [[
            'code' => 'product_existing_match_unresolvable',
            'product_name' => 'Store membership',
        ]];
        $proposal['product_questions'] = [];
        $review = new GuidedDecisionReview($this->customerBuilder(), $this->productBuilder());

        $presentation = $review->presentation($proposal);

        self::assertSame([
            'Store membership has a likely FluentCart match, but its variations do not align. '
                . 'CartShift will stop rather than create a duplicate or change the existing product.',
        ], $presentation['blockers']);
        self::assertStringNotContainsString('unresolvable', json_encode($presentation, JSON_THROW_ON_ERROR));
    }

    private function customerBuilder(): GuidedCustomerDecisionBuilder
    {
        $record = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'customer', '7'), [
            'identity' => 'shop-alpha:customer:7',
            'source_user_id' => 7,
            'classification' => 'registered',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
        ]);

        return new GuidedCustomerDecisionBuilder(static fn (): RecordEnvelope => $record);
    }

    private function productBuilder(): GuidedProductDecisionBuilder
    {
        return new GuidedProductDecisionBuilder(
            static fn (): iterable => [],
            static fn (): array => [],
            static fn (): array => ['orders' => 0, 'subscriptions' => 0],
        );
    }

    /** @param list<array<string,string>> $choices @return array<string,mixed> */
    private function productQuestion(string $reviewId, string $sourceId, array $choices): array
    {
        return [
            'review_id' => $reviewId,
            'identity' => 'shop-alpha:product:' . $sourceId,
            'product_name' => 'Product ' . $sourceId,
            'source_fingerprint' => str_repeat($sourceId[0], 64),
            'dependent_orders' => $reviewId === 'product-skip' ? 2 : 0,
            'dependent_subscriptions' => 0,
            'choices' => $choices,
        ];
    }

    /** @return array<string, mixed> */
    private function proposal(): array
    {
        $rows = [
            [
                'identity' => 'shop-alpha:order:42',
                'scope' => 'audit_finding',
                'finding_code' => 'order_note_visibility_decision_required',
                'action' => 'approve_mapping',
                'note_policy' => 'preserve_history_select_canonical',
                'note_count' => 2,
                'customer_visible_note_count' => 0,
                'source_fingerprint' => str_repeat('a', 64),
                'operator' => 'wp-user:1',
                'reason' => 'Proposed from exact read-only source evidence; owner review required.',
                'decided_at' => '2026-08-12T12:00:00Z',
            ],
            [
                'identity' => 'shop-alpha:product:10',
                'scope' => 'record',
                'action' => 'activate_catalogue',
                'target_status' => 'publish',
                'source_fingerprint' => str_repeat('b', 64),
                'operator' => 'wp-user:1',
                'reason' => 'Proposed from the exact materialised source record; owner review required.',
                'decided_at' => '2026-08-12T12:00:00Z',
            ],
        ];

        return [
            'status' => 'blocked',
            'blockers' => [[
                'code' => 'customer_ownership_decision_requires_owner',
                'identity' => 'shop-alpha:customer:7',
            ]],
            'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
            'renewed_audit_decisions' => ['shop-alpha:order:42|order_note_visibility_decision_required'],
            'renewed_record_decisions' => ['shop-alpha:product:10'],
            'proposal_decisions' => $rows,
            'proposal_counts' => ['audit_findings' => 1, 'records' => 1, 'retained' => 0, 'total' => 2],
            'decision_set' => ['decisions' => $rows],
        ];
    }
}
