<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedCustomerDecisionBuilder;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedCustomerDecisionBuilderTest extends PluginTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_get_results_callback']);
        $GLOBALS['wpdb']->last_error = '';

        parent::tearDown();
    }

    public function testNoTargetCandidateSealsTheCurrentOwnershipAcknowledgementsInTheProposal(): void
    {
        $builder = new GuidedCustomerDecisionBuilder(
            fn (SourceIdentity $identity): RecordEnvelope => match ($identity->canonical()) {
                'shop-alpha:customer:7' => $this->registered(),
                'shop-alpha:customer:91:guest' => $this->guest(),
            },
            static fn (int $orderId): bool => $orderId === 91,
            static fn (array $records): array => [],
        );

        $enriched = $builder->enrich($this->proposal(), $this->selection());
        $questions = $builder->questions($enriched);

        self::assertArrayHasKey('customer_questions', $enriched);
        self::assertSame('owner_review_required', $enriched['status']);
        self::assertSame([], $enriched['blockers']);
        self::assertSame(
            ['attach_exact_same_site_user', 'allow_unlinked_downloads'],
            array_column($questions, 'action'),
        );
        self::assertArrayNotHasKey('choices', $questions[0]);
        self::assertArrayNotHasKey('choices', $questions[1]);
    }

    public function testSameSiteUserCandidateOffersEvidenceBoundReuseOrSeparateCreation(): void
    {
        $snapshot = $this->targetSnapshot(7, 'ada@example.test', 'Ada Lovelace');
        $builder = new GuidedCustomerDecisionBuilder(
            fn (SourceIdentity $identity): RecordEnvelope => match ($identity->canonical()) {
                'shop-alpha:customer:7' => $this->registered(),
                'shop-alpha:customer:91:guest' => $this->guest(),
            },
            static fn (int $orderId): bool => $orderId === 91,
            static fn (array $records): array => [
                'shop-alpha:customer:7' => [[
                    'target_id' => 51,
                    'label' => 'Ada Lovelace (ada@example.test)',
                    'snapshot' => $snapshot,
                ]],
            ],
        );

        $questions = $builder->questions($builder->enrich($this->proposal(), $this->selection()));
        $registered = $questions[0];

        self::assertMatchesRegularExpression('/\Acustomer-[a-f0-9]{12}\z/D', $registered['review_id']);
        self::assertArrayHasKey('choices', $registered);
        self::assertSame(['reuse', 'create'], array_column($registered['choices'], 'action'));
        self::assertSame(51, $registered['choices'][0]['target_id']);
        self::assertSame(
            \CartShift\Support\CanonicalJson::fingerprint($snapshot),
            $registered['choices'][0]['target_fingerprint'],
        );
        self::assertSame('Ada Lovelace (ada@example.test)', $registered['choices'][0]['target_label']);
        self::assertMatchesRegularExpression('/\Achoice-[a-f0-9]{12}\z/D', $registered['choices'][0]['choice_id']);
    }

    public function testUseExistingResolvesToAnExplicitFingerprintBoundTargetDecision(): void
    {
        $snapshot = $this->targetSnapshot(7, 'ada@example.test', 'Ada Lovelace');
        $builder = $this->builderWithCandidates([
            'shop-alpha:customer:7' => [[
                'target_id' => 51,
                'label' => 'Ada Lovelace (ada@example.test)',
                'snapshot' => $snapshot,
            ]],
        ]);
        $enriched = $builder->enrich($this->proposal(), $this->selection());
        $questions = $builder->questions($enriched);
        $reuse = $questions[0]['choices'][0];

        $resolved = $builder->resolve($enriched, [
            ['review_id' => $questions[0]['review_id'], 'choice_id' => $reuse['choice_id']],
            ['identity' => 'shop-alpha:customer:91:guest', 'action' => 'allow_unlinked_downloads'],
        ], 'wp-user:9', '2026-08-13T10:00:00Z');
        $rows = array_column($resolved['decision_set']['decisions'], null, 'identity');

        self::assertSame('reuse_explicit_target_customer', $rows['shop-alpha:customer:7']['action']);
        self::assertSame(51, $rows['shop-alpha:customer:7']['target_id']);
        self::assertSame(
            \CartShift\Support\CanonicalJson::fingerprint($snapshot),
            $rows['shop-alpha:customer:7']['target_fingerprint'],
        );
        self::assertSame($this->registered()->sourceContentDigest, $rows['shop-alpha:customer:7']['source_fingerprint']);
    }

    public function testCreateSeparatelyRetainsTheExistingGuestOwnershipDecision(): void
    {
        $builder = $this->builderWithCandidates([
            'shop-alpha:customer:91:guest' => [[
                'target_id' => 88,
                'label' => 'Grace Hopper (grace@example.test)',
                'snapshot' => $this->targetSnapshot(null, 'grace@example.test', 'Grace Hopper'),
            ]],
        ]);
        $enriched = $builder->enrich($this->proposal(), $this->selection());
        $questions = $builder->questions($enriched);
        $create = array_values(array_filter(
            $questions[1]['choices'],
            static fn (array $choice): bool => $choice['action'] === 'create',
        ))[0];

        $resolved = $builder->resolve($enriched, [
            ['identity' => 'shop-alpha:customer:7', 'action' => 'attach_exact_same_site_user'],
            ['review_id' => $questions[1]['review_id'], 'choice_id' => $create['choice_id']],
        ], 'wp-user:9', '2026-08-13T10:00:00Z');
        $rows = array_column($resolved['decision_set']['decisions'], null, 'identity');

        self::assertSame('allow_unlinked_downloads', $rows['shop-alpha:customer:91:guest']['action']);
        self::assertSame(['shop-alpha:order:91'], $rows['shop-alpha:customer:91:guest']['affected_orders']);
        self::assertArrayNotHasKey('target_id', $rows['shop-alpha:customer:91:guest']);
    }

    public function testTargetReadFailureSealsNoQuestionsAndBlocksTheProposal(): void
    {
        $records = [
            'shop-alpha:customer:7' => $this->registered(),
            'shop-alpha:customer:91:guest' => $this->guest(),
        ];
        $builder = new GuidedCustomerDecisionBuilder(
            static function (SourceIdentity $identity) use (&$records): RecordEnvelope {
                return $records[$identity->canonical()];
            },
            static fn (int $orderId): bool => $orderId === 91,
            static function (array $sourceRecords): array {
                throw new \RuntimeException('database unavailable');
            },
        );

        $enriched = $builder->enrich($this->proposal(), $this->selection());

        self::assertSame('blocked', $enriched['status']);
        self::assertSame([], $enriched['customer_questions']);
        self::assertSame('customer_target_read_failed', $enriched['blockers'][2]['code']);
    }

    public function testLoadedCandidatesUseOneQueryAndFingerprintTheCompleteTargetSnapshot(): void
    {
        $queries = 0;
        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query, string $output) use (&$queries): array {
            ++$queries;
            return [[
                'target_id' => 51,
                'customer_user_id' => 7,
                'customer_email' => 'ADA@example.test',
                'customer_first_name' => 'Ada',
                'customer_last_name' => 'Lovelace',
                'customer_status' => 'active',
                'customer_uuid' => 'target-customer-51',
                'customer_created_at' => '2025-01-01 00:00:00',
                'customer_updated_at' => '2025-01-01 00:00:00',
                'address_row_id' => null,
                'address_customer_id' => null,
                'address_is_primary' => null,
                'address_type' => null,
                'address_status' => null,
                'address_label' => null,
                'address_name' => null,
                'address_1' => null,
                'address_2' => null,
                'address_city' => null,
                'address_state' => null,
                'address_phone' => null,
                'address_email' => null,
                'address_postcode' => null,
                'address_country' => null,
                'address_meta' => null,
            ]];
        };
        $records = [
            'shop-alpha:customer:7' => $this->registered(),
            'shop-alpha:customer:91:guest' => $this->guest(),
        ];
        $builder = new GuidedCustomerDecisionBuilder(
            static fn (SourceIdentity $identity): RecordEnvelope => $records[$identity->canonical()],
            static fn (int $orderId): bool => $orderId === 91,
        );

        $questions = $builder->questions($builder->enrich($this->proposal(), $this->selection()));

        self::assertSame(1, $queries);
        self::assertArrayHasKey('choices', $questions[0]);
        self::assertSame(51, $questions[0]['choices'][0]['target_id']);
        self::assertSame(
            \CartShift\Support\CanonicalJson::fingerprint($this->targetSnapshot(7, 'ADA@example.test', 'Ada Lovelace')),
            $questions[0]['choices'][0]['target_fingerprint'],
        );
    }

    public function testMultipleCandidatesListEachExistingCustomerOnce(): void
    {
        $first = [
            'target_id' => 51,
            'label' => 'Ada One (ada@example.test)',
            'snapshot' => $this->targetSnapshot(7, 'ada@example.test', 'Ada One'),
        ];
        $second = [
            'target_id' => 52,
            'label' => 'Ada Two (ada@example.test)',
            'snapshot' => $this->targetSnapshot(null, 'ada@example.test', 'Ada Two'),
        ];
        $builder = $this->builderWithCandidates([
            'shop-alpha:customer:7' => [$second, $first, $first],
        ]);

        $question = $builder->questions($builder->enrich($this->proposal(), $this->selection()))[0];
        $reuseChoices = array_values(array_filter(
            $question['choices'],
            static fn (array $choice): bool => $choice['action'] === 'reuse',
        ));

        self::assertSame([51, 52], array_column($reuseChoices, 'target_id'));
        self::assertSame(['reuse', 'reuse', 'create'], array_column($question['choices'], 'action'));
    }

    public function testChangedTargetSnapshotChangesReviewAndReuseChoiceIdentifiers(): void
    {
        $snapshot = $this->targetSnapshot(7, 'ada@example.test', 'Ada Lovelace');
        $records = [
            'shop-alpha:customer:7' => $this->registered(),
            'shop-alpha:customer:91:guest' => $this->guest(),
        ];
        $builder = new GuidedCustomerDecisionBuilder(
            static fn (SourceIdentity $identity): RecordEnvelope => $records[$identity->canonical()],
            static fn (int $orderId): bool => $orderId === 91,
            static function (array $sourceRecords) use (&$snapshot): array {
                return ['shop-alpha:customer:7' => [[
                    'target_id' => 51,
                    'label' => 'Ada Lovelace (ada@example.test)',
                    'snapshot' => $snapshot,
                ]]];
            },
        );

        $before = $builder->questions($builder->enrich($this->proposal(), $this->selection()))[0];
        $snapshot['customer']['updated_at'] = '2026-08-13 10:05:00';
        $after = $builder->questions($builder->enrich($this->proposal(), $this->selection()))[0];

        self::assertNotSame($before['review_id'], $after['review_id']);
        self::assertNotSame($before['choices'][0]['choice_id'], $after['choices'][0]['choice_id']);
        self::assertNotSame($before['choices'][0]['target_fingerprint'], $after['choices'][0]['target_fingerprint']);
    }

    public function testSealedReuseQuestionRejectsSourceContentChangedAfterReview(): void
    {
        $records = [
            'shop-alpha:customer:7' => $this->registered(),
            'shop-alpha:customer:91:guest' => $this->guest(),
        ];
        $builder = new GuidedCustomerDecisionBuilder(
            static function (SourceIdentity $identity) use (&$records): RecordEnvelope {
                return $records[$identity->canonical()];
            },
            static fn (int $orderId): bool => $orderId === 91,
            fn (array $sourceRecords): array => [
                'shop-alpha:customer:7' => [[
                    'target_id' => 51,
                    'label' => 'Ada Lovelace (ada@example.test)',
                    'snapshot' => $this->targetSnapshot(7, 'ada@example.test', 'Ada Lovelace'),
                ]],
            ],
        );
        $enriched = $builder->enrich($this->proposal(), $this->selection());
        $question = $builder->questions($enriched)[0];
        $records['shop-alpha:customer:7'] = RecordEnvelope::forPayload(
            2,
            new SourceIdentity('shop-alpha', 'customer', '7'),
            [
                'identity' => 'shop-alpha:customer:7',
                'source_user_id' => 7,
                'classification' => 'registered',
                'first_name' => 'Ada Changed',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.test',
            ],
        );

        try {
            $builder->resolve($enriched, [
                ['review_id' => $question['review_id'], 'choice_id' => $question['choices'][0]['choice_id']],
                ['identity' => 'shop-alpha:customer:91:guest', 'action' => 'allow_unlinked_downloads'],
            ], 'wp-user:9', '2026-08-13T10:00:00Z');
            self::fail('A customer choice survived changed source content.');
        } catch (\RuntimeException $exception) {
            self::assertSame('guided_customer_decision_stale', $exception->getMessage());
        }
    }

    public function testSealedOwnershipQuestionRejectsSourceContentChangedAfterReview(): void
    {
        $records = [
            'shop-alpha:customer:7' => $this->registered(),
            'shop-alpha:customer:91:guest' => $this->guest(),
        ];
        $builder = new GuidedCustomerDecisionBuilder(
            static function (SourceIdentity $identity) use (&$records): RecordEnvelope {
                return $records[$identity->canonical()];
            },
            static fn (int $orderId): bool => $orderId === 91,
            static fn (array $sourceRecords): array => [],
        );
        $enriched = $builder->enrich($this->proposal(), $this->selection());
        $records['shop-alpha:customer:7'] = RecordEnvelope::forPayload(
            2,
            new SourceIdentity('shop-alpha', 'customer', '7'),
            [
                'identity' => 'shop-alpha:customer:7',
                'source_user_id' => 7,
                'classification' => 'registered',
                'first_name' => 'Ada Changed',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.test',
            ],
        );

        try {
            $builder->resolve($enriched, [
                ['identity' => 'shop-alpha:customer:7', 'action' => 'attach_exact_same_site_user'],
                ['identity' => 'shop-alpha:customer:91:guest', 'action' => 'allow_unlinked_downloads'],
            ], 'wp-user:9', '2026-08-13T10:00:00Z');
            self::fail('A sealed customer acknowledgement survived changed source content.');
        } catch (\RuntimeException $exception) {
            self::assertSame('guided_customer_decision_stale', $exception->getMessage());
        }
    }

    public function testQuestionsExposeIdentityContextWithoutExposingDecisionFingerprints(): void
    {
        $builder = $this->builder();

        $questions = $builder->questions($this->proposal());

        self::assertSame('shop-alpha:customer:7', $questions[0]['identity']);
        self::assertSame('Ada Lovelace', $questions[0]['name']);
        self::assertSame('ada@example.test', $questions[0]['email']);
        self::assertSame('attach_exact_same_site_user', $questions[0]['action']);
        self::assertArrayNotHasKey('source_fingerprint', $questions[0]);
        self::assertSame('allow_unlinked_downloads', $questions[1]['action']);
        self::assertTrue($questions[1]['has_downloads']);
    }

    public function testApprovedCustomersAreMergedUsingCurrentSourceContentDigests(): void
    {
        $registered = $this->registered();
        $guest = $this->guest();
        $builder = $this->builder($registered, $guest);

        $resolved = $builder->resolve($this->proposal(), [
            ['identity' => 'shop-alpha:customer:7', 'action' => 'attach_exact_same_site_user'],
            ['identity' => 'shop-alpha:customer:91:guest', 'action' => 'allow_unlinked_downloads'],
        ], 'wp-user:1', '2026-08-12T12:00:00Z');
        $rows = array_column($resolved['decision_set']['decisions'], null, 'identity');

        self::assertSame('owner_review_required', $resolved['status']);
        self::assertSame([], $resolved['blockers']);
        self::assertSame($registered->sourceContentDigest, $rows['shop-alpha:customer:7']['source_fingerprint']);
        self::assertSame(7, $rows['shop-alpha:customer:7']['user_id']);
        self::assertSame($guest->sourceContentDigest, $rows['shop-alpha:customer:91:guest']['source_fingerprint']);
        self::assertSame(['shop-alpha:order:91'], $rows['shop-alpha:customer:91:guest']['affected_orders']);
        self::assertSame(['shop-alpha:order:91'], $rows['shop-alpha:customer:91:guest']['downloadable_orders']);
        self::assertSame(1, $rows['shop-alpha:customer:91:guest']['downloadable_order_count']);
    }

    public function testGuestReviewChangesWhenDownloadAccessChanges(): void
    {
        $downloadable = true;
        $records = [
            'shop-alpha:customer:7' => $this->registered(),
            'shop-alpha:customer:91:guest' => $this->guest(),
        ];
        $builder = new GuidedCustomerDecisionBuilder(
            static fn (SourceIdentity $identity): RecordEnvelope => $records[$identity->canonical()],
            static function () use (&$downloadable): bool {
                return $downloadable;
            },
        );

        $before = $builder->questions($this->proposal())[1];
        $downloadable = false;
        $after = $builder->questions($this->proposal())[1];

        self::assertTrue($before['has_downloads']);
        self::assertFalse($after['has_downloads']);
        self::assertNotSame($before['review_id'], $after['review_id']);
    }

    public function testEveryCustomerMustBeAnsweredAndUnrelatedBlockersRemainBlocked(): void
    {
        $builder = $this->builder();

        try {
            $builder->resolve($this->proposal(), [
                ['identity' => 'shop-alpha:customer:7', 'action' => 'attach_exact_same_site_user'],
            ], 'wp-user:1', '2026-08-12T12:00:00Z');
            self::fail('A partial customer review was accepted.');
        } catch (\RuntimeException $failure) {
            self::assertSame('guided_customer_decisions_incomplete', $failure->getMessage());
        }

        $proposal = $this->proposal();
        $proposal['blockers'][] = ['code' => 'existing_record_decision_stale', 'identity' => 'shop-alpha:order:12'];
        $resolved = $builder->resolve($proposal, [
            ['identity' => 'shop-alpha:customer:7', 'action' => 'attach_exact_same_site_user'],
            ['identity' => 'shop-alpha:customer:91:guest', 'action' => 'allow_unlinked_downloads'],
        ], 'wp-user:1', '2026-08-12T12:00:00Z');

        self::assertSame('blocked', $resolved['status']);
        self::assertSame('existing_record_decision_stale', $resolved['blockers'][0]['code']);
    }

    private function builder(?RecordEnvelope $registered = null, ?RecordEnvelope $guest = null): GuidedCustomerDecisionBuilder
    {
        $records = [
            'shop-alpha:customer:7' => $registered ?? $this->registered(),
            'shop-alpha:customer:91:guest' => $guest ?? $this->guest(),
        ];

        return new GuidedCustomerDecisionBuilder(
            static fn (SourceIdentity $identity): RecordEnvelope => $records[$identity->canonical()],
            static fn (int $orderId): bool => $orderId === 91,
        );
    }

    /** @param array<string,list<array<string,mixed>>> $candidates */
    private function builderWithCandidates(array $candidates): GuidedCustomerDecisionBuilder
    {
        $records = [
            'shop-alpha:customer:7' => $this->registered(),
            'shop-alpha:customer:91:guest' => $this->guest(),
        ];

        return new GuidedCustomerDecisionBuilder(
            static fn (SourceIdentity $identity): RecordEnvelope => $records[$identity->canonical()],
            static fn (int $orderId): bool => $orderId === 91,
            static fn (array $sourceRecords): array => $candidates,
        );
    }

    /** @return array<string, mixed> */
    private function proposal(): array
    {
        return [
            'status' => 'blocked',
            'blockers' => [
                ['code' => 'customer_ownership_decision_requires_owner', 'identity' => 'shop-alpha:customer:7'],
                ['code' => 'customer_ownership_decision_requires_owner', 'identity' => 'shop-alpha:customer:91:guest'],
            ],
            'proposal_counts' => ['records' => 0, 'total' => 0],
            'decision_set' => ['decisions' => []],
        ];
    }

    private function selection(): TransferSelection
    {
        return new TransferSelection(
            'shop-alpha',
            SelectionClause::none(),
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::all(),
        );
    }

    /** @return array{customer:array<string,mixed>,addresses:list<array<string,mixed>>} */
    private function targetSnapshot(?int $userId, string $email, string $name): array
    {
        [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, '');

        return [
            'customer' => [
                'user_id' => $userId,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'status' => 'active',
                'uuid' => 'target-customer-51',
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
            'addresses' => [],
        ];
    }

    private function registered(): RecordEnvelope
    {
        return RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'customer', '7'), [
            'identity' => 'shop-alpha:customer:7',
            'source_user_id' => 7,
            'classification' => 'registered',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
        ]);
    }

    private function guest(): RecordEnvelope
    {
        return RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'customer', '91:guest'), [
            'identity' => 'shop-alpha:customer:91:guest',
            'source_user_id' => null,
            'classification' => 'guest',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.test',
            'provenance' => ['source_order_id' => 91],
        ]);
    }
}
