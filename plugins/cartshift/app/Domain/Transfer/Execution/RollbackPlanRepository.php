<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class RollbackPlanRepository
{
    private string $directory;

    public function __construct(string $privateDirectory)
    {
        $this->directory = PrivateTransferFile::directory($privateDirectory);
    }

    public function save(RollbackPlan $plan): string
    {
        $document = CanonicalJson::encode([
            'plan' => $plan->toArray(),
            'plan_fingerprint' => $plan->fingerprint(),
        ]) . "\n";
        return PrivateTransferFile::writeImmutable(
            $this->directory,
            sprintf('rollback-%s-%s.json', $plan->runId, substr($plan->fingerprint(), 0, 24)),
            $document,
            'rollback_plan_immutable_conflict',
        );
    }

    public function get(string $path): RollbackPlan
    {
        $canonical = $this->privateFile($path);
        $bytes = file_get_contents($canonical);
        if (!is_string($bytes)) {
            throw new \RuntimeException('rollback_plan_unreadable');
        }
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)
            || array_keys($data) !== ['plan', 'plan_fingerprint']
            || !is_array($data['plan'])
            || !is_string($data['plan_fingerprint'])) {
            throw new \RuntimeException('rollback_plan_payload_invalid');
        }
        $plan = RollbackPlan::fromArray($data['plan']);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $data['plan_fingerprint']) !== 1
            || !hash_equals($plan->fingerprint(), $data['plan_fingerprint'])) {
            throw new \RuntimeException('rollback_plan_fingerprint_mismatch');
        }
        $canonicalBytes = CanonicalJson::encode([
            'plan' => $plan->toArray(),
            'plan_fingerprint' => $plan->fingerprint(),
        ]) . "\n";
        if (!hash_equals($canonicalBytes, $bytes)) {
            throw new \RuntimeException('rollback_plan_not_canonical');
        }
        return $plan;
    }

    private function privateFile(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0) {
            throw new \InvalidArgumentException('Rollback plan must be an absolute private non-symlink file.');
        }
        $canonical = realpath($path);
        if ($canonical === false || dirname($canonical) !== $this->directory) {
            throw new \InvalidArgumentException('Rollback plan must belong to the approved private directory.');
        }
        return $canonical;
    }
}
