<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedCustomerDecisionBuilder;
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

        $encoded = json_encode($presentation, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('shop-alpha', $encoded);
        self::assertStringNotContainsString('source_fingerprint', $encoded);
        self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $encoded);
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
