<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Audit\WooSourceApi;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedSourceScope;
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedSourceScopeTest extends PluginTestCase
{
    public function testItSelectsCommerceDependenciesInsteadOfEveryWordPressUserAndOmitsEndedSubscriptions(): void
    {
        $scope = GuidedSourceScope::read(
            'shop-alpha',
            true,
            new GuidedScopeWooSourceApi([
                21 => 'active',
                22 => 'on-hold',
                23 => 'pending-cancel',
                24 => 'cancelled',
                25 => 'expired',
                26 => 'switched',
                27 => 'trash',
            ]),
            static fn (): array => [9, 3, 3, 7],
        );

        self::assertSame(SelectionMode::All, $scope->selection->products->mode);
        self::assertSame(SelectionMode::None, $scope->selection->customers->mode);
        self::assertSame(SelectionMode::All, $scope->selection->orders->mode);
        self::assertSame(SelectionMode::Ids, $scope->selection->subscriptions->mode);
        self::assertSame([21, 22, 23], $scope->selection->subscriptions->ids);
        self::assertSame(4, $scope->omittedSubscriptions);
        self::assertSame(3, $scope->wordpressUsers);
    }

    public function testItUsesNoSubscriptionClauseWhenOnlyHistoricalSubscriptionsExist(): void
    {
        $scope = GuidedSourceScope::read(
            'shop-alpha',
            true,
            new GuidedScopeWooSourceApi([31 => 'cancelled', 32 => 'expired']),
            static fn (): array => [],
        );

        self::assertSame(SelectionMode::None, $scope->selection->subscriptions->mode);
        self::assertSame(2, $scope->omittedSubscriptions);
    }

    public function testItsSummaryCountsOnlyCustomersRequiredByTheSelectedClosure(): void
    {
        $scope = GuidedSourceScope::read(
            'shop-alpha',
            true,
            new GuidedScopeWooSourceApi([41 => 'active', 42 => 'expired']),
            static fn (): array => range(1, 8),
        );
        $records = [
            $this->customer('7', 'registered', 'registered@example.test'),
            $this->customer('91:guest', 'guest', 'guest@example.test'),
            $this->customer('92:guest', 'guest', 'guest@example.test'),
            $this->customer('93:guest', 'guest', 'another@example.test'),
            RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'order', '99'), [
                'customer' => null,
                'dependencies' => [],
            ]),
        ];

        self::assertSame([
            'included_subscriptions' => 1,
            'omitted_subscriptions' => 1,
            'included_registered_customers' => 1,
            'omitted_wordpress_accounts' => 7,
            'guest_order_profiles' => 3,
            'unique_guest_emails' => 2,
            'unlinked_order_profiles' => 1,
        ], $scope->summary($records));
    }

    private function customer(string $sourceId, string $classification, string $email): RecordEnvelope
    {
        return RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'customer', $sourceId), [
            'classification' => $classification,
            'normalized_email_digest' => hash('sha256', $email),
            'dependencies' => [],
        ]);
    }
}

final class GuidedScopeWooSourceApi implements WooSourceApi
{
    /** @param array<int,string> $subscriptions */
    public function __construct(private readonly array $subscriptions) {}

    public function productCensusPage(int $page, int $limit): array { return []; }
    public function semanticProductIds(): array { return []; }
    public function lookupProductIds(): array { return []; }
    public function product(int $id): ?array { return null; }
    public function orderCensusPage(int $page, int $limit): array { return []; }
    public function order(int $id): ?array { return null; }
    public function subscriptionCensusPage(int $page, int $limit): array
    {
        return $page === 1 ? array_keys($this->subscriptions) : [];
    }
    public function subscription(int $id): ?array
    {
        return isset($this->subscriptions[$id])
            ? ['id' => $id, 'status' => $this->subscriptions[$id]]
            : null;
    }
}
