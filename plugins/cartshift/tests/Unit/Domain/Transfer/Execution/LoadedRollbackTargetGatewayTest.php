<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\FilesystemSagaRepository;
use CartShift\Domain\Transfer\Execution\LoadedRollbackTargetGateway;
use CartShift\Domain\Transfer\Execution\RollbackPlan;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\Product\ProductTargetGateway;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedRollbackTargetGatewayTest extends PluginTestCase
{
    private string $root;
    private object $originalDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDatabase = $GLOBALS['wpdb'];
        $this->root = sys_get_temp_dir() . '/cartshift-rollback-recovery-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            if (is_dir($file)) {
                foreach (glob($file . '/*') ?: [] as $child) unlink($child);
                rmdir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($this->root);
        $GLOBALS['wpdb'] = $this->originalDatabase;
        parent::tearDown();
    }

    public function testCommittedRecoveryRefusesToCleanFilesWhileTheTargetStillExists(): void
    {
        $GLOBALS['wpdb'] = new RollbackRecoveryDatabase([1, str_repeat('b', 64), 1]);
        $gateway = $this->gateway(true);

        $this->expectExceptionMessage('rollback_committed_state_unproven:shop-alpha:product:41');
        $gateway->completeCommittedRollback($this->plan());
    }

    public function testCommittedRecoveryRequiresTheExactTombstonedMap(): void
    {
        $GLOBALS['wpdb'] = new RollbackRecoveryDatabase([1, null]);
        $gateway = $this->gateway(false);

        $this->expectExceptionMessage('rollback_committed_state_unproven:shop-alpha:product:41');
        $gateway->completeCommittedRollback($this->plan());
    }

    public function testCommittedRecoveryAcceptsOnlyAbsentTargetAndExactJournalAndMapEvidence(): void
    {
        $database = new RollbackRecoveryDatabase([1, str_repeat('b', 64), 1]);
        $GLOBALS['wpdb'] = $database;
        $gateway = $this->gateway(false);

        $gateway->completeCommittedRollback($this->plan());

        self::assertSame(3, $database->reads);
    }

    public function testDeletingSecondProductDoesNotDeleteMediaOrTaxonomyOwnedByFirstProductReceipt(): void
    {
        $snapshot = [
            'product' => ['post_title' => 'Second'],
            'detail' => ['post_id' => 902],
            'variations' => [],
            'taxonomies' => [501],
            'taxonomy_rows' => [['term_id' => 501, 'taxonomy' => 'product-categories', 'name' => 'Training', 'slug' => 'training', 'description' => '', 'parent' => 0, 'count' => 2]],
            'media' => [['source_identity' => 'shop-alpha:media_asset:701', 'id' => 701, 'sha256' => str_repeat('7', 64)]],
            'downloads' => [],
        ];
        $sourceMap = [
            'shop-alpha:product:42' => 902,
            'shop-alpha:taxonomy_term:10:product-cat' => 501,
            'shop-alpha:media_asset:701' => 701,
        ];
        $after = (new ProductTargetFingerprint())->fingerprint($snapshot, $sourceMap);
        $receipt = new TransferReceipt(
            'run-rollback-22', 'product', 'shop-alpha:product:42', 1, str_repeat('a', 64),
            'created', ['primary' => 902] + $sourceMap, null, $after, 2,
            '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );
        $database = new RollbackDeleteDatabase(str_repeat('1', 64));
        $GLOBALS['wpdb'] = $database;
        $gateway = new LoadedRollbackTargetGateway(
            'shop-alpha',
            new FilesystemSagaRepository($this->root),
            new RollbackDeleteProductGateway($snapshot),
        );

        $gateway->delete($receipt);

        self::assertContains(902, $database->deletedPostIds);
        self::assertNotContains(701, $database->deletedPostIds, 'Shared attachment was treated as owned by the second receipt.');
        self::assertSame([], $database->deletedTermIds, 'Shared taxonomy term was treated as owned by the second receipt.');
    }

    private function gateway(bool $exists): LoadedRollbackTargetGateway
    {
        return new LoadedRollbackTargetGateway(
            'shop-alpha',
            new FilesystemSagaRepository($this->root),
            new RollbackRecoveryProductGateway($exists),
        );
    }

    private function plan(): RollbackPlan
    {
        $receipt = new TransferReceipt(
            'run-rollback-22', 'product', 'shop-alpha:product:41', 1, str_repeat('a', 64),
            'created', ['primary' => 901, 'shop-alpha:product:41' => 901], null, str_repeat('b', 64), 1,
            '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );
        return new RollbackPlan(
            $receipt->runId,
            1,
            [['source_identity' => $receipt->sourceIdentity, 'receipt' => $receipt]],
            [],
            true,
        );
    }
}

final class RollbackRecoveryDatabase extends \wpdb
{
    public int $reads = 0;
    /** @param list<int|string|null> $results */
    public function __construct(private array $results) {}
    public function get_var(string $query): string|int|float|null
    {
        return $this->results[$this->reads++] ?? null;
    }
}

final class RollbackRecoveryProductGateway implements ProductTargetGateway
{
    public function __construct(private bool $existsValue) {}
    public function createTaxonomyTerm(array $plan, ?int $parentTargetId): int { throw new \LogicException(); }
    public function createDraftProduct(array $fields): int { throw new \LogicException(); }
    public function createProductDetail(int $productId, array $fields): int { throw new \LogicException(); }
    public function createVariation(int $productId, array $fields): int { throw new \LogicException(); }
    public function finishProductDetail(int $productId, int $defaultVariationId, int $minPrice, int $maxPrice): void { throw new \LogicException(); }
    public function assignTaxonomies(int $productId, array $targetTermIds): void { throw new \LogicException(); }
    public function attachMedia(int $productId, array $variationIds, array $stagedMedia): array { throw new \LogicException(); }
    public function createDownload(int $productId, array $variationIds, array $fields): int { throw new \LogicException(); }
    public function exists(int $productId): bool { return $this->existsValue; }
    public function snapshot(int $productId): array { throw new \LogicException(); }
    public function behaviour(int $productId, array $variationIds): array { throw new \LogicException(); }
}

final class RollbackDeleteProductGateway implements ProductTargetGateway
{
    public function __construct(private array $snapshotValue) {}
    public function createTaxonomyTerm(array $plan, ?int $parentTargetId): int { throw new \LogicException(); }
    public function createDraftProduct(array $fields): int { throw new \LogicException(); }
    public function createProductDetail(int $productId, array $fields): int { throw new \LogicException(); }
    public function createVariation(int $productId, array $fields): int { throw new \LogicException(); }
    public function finishProductDetail(int $productId, int $defaultVariationId, int $minPrice, int $maxPrice): void { throw new \LogicException(); }
    public function assignTaxonomies(int $productId, array $targetTermIds): void { throw new \LogicException(); }
    public function attachMedia(int $productId, array $variationIds, array $stagedMedia): array { throw new \LogicException(); }
    public function createDownload(int $productId, array $variationIds, array $fields): int { throw new \LogicException(); }
    public function exists(int $productId): bool { return true; }
    public function snapshot(int $productId): array { return $this->snapshotValue; }
    public function behaviour(int $productId, array $variationIds): array { throw new \LogicException(); }
}

final class RollbackDeleteDatabase extends \wpdb
{
    /** @var list<int> */
    public array $deletedPostIds = [];
    /** @var list<int> */
    public array $deletedTermIds = [];
    public function __construct(private string $ownerFingerprint) {}
    public function get_var(string $query): string|int|float|null
    {
        if (str_contains($query, 'SELECT term_taxonomy_id')) return 1501;
        if (str_contains($query, 'SELECT COUNT(*) FROM wp_term_relationships')) return 1;
        return null;
    }
    public function get_results(string $query, string $output = OBJECT): array
    {
        if (str_contains($query, 'cartshift_id_map')) {
            return [(object) ['created_by_migration' => 1, 'target_fingerprint' => $this->ownerFingerprint]];
        }
        if (str_contains($query, 'cartshift_transfer_records')) {
            return [(object) ['after_hash' => $this->ownerFingerprint]];
        }
        return [];
    }
    public function get_col(string $query): array { return []; }
    public function delete(string $table, array $where, ?array $whereFormat = null): int|false
    {
        if ($table === $this->posts && isset($where['ID'])) $this->deletedPostIds[] = (int) $where['ID'];
        if ($table === $this->terms && isset($where['term_id'])) $this->deletedTermIds[] = (int) $where['term_id'];
        return 1;
    }
    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false { return 1; }
}
