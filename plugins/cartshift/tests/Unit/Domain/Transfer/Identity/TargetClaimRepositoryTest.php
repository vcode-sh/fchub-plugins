<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\Identity\LinkDecision;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\SharedLinkRepository;
use CartShift\Domain\Transfer\Identity\TargetAlreadyClaimed;
use CartShift\Domain\Transfer\Identity\TargetClaimRepository;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class TargetClaimRepositoryTest extends PluginTestCase
{
    /** @var list<array<string, mixed>> */
    private array $claims = [];
    /** @var list<array<string, mixed>> */
    private array $links = [];

    protected function setUp(): void
    {
        parent::setUp();
        $self = $this;
        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use ($self): int|false {
            if (str_ends_with($table, 'cartshift_target_claims')) {
                foreach ($self->claims as $claim) {
                    if ($claim['entity_type'] === $data['entity_type'] && $claim['target_id'] === $data['target_id']) {
                        return false;
                    }
                }
                $self->claims[] = $data;
                return 1;
            }

            if (str_ends_with($table, 'cartshift_shared_links')) {
                foreach ($self->links as $link) {
                    if (
                        $link['source_key'] === $data['source_key']
                        && $link['entity_type'] === $data['entity_type']
                        && $link['source_id'] === $data['source_id']
                    ) {
                        return false;
                    }
                }
                $self->links[] = $data;
                return 1;
            }

            return 1;
        };
        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query, string $output) use ($self): array {
            $rows = str_contains($query, 'target_claims') ? $self->claims : $self->links;

            foreach ($rows as $row) {
                if (str_contains($query, "source_key = '" . $row['source_key'] . "'")
                    || (isset($row['target_id']) && str_contains($query, 'target_id = ' . $row['target_id']))) {
                    return [$output === ARRAY_A ? $row : (object) $row];
                }
            }

            return [];
        };
    }

    public function testExclusiveClaimRequiresAnActiveTransaction(): void
    {
        $this->expectExceptionMessage('target_claim_requires_transaction');

        (new TargetClaimRepository())->claimOrThrow(...$this->claimArguments('lapka-web'));
    }

    public function testTwoSourcesRacingForOneOrderTargetHaveOneWinner(): void
    {
        DatabaseTransaction::begin();
        $repository = new TargetClaimRepository();
        $first = $repository->claimOrThrow(...$this->claimArguments('lapka-web'));

        try {
            $repository->claimOrThrow(...$this->claimArguments('lapka-klub'));
            self::fail('A second source claimed the same exclusive target.');
        } catch (TargetAlreadyClaimed $exception) {
            self::assertSame('target_already_claimed', $exception->getMessage());
            self::assertCount(1, $this->claims);
            self::assertSame('lapka-web', $first->identity->sourceKey);
        } finally {
            DatabaseTransaction::rollback();
        }
    }

    public function testExactClaimRetryIsIdempotentButChangedRunOrFingerprintConflicts(): void
    {
        DatabaseTransaction::begin();
        $repository = new TargetClaimRepository();
        $first = $repository->claimOrThrow(...$this->claimArguments('lapka-web'));
        $retry = $repository->claimOrThrow(...$this->claimArguments('lapka-web'));
        self::assertTrue($first->isCompatibleWith($retry));

        $arguments = $this->claimArguments('lapka-web');
        $arguments['runId'] = 'run-2';

        try {
            $repository->claimOrThrow(...$arguments);
            self::fail('Changed claim ownership was accepted.');
        } catch (TargetAlreadyClaimed) {
            self::addToAssertionCount(1);
        } finally {
            DatabaseTransaction::rollback();
        }
    }

    public function testReviewedProductLinksMayShareATargetButOrdersCannotConstructADecision(): void
    {
        DatabaseTransaction::begin();
        $repository = new SharedLinkRepository();
        $web = $this->decision('lapka-web', 'product', '42');
        $club = $this->decision('lapka-klub', 'product', '84');
        $repository->storeOrThrow($web);
        $repository->storeOrThrow($club);
        self::assertCount(2, $this->links);
        self::assertSame(900, $this->links[0]['target_id']);
        self::assertSame(900, $this->links[1]['target_id']);

        try {
            $this->decision('lapka-web', 'order', '42');
            self::fail('An order shared-link decision was constructed.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        } finally {
            DatabaseTransaction::rollback();
        }
    }

    /** @return array<string, mixed> */
    private function claimArguments(string $sourceKey): array
    {
        return [
            'identity' => new SourceIdentity($sourceKey, 'order', '42'),
            'targetId' => 900,
            'runId' => 'run-1',
            'sourceFingerprint' => str_repeat('a', 64),
            'targetFingerprint' => str_repeat('b', 64),
            'state' => MapState::Claimed,
        ];
    }

    private function decision(string $sourceKey, string $entity, string $sourceId): LinkDecision
    {
        $source = new SourceIdentity($sourceKey, $entity, $sourceId);
        $sourceFingerprint = str_repeat('a', 64);
        $targetFingerprint = str_repeat('b', 64);
        $approvedAt = '2026-08-10T12:00:00Z';

        return new LinkDecision(
            $source,
            $sourceFingerprint,
            900,
            $targetFingerprint,
            LinkDecision::fingerprint($source, $sourceFingerprint, 900, $targetFingerprint, $approvedAt),
            $approvedAt,
        );
    }
}
