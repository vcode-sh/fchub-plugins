<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverTargetGateway;
use CartShift\Domain\Transfer\Subscription\SubscriptionTargetCutover;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionTargetCutoverTest extends PluginTestCase
{
    private string $root;
    protected function setUp(): void { parent::setUp(); $this->root = sys_get_temp_dir() . '/cartshift-target-cutover-' . bin2hex(random_bytes(8)); mkdir($this->root, 0700); }
    protected function tearDown(): void { foreach (glob($this->root . '/*') ?: [] as $file) unlink($file); rmdir($this->root); parent::tearDown(); }

    public function testMarkedActivationResumesWithoutWritingAnAlreadyActiveTarget(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $repository->create(new SubscriptionCutoverEvidence(
            'run-task-22', 'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
            str_repeat('d', 64), str_repeat('e', 64),
            'rehearsal', SubscriptionCutoverEvidence::SOURCE_RELEASED, [[
                'source_identity' => 'shop-alpha:subscription:31', 'source_fingerprint' => str_repeat('1', 64),
                'target_id' => 9031, 'staged_target_fingerprint' => str_repeat('2', 64),
                'source_release_required' => true, 'intended_status' => 'active',
                'release_state' => 'released', 'activation_state' => 'marked',
                'pre_renewal_fingerprint' => str_repeat('3', 64),
                'pre_release_comparison_fingerprint' => str_repeat('4', 64),
                'previous_requires_manual_renewal' => false,
                'post_source_fingerprint' => str_repeat('5', 64),
                'post_renewal_fingerprint' => str_repeat('3', 64),
            ]], '2026-08-10T12:00:00Z',
        ));
        $gateway = new TargetCutoverGateway('active');
        $result = (new SubscriptionTargetCutover($repository, $gateway))->activateAndReconcile(
            'run-task-22', '2026-08-10T12:10:00Z',
        );

        self::assertSame(SubscriptionCutoverEvidence::RECONCILED, $result->state);
        self::assertSame('reconciled', $result->entries[0]['activation_state']);
        self::assertSame(0, $gateway->updates);
        self::assertGreaterThanOrEqual(2, $gateway->reads);
    }

    public function testPartialActivationFailureResumesWithoutRewritingTheFirstSubscription(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $entries = [];
        foreach ([31 => 9031, 32 => 9032] as $sourceId => $targetId) {
            $entries[] = [
                'source_identity' => 'shop-alpha:subscription:' . $sourceId,
                'source_fingerprint' => hash('sha256', 'source-' . $sourceId),
                'target_id' => $targetId,
                'staged_target_fingerprint' => hash('sha256', 'target-' . $targetId),
                'source_release_required' => true,
                'intended_status' => 'active',
                'release_state' => 'released',
                'activation_state' => 'paused',
                'pre_renewal_fingerprint' => str_repeat('3', 64),
                'pre_release_comparison_fingerprint' => str_repeat('4', 64),
                'previous_requires_manual_renewal' => false,
                'post_source_fingerprint' => str_repeat('5', 64),
                'post_renewal_fingerprint' => str_repeat('3', 64),
            ];
        }
        $repository->create(new SubscriptionCutoverEvidence(
            'run-task-22', 'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
            str_repeat('d', 64), str_repeat('e', 64),
            'rehearsal', SubscriptionCutoverEvidence::SOURCE_RELEASED, $entries, '2026-08-10T12:00:00Z',
        ));
        $gateway = new MultiTargetCutoverGateway([9031 => 'paused', 9032 => 'paused']);
        $gateway->failTargetId = 9032;
        $cutover = new SubscriptionTargetCutover($repository, $gateway);

        try {
            $cutover->activateAndReconcile('run-task-22', '2026-08-10T12:10:00Z');
            self::fail('The injected activation failure was not observed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected_target_activation_failure', $exception->getMessage());
        }
        self::assertSame('activated', $repository->get('run-task-22')->entries[0]['activation_state']);

        $gateway->failTargetId = null;
        $result = $cutover->activateAndReconcile('run-task-22', '2026-08-10T12:11:00Z');
        self::assertSame(SubscriptionCutoverEvidence::RECONCILED, $result->state);
        self::assertSame(1, $gateway->updates[9031] ?? 0, 'Retry rewrote a subscription already activated and evidenced.');
        self::assertSame(1, $gateway->updates[9032] ?? 0);
    }
}

final class TargetCutoverGateway implements SubscriptionCutoverTargetGateway
{
    public int $updates = 0;
    public int $reads = 0;
    public function __construct(public string $status) {}
    public function create(array $row): int { throw new \LogicException(); }
    public function exists(int $subscriptionId): bool { return true; }
    public function snapshot(int $subscriptionId): array { ++$this->reads; return ['subscription' => ['id' => $subscriptionId, 'status' => $this->status, 'updated_at' => 'volatile'], 'transaction_links' => [], 'meta' => []]; }
    public function linkTransaction(int $transactionId, int $subscriptionId, string $orderType): void { throw new \LogicException(); }
    public function writeCorrection(int $subscriptionId, string $key, int $value): void { throw new \LogicException(); }
    public function activateStatus(int $subscriptionId, string $expectedStatus, string $intendedStatus): void { ++$this->updates; $this->status = $intendedStatus; }
}

final class MultiTargetCutoverGateway implements SubscriptionCutoverTargetGateway
{
    /** @var array<int,string> */
    public array $statuses;
    /** @var array<int,int> */
    public array $updates = [];
    public ?int $failTargetId = null;

    /** @param array<int,string> $statuses */
    public function __construct(array $statuses) { $this->statuses = $statuses; }
    public function create(array $row): int { throw new \LogicException(); }
    public function exists(int $subscriptionId): bool { return isset($this->statuses[$subscriptionId]); }
    public function snapshot(int $subscriptionId): array
    {
        return ['subscription' => ['id' => $subscriptionId, 'status' => $this->statuses[$subscriptionId] ?? null, 'updated_at' => 'volatile'], 'transaction_links' => [], 'meta' => []];
    }
    public function linkTransaction(int $transactionId, int $subscriptionId, string $orderType): void { throw new \LogicException(); }
    public function writeCorrection(int $subscriptionId, string $key, int $value): void { throw new \LogicException(); }
    public function activateStatus(int $subscriptionId, string $expectedStatus, string $intendedStatus): void
    {
        if ($subscriptionId === $this->failTargetId) throw new \RuntimeException('injected_target_activation_failure');
        if (($this->statuses[$subscriptionId] ?? null) !== $expectedStatus) throw new \RuntimeException('unexpected_target_status');
        $this->updates[$subscriptionId] = ($this->updates[$subscriptionId] ?? 0) + 1;
        $this->statuses[$subscriptionId] = $intendedStatus;
    }
}
