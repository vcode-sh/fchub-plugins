<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\SameSite\GuidedDecisionSetAcceptor;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedDecisionSetAcceptorTest extends PluginTestCase
{
    /** @var list<string> */
    private array $roots = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTree($root);
        }

        parent::tearDown();
    }

    public function testAcceptanceIsCanonicalPrivateAndIdempotent(): void
    {
        $path = $this->path();
        $proposal = $this->proposal('shop-alpha:product:10');
        $acceptor = new GuidedDecisionSetAcceptor();

        $first = $acceptor->accept($proposal, $path);
        $before = file_get_contents($path);
        $retried = $acceptor->accept($proposal, $path);

        self::assertSame(['accepted' => 1], $first);
        self::assertSame($first, $retried);
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(
            TransferDecisionSet::fromArray($proposal['decision_set']['decisions'])->canonicalJson(),
            $before,
        );
        self::assertSame($before, file_get_contents($path));
        self::assertSame([], glob($path . '.tmp-*') ?: []);
    }

    public function testChangedDecisionSetWinsTheCasWithoutBeingTouched(): void
    {
        $path = $this->path();
        $newer = TransferDecisionSet::fromArray([$this->decision('shop-alpha:product:11')]);
        file_put_contents($path, $newer->canonicalJson());
        chmod($path, 0600);
        $before = file_get_contents($path);

        try {
            (new GuidedDecisionSetAcceptor())->accept($this->proposal('shop-alpha:product:10'), $path);
            self::fail('A proposal replaced a decision set changed after review.');
        } catch (\RuntimeException $failure) {
            self::assertSame('guided_decision_set_changed', $failure->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame([], glob($path . '.tmp-*') ?: []);
    }

    private function path(): string
    {
        $root = sys_get_temp_dir() . '/cartshift-guided-acceptor-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $this->roots[] = $root;

        return $root . '/decisions.json';
    }

    /** @return array<string,mixed> */
    private function proposal(string $identity): array
    {
        return [
            'status' => 'owner_review_required',
            'blockers' => [],
            'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
            'decision_set' => ['decisions' => [$this->decision($identity)]],
        ];
    }

    /** @return array<string,mixed> */
    private function decision(string $identity): array
    {
        return [
            'identity' => $identity,
            'scope' => 'record',
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => str_repeat('a', 64),
            'operator' => 'wp-user:1',
            'reason' => 'Owner approved the reviewed product.',
            'decided_at' => '2026-08-12T11:00:00Z',
        ];
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
