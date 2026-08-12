<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedProductDecisionBuilder;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedProductDecisionBuilderTest extends PluginTestCase
{
    public function testStrongCollisionOffersUseExistingOrSkipButNeverOverwriteOrDuplicate(): void
    {
        $record = $this->productRecord('Store membership', 'MEMBERSHIP');
        $builder = $this->builder($record, 'Store membership', 'MEMBERSHIP');

        $proposal = $builder->enrich($this->proposal($record), $this->selection());
        $questions = $builder->questions($proposal);

        self::assertCount(1, $questions);
        self::assertSame('Store membership', $questions[0]['product_name']);
        self::assertSame(['link', 'skip'], array_column($questions[0]['choices'], 'action'));
        self::assertNotContains('overwrite', array_column($questions[0]['choices'], 'action'));
        self::assertNotContains('create', array_column($questions[0]['choices'], 'action'));
        self::assertSame([], $proposal['proposal_decisions']);
        self::assertSame([], $proposal['decision_set']['decisions']);
    }

    public function testApprovedLinkIsBoundToTheExactSourceTargetAndVariationEvidence(): void
    {
        $record = $this->productRecord('Store membership', 'MEMBERSHIP');
        $builder = $this->builder($record, 'Store membership', 'MEMBERSHIP');
        $proposal = $builder->enrich($this->proposal($record), $this->selection());
        $question = $builder->questions($proposal)[0];
        $link = array_values(array_filter(
            $question['choices'],
            static fn (array $choice): bool => $choice['action'] === 'link',
        ))[0];

        $resolved = $builder->resolve(
            $proposal,
            [['review_id' => $question['review_id'], 'choice_id' => $link['choice_id']]],
            'wp-user:9',
            '2026-08-12T21:00:00Z',
        );
        $row = $resolved['decision_set']['decisions'][0];

        self::assertSame('link_existing_product', $row['action']);
        self::assertSame(501, $row['target_product_id']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $row['target_fingerprint']);
        self::assertSame('site-alpha:product:10:variation:11', $row['variation_links'][0]['source_variation']);
        self::assertSame(901, $row['variation_links'][0]['target_variation_id']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $row['variation_links'][0]['source_fingerprint']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $row['variation_links'][0]['target_fingerprint']);
        self::assertSame('wp-user:9', $row['operator']);
        self::assertSame('2026-08-12T21:00:00Z', $row['decided_at']);
    }

    public function testSkipIsUnavailableWhileOrdersStillDependOnTheProduct(): void
    {
        $record = $this->productRecord('Store membership', 'MEMBERSHIP');
        $builder = $this->builder($record, 'Store membership', 'MEMBERSHIP', 3);

        $question = $builder->questions($builder->enrich($this->proposal($record), $this->selection()))[0];

        self::assertNotContains('skip', array_column($question['choices'], 'action'));
        self::assertSame(3, $question['dependent_orders']);
    }

    public function testUnrelatedTargetCatalogueDoesNotCreatePointlessOwnerQuestions(): void
    {
        $record = $this->productRecord('Store membership', 'MEMBERSHIP');
        $builder = $this->builder($record, 'Completely different product', 'OTHER-SKU');

        $proposal = $builder->enrich($this->proposal($record), $this->selection());

        self::assertSame([], $proposal['product_questions']);
        self::assertSame('activate_catalogue', $proposal['proposal_decisions'][0]['action']);
        self::assertSame('activate_catalogue', $proposal['decision_set']['decisions'][0]['action']);
    }

    public function testStrongButIncompatibleMatchStopsInsteadOfDuplicatingOrBreakingOrders(): void
    {
        $record = $this->productRecord('Store membership', 'MEMBERSHIP');
        $target = [
            'product' => ['post_title' => 'Store membership', 'post_status' => 'publish'],
            'detail' => ['variation_type' => 'simple'],
            'variations' => [[
                'id' => 901,
                'post_id' => 501,
                'variation_title' => 'Monthly',
                'sku' => 'MEMBERSHIP-MONTHLY',
                'item_price' => 2500,
            ], [
                'id' => 902,
                'post_id' => 501,
                'variation_title' => 'Annual',
                'sku' => 'MEMBERSHIP-ANNUAL',
                'item_price' => 25000,
            ]],
            'taxonomies' => [],
            'taxonomy_rows' => [],
            'media' => [],
            'downloads' => [],
        ];
        $builder = new GuidedProductDecisionBuilder(
            static fn (): iterable => [$record],
            static fn (): array => [[
                'id' => 501,
                'name' => 'Store membership',
                'sku' => 'MEMBERSHIP',
                'price' => 2500.0,
                'variation_count' => 2,
                'snapshot' => $target,
            ]],
            static fn (): array => ['orders' => 3, 'subscriptions' => 0],
        );

        try {
            $proposal = $builder->enrich($this->proposal($record), $this->selection());
        } catch (\RuntimeException $exception) {
            self::fail('An incompatible strong match escaped as a technical error.');
        }

        self::assertSame('blocked', $proposal['status']);
        self::assertSame([], $proposal['product_questions']);
        self::assertSame('Store membership', $proposal['blockers'][0]['product_name']);
        self::assertSame([], $proposal['proposal_decisions']);
        self::assertSame([], $proposal['decision_set']['decisions']);
        self::assertSame(0, $proposal['proposal_counts']['records']);
        self::assertSame(1, $proposal['proposal_counts']['product_blockers']);
    }

    public function testEqualVariationCountsCannotTurnUnrelatedVariantsIntoAValidLink(): void
    {
        $record = $this->productRecordWithVariations('Store membership', 'MEMBERSHIP', [
            ['id' => '11', 'sku' => 'WOO-MONTHLY', 'name' => 'Monthly'],
            ['id' => '12', 'sku' => 'WOO-ANNUAL', 'name' => 'Annual'],
        ]);
        $builder = new GuidedProductDecisionBuilder(
            static fn (): iterable => [$record],
            fn (): array => $this->targetWithVariations('Store membership', 'MEMBERSHIP', [
                ['id' => 901, 'sku' => 'FC-RED', 'name' => 'Red'],
                ['id' => 902, 'sku' => 'FC-BLUE', 'name' => 'Blue'],
            ]),
            static fn (): array => ['orders' => 3, 'subscriptions' => 0],
        );

        $proposal = $builder->enrich($this->proposal($record), $this->selection());

        self::assertSame('blocked', $proposal['status']);
        self::assertSame([], $proposal['product_questions']);
        self::assertSame([], $proposal['decision_set']['decisions']);
    }

    public function testUnusableStrongMatchCannotForceAWeakerProductLink(): void
    {
        $record = $this->productRecord('Store membership', 'MEMBERSHIP');
        $strong = $this->targetWithVariations('Store membership', 'MEMBERSHIP', [
            ['id' => 901, 'sku' => 'WRONG-ONE', 'name' => 'Monthly'],
            ['id' => 902, 'sku' => 'WRONG-TWO', 'name' => 'Annual'],
        ])[0];
        $weak = $this->targetWithVariations('Store membership', 'OTHER-SKU', [
            ['id' => 903, 'sku' => 'MEMBERSHIP', 'name' => 'Default'],
        ])[0];
        $weak['id'] = 502;
        $weak['price'] = 999.0;
        $weak['snapshot']['variations'][0]['post_id'] = 502;
        $builder = new GuidedProductDecisionBuilder(
            static fn (): iterable => [$record],
            static fn (): array => [$strong, $weak],
            static fn (): array => ['orders' => 3, 'subscriptions' => 0],
        );

        $proposal = $builder->enrich($this->proposal($record), $this->selection());

        self::assertSame('blocked', $proposal['status']);
        self::assertSame([], $proposal['product_questions']);
        self::assertSame([], $proposal['decision_set']['decisions']);
    }

    public function testExistingCatalogueReadFailureCannotFallBackToCreatingDuplicates(): void
    {
        $GLOBALS['_cartshift_test_get_col_callback'] = static function (): array {
            $GLOBALS['wpdb']->last_error = 'Database read failed';
            return [];
        };
        $method = new \ReflectionMethod(GuidedProductDecisionBuilder::class, 'loadedTargetProducts');

        $this->expectExceptionMessage('guided_product_target_read_failed');
        $method->invoke(null);
    }

    public function testDependencyReadFailureCannotOfferAnUnsafeSkip(): void
    {
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $context): string =>
            str_contains($context, 'woocommerce_order_itemmeta') ? 'Database read failed' : '';
        $record = $this->productRecord('Store membership', 'MEMBERSHIP');
        $builder = new GuidedProductDecisionBuilder(
            static fn (): iterable => [$record],
            fn (): array => $this->target('Store membership', 'MEMBERSHIP'),
        );

        $this->expectExceptionMessage('guided_product_dependency_read_failed');
        $builder->enrich($this->proposal($record), $this->selection());
    }

    private function builder(
        RecordEnvelope $record,
        string $targetName,
        string $targetSku,
        int $dependentOrders = 0,
    ): GuidedProductDecisionBuilder {
        return new GuidedProductDecisionBuilder(
            static fn (TransferSelection $selection): iterable => [$record],
            fn (): array => $this->target($targetName, $targetSku),
            static fn (SourceIdentity $identity): array => [
                'orders' => $dependentOrders,
                'subscriptions' => 0,
            ],
        );
    }

    /** @return list<array<string,mixed>> */
    private function target(string $targetName, string $targetSku): array
    {
        $snapshot = [
            'product' => ['post_title' => $targetName, 'post_status' => 'publish'],
            'detail' => ['variation_type' => 'simple'],
            'variations' => [[
                'id' => 901,
                'post_id' => 501,
                'variation_title' => 'Default',
                'sku' => $targetSku,
                'item_price' => 2500,
                'payment_type' => 'onetime',
            ]],
            'taxonomies' => [],
            'taxonomy_rows' => [],
            'media' => [],
            'downloads' => [],
        ];

        return [[
                'id' => 501,
                'name' => $targetName,
                'sku' => $targetSku,
                'price' => 2500.0,
                'variation_count' => 1,
                'snapshot' => $snapshot,
            ]];
    }

    private function selection(): TransferSelection
    {
        return new TransferSelection(
            'site-alpha',
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
        );
    }

    private function productRecord(string $name, string $sku): RecordEnvelope
    {
        return RecordEnvelope::forPayload(2, new SourceIdentity('site-alpha', 'product', '10'), [
            'identity' => 'site-alpha:product:10',
            'name' => $name,
            'sku' => $sku,
            'status' => 'publish',
            'variations' => [[
                'identity' => 'site-alpha:product:10:variation:11',
                'sku' => $sku,
                'attribute_assignments' => [],
                'price' => ['active_price' => 2500],
            ]],
        ]);
    }

    /** @param list<array{id:string,sku:string,name:string}> $variations */
    private function productRecordWithVariations(string $name, string $sku, array $variations): RecordEnvelope
    {
        return RecordEnvelope::forPayload(2, new SourceIdentity('site-alpha', 'product', '10'), [
            'identity' => 'site-alpha:product:10',
            'name' => $name,
            'sku' => $sku,
            'status' => 'publish',
            'variations' => array_map(static fn (array $variation): array => [
                'identity' => 'site-alpha:product:10:variation:' . $variation['id'],
                'sku' => $variation['sku'],
                'attribute_assignments' => [['value' => $variation['name']]],
                'price' => ['active_price' => 2500],
            ], $variations),
        ]);
    }

    /** @param list<array{id:int,sku:string,name:string}> $variations @return list<array<string,mixed>> */
    private function targetWithVariations(string $name, string $sku, array $variations): array
    {
        $snapshot = [
            'product' => ['post_title' => $name, 'post_status' => 'publish'],
            'detail' => ['variation_type' => count($variations) === 1 ? 'simple' : 'variable'],
            'variations' => array_map(static fn (array $variation): array => [
                'id' => $variation['id'],
                'post_id' => 501,
                'variation_title' => $variation['name'],
                'sku' => $variation['sku'],
                'item_price' => 2500,
                'payment_type' => 'onetime',
            ], $variations),
            'taxonomies' => [],
            'taxonomy_rows' => [],
            'media' => [],
            'downloads' => [],
        ];

        return [[
            'id' => 501,
            'name' => $name,
            'sku' => $sku,
            'price' => 2500.0,
            'variation_count' => count($variations),
            'snapshot' => $snapshot,
        ]];
    }

    /** @return array<string,mixed> */
    private function proposal(RecordEnvelope $record): array
    {
        $row = [
            'identity' => $record->identity->canonical(),
            'scope' => 'record',
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => $record->sourceContentDigest,
            'operator' => 'wp-user:1',
            'reason' => 'Proposed from exact source evidence.',
            'decided_at' => '2026-08-12T20:00:00Z',
        ];

        return [
            'status' => 'owner_review_required',
            'blockers' => [],
            'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
            'proposal_decisions' => [$row],
            'proposal_counts' => ['records' => 1, 'retained' => 0, 'total' => 1],
            'decision_set_fingerprint' => TransferDecisionSet::fromArray([$row])->fingerprint(),
            'decision_set' => ['decisions' => [$row]],
        ];
    }
}
