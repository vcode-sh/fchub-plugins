<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedCustomerDecisionBuilder;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedCustomerDecisionBuilderTest extends PluginTestCase
{
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
